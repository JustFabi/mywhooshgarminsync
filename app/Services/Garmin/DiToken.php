<?php

namespace App\Services\Garmin;

/**
 * Garmin "DI" OAuth2 Bearer token — the replacement for the OAuth1/OAuth2
 * mobile-auth flow Garmin retired in March 2026. Obtained by exchanging an
 * SSO CAS service ticket at diauth.garmin.com; refreshed with the long-lived
 * refresh token.
 */
final class DiToken
{
    public function __construct(
        public readonly string $accessToken = '',
        public readonly string $refreshToken = '',
        public readonly string $clientId = '',
        public readonly int $expiresAt = 0,
    ) {}

    /**
     * @param  array{access_token?: mixed, refresh_token?: mixed, client_id?: mixed, expires_at?: mixed}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            accessToken: (string) ($data['access_token'] ?? ''),
            refreshToken: (string) ($data['refresh_token'] ?? ''),
            clientId: (string) ($data['client_id'] ?? ''),
            expiresAt: (int) ($data['expires_at'] ?? 0),
        );
    }

    /** @return array{access_token: string, refresh_token: string, client_id: string, expires_at: int} */
    public function toArray(): array
    {
        return [
            'access_token' => $this->accessToken,
            'refresh_token' => $this->refreshToken,
            'client_id' => $this->clientId,
            'expires_at' => $this->expiresAt,
        ];
    }

    /** Access + refresh token present. */
    public function isUsable(): bool
    {
        return $this->accessToken !== '' && $this->refreshToken !== '';
    }

    /**
     * True when the access token expires within the buffer (default 15 min,
     * as python-garminconnect uses) and should be refreshed before use.
     */
    public function isExpiringWithin(int $seconds = 900): bool
    {
        if ($this->expiresAt === 0) {
            return false;
        }

        return time() > $this->expiresAt - $seconds;
    }

    /**
     * Decode the `exp` claim of a Garmin JWT without verifying the signature —
     * Garmin signs server-side; the client only reads expiry metadata.
     */
    public static function expiresAtFromJwt(string $jwt): int
    {
        $parts = explode('.', $jwt);
        if (count($parts) < 2) {
            return 0;
        }

        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
        if (! is_array($payload) || ! isset($payload['exp']) || ! is_numeric($payload['exp'])) {
            return 0;
        }

        return (int) $payload['exp'];
    }

    /** Client ID this JWT was issued for (falls back to ''). */
    public static function clientIdFromJwt(string $jwt): string
    {
        $parts = explode('.', $jwt);
        if (count($parts) < 2) {
            return '';
        }

        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
        if (! is_array($payload) || ! isset($payload['client_id']) || ! is_string($payload['client_id'])) {
            return '';
        }

        return $payload['client_id'];
    }
}
