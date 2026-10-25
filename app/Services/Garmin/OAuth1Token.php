<?php

namespace App\Services\Garmin;

/**
 * OAuth1 credentials obtained through Garmin SSO. Valid for ~1 year and used
 * to mint fresh OAuth2 tokens.
 */
final class OAuth1Token
{
    public function __construct(
        public string $oauthToken = '',
        public string $oauthTokenSecret = '',
        public string $mfaToken = '',
        public string $mfaExpiration = '',
        public string $domain = 'garmin.com',
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            oauthToken: (string) ($data['oauth_token'] ?? ''),
            oauthTokenSecret: (string) ($data['oauth_token_secret'] ?? ''),
            mfaToken: (string) ($data['mfa_token'] ?? ''),
            mfaExpiration: (string) ($data['mfa_expiration_timestamp'] ?? ''),
            domain: (string) ($data['domain'] ?? 'garmin.com'),
        );
    }

    public function toArray(): array
    {
        return [
            'oauth_token' => $this->oauthToken,
            'oauth_token_secret' => $this->oauthTokenSecret,
            'mfa_token' => $this->mfaToken,
            'mfa_expiration_timestamp' => $this->mfaExpiration,
            'domain' => $this->domain,
        ];
    }
}
