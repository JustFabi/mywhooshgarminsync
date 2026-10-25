<?php

namespace App\Services\Garmin;

use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Garmin SSO login flow, ported one-to-one from mywoosh2garmin's garmin/sso.go:
 *
 *  1. Fetch the OAuth consumer credentials from Garmin's public S3 file.
 *  2. GET  /sso/embed            → seed the cookie jar.
 *  3. GET  /sso/signin           → extract the CSRF token.
 *  4. POST /sso/signin           → credentials; expect a <title>Success</title>.
 *  5. Extract the `ticket` from the response.
 *  6. GET  connectapi/oauth-service/oauth/preauthorized (consumer-signed OAuth1)
 *     → OAuth1 token.
 *  7. POST connectapi/oauth-service/oauth/exchange/user/2.0 (token-signed OAuth1)
 *     → OAuth2 Bearer token.
 */
final class GarminSsoService
{
    private const SSO_USER_AGENT = 'com.garmin.android.apps.connectmobile';
    private const CONSUMER_URL = 'https://thegarth.s3.amazonaws.com/oauth_consumer.json';
    private const TIMEOUT = 30;

    private ?CookieJar $jar = null;
    private string $lastUrl = '';

    public function __construct(private readonly string $domain = 'garmin.com') {}

    /**
     * Full login. Returns OAuth1 + OAuth2 tokens.
     */
    public function login(string $email, string $password): array
    {
        $consumer = self::consumer();

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

        // 2. Seed cookies.
        $this->ssoGet($ssoEmbed.'?'.$embedParams, useReferer: false);

        // 3. Signin page: CSRF token.
        $body = $this->ssoGet($ssoBase.'/sso/signin?'.$signinParams, useReferer: true);

        if (! preg_match('/name="_csrf"\s+value="(.+?)"/s', $body, $csrf)) {
            throw new GarminAuthException('CSRF token not found in signin page');
        }

        // 4. Submit credentials.
        $body = $this->ssoPost(
            $ssoBase.'/sso/signin?'.$signinParams,
            [
                'username' => $email,
                'password' => $password,
                'embed' => 'true',
                '_csrf' => $csrf[1],
            ],
        );

        // 5. Verify result.
        if (! preg_match('/<title>(.*?)<\/title>/s', $body, $titleMatch)) {
            throw new GarminAuthException('no title in response — login may have failed');
        }

        $title = trim($titleMatch[1]);

        if (Str::contains($title, 'MFA')) {
            throw new GarminAuthException(
                'MFA is required but not supported for the web app. Disable MFA temporarily on your Garmin account. (title: '.$title.')'
            );
        }

        if ($title !== 'Success') {
            throw new GarminAuthException('login failed: "'.$title.'" (check credentials)');
        }

        if (! preg_match('/embed\?ticket=([^"]+)"/s', $body, $ticketMatch)) {
            throw new GarminAuthException('ticket not found in response');
        }

        $ticket = $ticketMatch[1];

        // 6. OAuth1 token.
        $oauth1 = $this->getOAuth1Token($consumer, $ticket);

        // 7. OAuth2 exchange.
        $oauth2 = $this->exchange($oauth1);

        return [$oauth1, $oauth2];
    }

    /**
     * Exchange an OAuth1 token for an OAuth2 token (also used to refresh).
     */
    public function exchange(OAuth1Token $oauth1): OAuth2Token
    {
        $url = sprintf('https://connectapi.%s/oauth-service/oauth/exchange/user/2.0', $oauth1->domain ?: $this->domain);
        $authorization = (new OAuth1Signer())->header(
            'POST',
            $url,
            self::consumer()['consumer_key'],
            self::consumer()['consumer_secret'],
            $oauth1->oauthToken,
            $oauth1->oauthTokenSecret,
        );

        $response = Http::withOptions(['timeout' => self::TIMEOUT])
            ->withHeaders([
                'User-Agent' => self::SSO_USER_AGENT,
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Authorization' => $authorization,
            ])
            ->send('POST', $url, [
                'body' => $oauth1->mfaToken !== '' ? 'mfa_token='.rawurlencode($oauth1->mfaToken) : '',
            ]);

        if ($response->status() >= 400) {
            throw new GarminAuthException(sprintf(
                'oauth2 exchange failed (HTTP %d): %s',
                $response->status(),
                mb_substr($response->body(), 0, 300),
            ));
        }

        $data = $response->json() ?? [];
        $now = time();

        return new OAuth2Token(
            scope: (string) ($data['scope'] ?? ''),
            jti: (string) ($data['jti'] ?? ''),
            tokenType: (string) ($data['token_type'] ?? ''),
            accessToken: (string) ($data['access_token'] ?? ''),
            refreshToken: (string) ($data['refresh_token'] ?? ''),
            expiresIn: (int) ($data['expires_in'] ?? 0),
            expiresAt: $now + (int) ($data['expires_in'] ?? 0),
            refreshTokenExpiresIn: (int) ($data['refresh_token_expires_in'] ?? 0),
            refreshTokenExpiresAt: $now + (int) ($data['refresh_token_expires_in'] ?? 0),
        );
    }

    /**
     * OAuth consumer credentials (public Garmin-published file).
     *
     * @return array{consumer_key: string, consumer_secret: string}
     */
    public static function consumer(): array
    {
        static $memo = null;

        if ($memo !== null) {
            return $memo;
        }

        if (config('services.garmin.consumer_key') && config('services.garmin.consumer_secret')) {
            return $memo = [
                'consumer_key' => (string) config('services.garmin.consumer_key'),
                'consumer_secret' => (string) config('services.garmin.consumer_secret'),
            ];
        }

        $response = Http::timeout(self::TIMEOUT)->get(self::CONSUMER_URL);
        if ($response->status() !== 200) {
            throw new GarminAuthException('consumer fetch failed (HTTP '.$response->status().')');
        }

        $data = $response->json();
        if (! is_array($data) || empty($data['consumer_key']) || empty($data['consumer_secret'])) {
            throw new GarminAuthException('consumer credentials file has unexpected contents');
        }

        return $memo = [
            'consumer_key' => (string) $data['consumer_key'],
            'consumer_secret' => (string) $data['consumer_secret'],
        ];
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * The preauthorized call is consumer-signed OAuth1, not session cookies.
     */
    private function getOAuth1Token(array $consumer, string $ticket): OAuth1Token
    {
        $loginUrl = 'https://sso.'.$this->domain.'/sso/embed';
        $url = sprintf(
            'https://connectapi.%s/oauth-service/oauth/preauthorized?ticket=%s&login-url=%s&accepts-mfa-tokens=true',
            $this->domain,
            rawurlencode($ticket),
            rawurlencode($loginUrl),
        );

        $authorization = (new OAuth1Signer())->header(
            'GET',
            $url,
            $consumer['consumer_key'],
            $consumer['consumer_secret'],
        );

        $response = Http::withOptions(['timeout' => self::TIMEOUT])
            ->withHeaders([
                'User-Agent' => self::SSO_USER_AGENT,
                'Authorization' => $authorization,
            ])
            ->get($url);

        if ($response->status() !== 200) {
            throw new GarminAuthException(sprintf(
                'preauthorized failed (HTTP %d): %s',
                $response->status(),
                mb_substr($response->body(), 0, 300),
            ));
        }

        $values = [];
        parse_str($response->body(), $values);

        if (empty($values['oauth_token']) || empty($values['oauth_token_secret'])) {
            throw new GarminAuthException('preauthorized response missing oauth_token');
        }

        return new OAuth1Token(
            oauthToken: (string) $values['oauth_token'],
            oauthTokenSecret: (string) $values['oauth_token_secret'],
            mfaToken: (string) ($values['mfa_token'] ?? ''),
            mfaExpiration: (string) ($values['mfa_expiration_timestamp'] ?? ''),
            domain: $this->domain,
        );
    }

    private function ssoGet(string $url, bool $useReferer): string
    {
        $response = Http::withOptions([
            'cookies' => $this->jar(),
            'timeout' => self::TIMEOUT,
            'allow_redirects' => ['max' => 10, 'track_redirects' => true],
        ])
            ->withHeaders($this->ssoHeaders($useReferer))
            ->get($url);

        $this->rememberLastUrl($url, $response);

        if ($response->status() >= 400) {
            throw new GarminAuthException(sprintf(
                'SSO request failed (HTTP %d): %s',
                $response->status(),
                mb_substr($response->body(), 0, 300),
            ));
        }

        return $response->body();
    }

    private function ssoPost(string $url, array $form): string
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

        if ($response->status() >= 400) {
            throw new GarminAuthException(sprintf(
                'SSO request failed (HTTP %d): %s',
                $response->status(),
                mb_substr($response->body(), 0, 300),
            ));
        }

        return $response->body();
    }

    private function ssoHeaders(bool $useReferer): array
    {
        $headers = ['User-Agent' => self::SSO_USER_AGENT];
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
