<?php

namespace App\Services\Garmin;

/**
 * Minimal RFC 5849 (OAuth 1.0a) HMAC-SHA1 request signer, equivalent to
 * dghubble/oauth1 as used by the original Go implementation.
 *
 * Produces the `Authorization: OAuth ...` header; query parameters of the
 * request URL are folded into the signature base string per spec §3.4.1.3.
 */
final class OAuth1Signer
{
    public function header(
        string $method,
        string $url,
        string $consumerKey,
        string $consumerSecret,
        string $token = '',
        string $tokenSecret = '',
        ?string $nonce = null,
        ?string $timestamp = null,
    ): string {
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new GarminException('invalid URL for OAuth1 signing');
        }

        $baseUri = sprintf('%s://%s%s', $parts['scheme'], $parts['host'], $parts['path'] ?? '');

        $params = $this->queryParameters($parts['query'] ?? '');
        $params += [
            'oauth_consumer_key' => $consumerKey,
            'oauth_nonce' => $nonce ?? bin2hex(random_bytes(16)),
            'oauth_signature_method' => 'HMAC-SHA1',
            'oauth_timestamp' => $timestamp ?? (string) time(),
            'oauth_token' => $token,
            'oauth_version' => '1.0',
        ];

        $signature = $this->signature($method, $baseUri, $params, $consumerSecret, $tokenSecret);

        // The Authorization header carries only the protocol parameters;
        // ordinary query parameters are signed but not repeated here.
        $oauthParams = array_filter($params, fn (string $key) => str_starts_with($key, 'oauth_'), ARRAY_FILTER_USE_KEY);
        $oauthParams['oauth_signature'] = $signature;
        ksort($oauthParams, SORT_STRING);

        $header = 'OAuth';
        foreach ($oauthParams as $key => $value) {
            $header .= sprintf(', %s="%s"', $key, rawurlencode((string) $value));
        }

        return $header;
    }

    /**
     * @param  array<string, string>  $params  protocol + query parameters (raw values)
     */
    public function signature(
        string $method,
        string $baseUri,
        array $params,
        string $consumerSecret,
        string $tokenSecret = '',
    ): string {
        $pairs = [];
        foreach ($params as $key => $value) {
            // Never sign the pre-computed signature.
            if ($key === 'oauth_signature') {
                continue;
            }

            $pairs[] = [rawurlencode((string) $key), rawurlencode((string) $value)];
        }

        usort($pairs, fn (array $a, array $b) => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);

        $encoded = [];
        foreach ($pairs as [$key, $value]) {
            $encoded[] = "{$key}={$value}";
        }

        $baseString = implode('&', [
            strtoupper($method),
            rawurlencode($baseUri),
            rawurlencode(implode('&', $encoded)),
        ]);

        $signingKey = rawurlencode($consumerSecret).'&'.rawurlencode($tokenSecret);

        return base64_encode(hash_hmac('sha1', $baseString, $signingKey, true));
    }

    /**
     * @return array<string, string> raw (percent-decoded) query parameters
     */
    private function queryParameters(string $query): array
    {
        if ($query === '') {
            return [];
        }

        $params = [];
        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }

            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $params[rawurldecode($key)] = rawurldecode($value);
        }

        return $params;
    }
}
