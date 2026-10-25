<?php

namespace App\Services\Garmin;

/**
 * Short-lived OAuth2 Bearer token used for Garmin Connect API access.
 */
final class OAuth2Token
{
    public function __construct(
        public string $scope = '',
        public string $jti = '',
        public string $tokenType = '',
        public string $accessToken = '',
        public string $refreshToken = '',
        public int $expiresIn = 0,
        public int $expiresAt = 0,
        public int $refreshTokenExpiresIn = 0,
        public int $refreshTokenExpiresAt = 0,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            scope: (string) ($data['scope'] ?? ''),
            jti: (string) ($data['jti'] ?? ''),
            tokenType: (string) ($data['token_type'] ?? ''),
            accessToken: (string) ($data['access_token'] ?? ''),
            refreshToken: (string) ($data['refresh_token'] ?? ''),
            expiresIn: (int) ($data['expires_in'] ?? 0),
            expiresAt: (int) ($data['expires_at'] ?? 0),
            refreshTokenExpiresIn: (int) ($data['refresh_token_expires_in'] ?? 0),
            refreshTokenExpiresAt: (int) ($data['refresh_token_expires_at'] ?? 0),
        );
    }

    public function toArray(): array
    {
        return [
            'scope' => $this->scope,
            'jti' => $this->jti,
            'token_type' => $this->tokenType,
            'access_token' => $this->accessToken,
            'refresh_token' => $this->refreshToken,
            'expires_in' => $this->expiresIn,
            'expires_at' => $this->expiresAt,
            'refresh_token_expires_in' => $this->refreshTokenExpiresIn,
            'refresh_token_expires_at' => $this->refreshTokenExpiresAt,
        ];
    }

    public function isExpired(): bool
    {
        return $this->expiresAt < time();
    }

    public function isRefreshExpired(): bool
    {
        return $this->refreshTokenExpiresAt < time();
    }

    public function bearer(): string
    {
        return 'Bearer '.$this->accessToken;
    }
}
