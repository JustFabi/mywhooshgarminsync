<?php

namespace App\Services\Garmin;

use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Garmin login: SSO embed-widget flow → CAS service ticket → DI OAuth2 token.
 *
 * Garmin retired the OAuth1 "mobile auth" flow (preauthorized / exchange)
 * around March 2026; the replacement — as implemented by python-garminconnect
 * ≥ 0.3 — exchanges the SSO CAS service ticket for a native OAuth2 Bearer
 * token ("DI" token) at diauth.garmin.com. The service_url sent to the token
 * endpoint must match the one used during the SSO login (the embed widget).
 *
 *  1. GET  /sso/embed    → seed the cookie jar.
 *  2. GET  /sso/signin   → extract the CSRF token.
 *  3. POST /sso/signin   → credentials; expect a <title>Success</title>.
 *  4. Extract the CAS ticket (ST-...) from the response.
 *  5. POST diauth.<domain>/di-oauth2-service/oauth/token
 *     → { access_token, refresh_token }.
 */
final class GarminSsoService
{
    private const TIMEOUT = 30;

    /** Anti-WAF pause between loading the signin page and submitting credentials. */
    private const SIGNIN_DELAY_MIN_S = 3;
    private const SIGNIN_DELAY_MAX_S = 8;

    /**
     * The SSO embed widget is a browser widget — the login presents as a
     * desktop Chrome browser (matching python-garminconnect's widget strategy,
     * which uses chrome TLS + default browser headers). An app User-Agent on
     * libcurl's TLS accumulates Cloudflare reputation damage over attempts.
     */
    private const BROWSER_HEADERS = [
        'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) '
            .'AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
        'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,'
            .'image/avif,image/webp,image/apng,*/*;q=0.8,'
            .'application/signed-exchange;v=b3;q=0.7',
        'Accept-Language' => 'en-US,en;q=0.9',
        'sec-ch-ua' => '"Google Chrome";v="131", "Chromium";v="131", "Not_A Brand";v="24"',
        'sec-ch-ua-mobile' => '?0',
        'sec-ch-ua-platform' => '"macOS"',
        'Sec-Fetch-Dest' => 'document',
        'Sec-Fetch-Mode' => 'navigate',
        'Sec-Fetch-User' => '?1',
        'Upgrade-Insecure-Requests' => '1',
    ];

    /**
     * DI OAuth2 client IDs, tried in order (python-garminconnect constants).
     */
    private const DI_CLIENT_IDS = [
        'GARMIN_CONNECT_MOBILE_ANDROID_DI_2025Q2',
        'GARMIN_CONNECT_MOBILE_ANDROID_DI_2024Q4',
        'GARMIN_CONNECT_MOBILE_ANDROID_DI',
        'GARMIN_CONNECT_MOBILE_IOS_DI',
    ];

    private const DI_GRANT_TYPE = 'https://connectapi.garmin.com/di-oauth2-service/oauth/grant/service_ticket';

    private ?CookieJar $jar = null;
    private string $lastUrl = '';

    public function __construct(private readonly string $domain = 'garmin.com') {}

    /**
     * Headers identifying this client as the Garmin Connect Android app.
     * Required on DI token requests and on all connectapi calls made with a
     * DI Bearer token.
     *
     * @param  array<string, string>  $extra
     * @return array<string, string>
     */
    public static function nativeHeaders(array $extra = []): array
    {
        return array_merge([
            'User-Agent' => 'GCM-Android-5.23',
            'X-Garmin-User-Agent' => 'com.garmin.android.apps.connectmobile/5.23; ; '
                .'Google/sdk_gphone64_arm64/google; Android/33; Dalvik/2.1.0',
            'X-Garmin-Paired-App-Version' => '10861',
            'X-Garmin-Client-Platform' => 'Android',
            'X-App-Ver' => '10861',
            'X-Lang' => 'en',
            'X-GCExperience' => 'GC5',
            'Accept-Language' => 'en-US,en;q=0.9',
        ], $extra);
    }

    /**
     * Full login. Returns a DI token (access + refresh).
     *
     * Garmin's SSO intermittently rejects valid-credential submissions with a
     * re-rendered sign-in page (sometimes even HTTP 401) — observed for the
     * same credentials within minutes. Ambiguous failures are therefore
     * retried; definitive rejections (explicit error text, lockout, MFA,
     * rate limit) are not.
     */
    public function login(string $email, string $password): DiToken
    {
        $ssoBase = 'https://sso.'.$this->domain;
        $ssoEmbed = $ssoBase.'/sso/embed';

        $embedParams = self::buildQuery([
            'id' => 'gauth-widget',
            'embedWidget' => 'true',
            'gauthHost' => $ssoBase.'/sso',
        ]);

        $signinParams = self::buildQuery([
            'id' => 'gauth-widget',
            'embedWidget' => 'true',
            'gauthHost' => $ssoEmbed,
            'service' => $ssoEmbed,
            'source' => $ssoEmbed,
            'redirectAfterAccountLoginUrl' => $ssoEmbed,
            'redirectAfterAccountCreationUrl' => $ssoEmbed,
        ]);

        $signinUrl = $ssoBase.'/sso/signin?'.$signinParams;

        // 1. Seed cookies (top-level navigation).
        $this->ssoGet($ssoEmbed.'?'.$embedParams, useReferer: false, step: 'embed', fetchSite: 'none');

        $attempts = 3;

        for ($attempt = 1; ; $attempt++) {
            // 2. Fresh CSRF token per attempt.
            $body = $this->ssoGet($signinUrl, useReferer: true, step: 'sign-in page');

            if (! preg_match('/name="_csrf"\s+value="(.+?)"/s', $body, $csrf)) {
                throw new GarminAuthException('CSRF token not found in signin page');
            }

            // Anti-WAF pause: Cloudflare flags rapid GET→POST sequences as bot-like.
            sleep(random_int(self::SIGNIN_DELAY_MIN_S, self::SIGNIN_DELAY_MAX_S));

            // 3. Submit credentials. Garmin answers wrong credentials, MFA
            // challenges and lockouts as page responses (often HTTP 401) — the
            // status alone is not an error indicator, so classify the body.
            [$status, $body] = $this->ssoPost(
                $signinUrl,
                [
                    'username' => $email,
                    'password' => $password,
                    'embed' => 'true',
                    '_csrf' => $csrf[1],
                ],
            );

            // 4. Classify the result.
            if ($status === 429 || $status === 427) {
                throw new GarminAuthException(sprintf(
                    'sign-in rate limited (HTTP %d) — wait a few minutes and try again',
                    $status,
                ));
            }

            if (! preg_match('/<title>(.*?)<\/title>/s', $body, $titleMatch)) {
                throw new GarminAuthException(sprintf(
                    'sign-in response has no title (HTTP %d)', $status,
                ));
            }

            $title = trim($titleMatch[1]);
            $titleLower = mb_strtolower($title);

            if (preg_match('/bad gateway|service unavailable|cloudflare|502|503/i', $titleLower)) {
                throw new GarminAuthException(sprintf('Garmin SSO unavailable: "%s" (HTTP %d)', $title, $status));
            }

            $mfaMethod = self::widgetMfaMethod($body);

            if (Str::contains($titleLower, 'mfa') || $mfaMethod !== '') {
                throw new GarminAuthException(sprintf(
                    'MFA is required (%s) but not supported. Disable MFA on your Garmin account and try again. (title: %s)',
                    $mfaMethod !== '' ? $mfaMethod : 'unknown method',
                    $title,
                ));
            }

            if (preg_match('/locked|invalid|incorrect|account error/i', $title)) {
                throw new GarminAuthException('login failed: "'.$title.'"');
            }

            if ($title === 'Success') {
                if (! preg_match('/\?ticket=(ST-[^"&\s]+)/s', $body, $ticketMatch)) {
                    throw new GarminAuthException('ticket not found in response');
                }

                // 5. Exchange the CAS ticket for a DI OAuth2 token.
                return $this->exchangeServiceTicket($ticketMatch[1]);
            }

            // Ambiguous rejection (re-rendered sign-in page without an
            // explicit error): retry unless this was the last attempt.
            $hint = self::bodyErrorHint($body);

            if ($attempt < $attempts && $hint === '') {
                sleep(random_int(2, 4));

                continue;
            }

            throw new GarminAuthException(sprintf(
                'login failed: "%s" (HTTP %d, %d attempt%s)%s%s',
                $title,
                $status,
                $attempt,
                $attempt === 1 ? '' : 's',
                $hint !== '' ? ', Garmin says: "'.$hint.'"' : '',
                $hint === '' ? ' — likely a temporary Garmin SSO hiccup, try again' : '',
            ));
        }
    }

    /**
     * Exchange an SSO CAS service ticket for a DI OAuth2 Bearer token.
     *
     * Tries each known DI client ID in turn; the service_url must match the
     * one the SSO login used (the embed widget).
     */
    public function exchangeServiceTicket(string $ticket): DiToken
    {
        $url = sprintf('https://diauth.%s/di-oauth2-service/oauth/token', $this->domain);
        $serviceUrl = 'https://sso.'.$this->domain.'/sso/embed';
        $lastStatus = 0;

        foreach (self::DI_CLIENT_IDS as $clientId) {
            $response = Http::withOptions(['timeout' => self::TIMEOUT])
                ->withHeaders(self::nativeHeaders([
                    'Authorization' => 'Basic '.base64_encode($clientId.':'),
                    'Accept' => 'application/json,text/html;q=0.9,*/*;q=0.8',
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Cache-Control' => 'no-cache',
                ]))
                ->asForm()
                ->post($url, [
                    'client_id' => $clientId,
                    'service_ticket' => $ticket,
                    'grant_type' => self::DI_GRANT_TYPE,
                    'service_url' => $serviceUrl,
                ]);

            if ($response->status() === 429) {
                throw new GarminAuthException(
                    'DI token exchange rate limited (HTTP 429) — wait a moment and try again',
                );
            }

            if ($response->status() >= 400) {
                $lastStatus = $response->status();

                continue;
            }

            $data = $response->json();
            if (! is_array($data) || empty($data['access_token'])) {
                $lastStatus = $response->status();

                continue;
            }

            $accessToken = (string) $data['access_token'];

            return new DiToken(
                accessToken: $accessToken,
                refreshToken: (string) ($data['refresh_token'] ?? ''),
                clientId: DiToken::clientIdFromJwt($accessToken) ?: $clientId,
                expiresAt: DiToken::expiresAtFromJwt($accessToken),
            );
        }

        throw new GarminAuthException(sprintf(
            'DI token exchange failed for all client IDs (last HTTP %d)',
            $lastStatus,
        ));
    }

    /**
     * Refresh a DI token with its long-lived refresh token.
     */
    public function refreshDiToken(DiToken $token): DiToken
    {
        if ($token->refreshToken === '') {
            throw new GarminAuthException('no Garmin refresh token on file — reconnect on Connections');
        }

        $url = sprintf('https://diauth.%s/di-oauth2-service/oauth/token', $this->domain);

        $response = Http::withOptions(['timeout' => self::TIMEOUT])
            ->withHeaders(self::nativeHeaders([
                'Authorization' => 'Basic '.base64_encode($token->clientId.':'),
                'Accept' => 'application/json',
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Cache-Control' => 'no-cache',
            ]))
            ->asForm()
            ->post($url, [
                'grant_type' => 'refresh_token',
                'client_id' => $token->clientId,
                'refresh_token' => $token->refreshToken,
            ]);

        if ($response->status() >= 400) {
            throw new GarminAuthException(sprintf(
                'DI token refresh failed (HTTP %d) — reconnect on Connections',
                $response->status(),
            ));
        }

        $data = $response->json();
        if (! is_array($data) || empty($data['access_token'])) {
            throw new GarminAuthException('DI token refresh returned no access token');
        }

        $accessToken = (string) $data['access_token'];

        return new DiToken(
            accessToken: $accessToken,
            refreshToken: ((string) ($data['refresh_token'] ?? '')) ?: $token->refreshToken,
            clientId: DiToken::clientIdFromJwt($accessToken) ?: $token->clientId,
            expiresAt: DiToken::expiresAtFromJwt($accessToken),
        );
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * The widget MFA page exposes `var mfaMethod = "email|sms|..."` in
     * inline <script> tags — its <title> alone is ambiguous because the
     * plain signin page is also titled "GARMIN Authentication Application".
     */
    private static function widgetMfaMethod(string $body): string
    {
        if (preg_match('/var\s+mfaMethod\s*=\s*"([^"]*)"\s*;/', $body, $match)) {
            return (string) $match[1];
        }

        return '';
    }

    /**
     * Pull a human-readable error sentence (wrong password, lockout notice,
     * ...) out of the sign-in page HTML, if Garmin included one.
     */
    private static function bodyErrorHint(string $body): string
    {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($body)) ?? '');

        if ($text === '') {
            return '';
        }

        if (preg_match('/[^.!?\n]{0,80}(?:incorrect|invalid|wrong|locked|too many|unable|failed)[^!?\n]{0,120}[.!?]?/i', $text, $match)) {
            return mb_substr(trim($match[0]), 0, 200);
        }

        return '';
    }

    private function ssoGet(string $url, bool $useReferer, string $step = 'SSO', string $fetchSite = 'same-origin'): string
    {
        $response = Http::withOptions([
            'cookies' => $this->jar(),
            'timeout' => self::TIMEOUT,
            'allow_redirects' => ['max' => 10, 'track_redirects' => true],
        ])
            ->withHeaders($this->ssoHeaders($useReferer, $fetchSite))
            ->get($url);

        $this->rememberLastUrl($url, $response);

        if ($response->status() >= 400) {
            throw new GarminAuthException(sprintf(
                '%s request failed (HTTP %d): %s',
                $step,
                $response->status(),
                mb_substr($response->body(), 0, 200),
            ));
        }

        return $response->body();
    }

    /**
     * @return array{0: int, 1: string} [status, body] — never throws on HTTP
     * error statuses: wrong credentials / MFA / lockouts arrive as pages.
     */
    private function ssoPost(string $url, array $form): array
    {
        $response = Http::withOptions([
            'cookies' => $this->jar(),
            'timeout' => self::TIMEOUT,
            'allow_redirects' => ['max' => 10, 'track_redirects' => true],
        ])
            ->withHeaders($this->ssoHeaders(true))
            ->asForm()
            ->post($url, $form);

        $this->rememberLastUrl($url, $response);

        return [$response->status(), $response->body()];
    }

    private function ssoHeaders(bool $useReferer, string $fetchSite = 'same-origin'): array
    {
        $headers = self::BROWSER_HEADERS;
        $headers['Sec-Fetch-Site'] = $fetchSite;

        if ($useReferer && $this->lastUrl !== '') {
            $headers['Referer'] = $this->lastUrl;
        }

        return $headers;
    }

    private function jar(): CookieJar
    {
        return $this->jar ??= new CookieJar();
    }

    private function rememberLastUrl(string $requested, \Illuminate\Http\Client\Response $response): void
    {
        // After redirect-following, the last URL in the chain is the "final"
        // URL — replicate Go's resp.Request.URL semantics.
        $history = $response->header('X-Guzzle-Redirect-History');
        $last = is_array($history) && $history !== [] ? (string) $history[count($history) - 1] : '';
        if ($last !== '') {
            $this->lastUrl = $last;

            return;
        }

        $this->lastUrl = $requested;
    }

    /**
     * http_build_query with RFC 3986 encoding, keys sorted (url.Values.Encode).
     */
    private static function buildQuery(array $params): string
    {
        ksort($params, SORT_STRING);

        return http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }
}
