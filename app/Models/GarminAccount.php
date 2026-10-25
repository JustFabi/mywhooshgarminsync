<?php

namespace App\Models;

use App\Services\Garmin\OAuth1Token;
use App\Services\Garmin\OAuth2Token;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GarminAccount extends Model
{
    protected $fillable = [
        'user_id',
        'email',
        'password',
        'oauth1_token',
        'oauth2_token',
        'oauth2_expires_at',
        'connected_at',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'oauth1_token' => 'encrypted:array',
            'oauth2_token' => 'encrypted:array',
            'oauth2_expires_at' => 'datetime',
            'connected_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function oauth1(): ?OAuth1Token
    {
        $data = $this->oauth1_token;

        return is_array($data) ? OAuth1Token::fromArray($data) : null;
    }

    public function oauth2(): ?OAuth2Token
    {
        $data = $this->oauth2_token;

        return is_array($data) ? OAuth2Token::fromArray($data) : null;
    }

    public function setOAuth2(OAuth2Token $token): void
    {
        $this->oauth2_token = $token->toArray();
        $this->oauth2_expires_at = date('c', $token->expiresAt);
    }

    /** Whether automatic re-login is possible when the OAuth1 token expires. */
    public function canAutoRelogin(): bool
    {
        return (bool) $this->password;
    }

    /** Domain of the connected Garmin account (defaults to garmin.com). */
    public function domain(): string
    {
        return $this->oauth1()->domain ?: 'garmin.com';
    }

    protected function password(): Attribute
    {
        return Attribute::set(fn ($value) => $value === '' ? null : $value);
    }
}
