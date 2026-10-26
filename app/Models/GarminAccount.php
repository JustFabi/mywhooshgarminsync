<?php

namespace App\Models;

use App\Services\Garmin\DiToken;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GarminAccount extends Model
{
    protected $fillable = [
        'user_id',
        'email',
        'password',
        'di_token',
        'di_expires_at',
        'connected_at',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'di_token' => 'encrypted:array',
            'di_expires_at' => 'datetime',
            'connected_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function diToken(): ?DiToken
    {
        $data = $this->di_token;

        return is_array($data) ? DiToken::fromArray($data) : null;
    }

    public function setDiToken(DiToken $token): void
    {
        $this->di_token = $token->toArray();
        $this->di_expires_at = date('c', $token->expiresAt);
    }

    /** A row without usable tokens is not "connected" (e.g. a half-created row). */
    public function hasDiToken(): bool
    {
        $token = $this->diToken();

        return $token !== null && $token->isUsable();
    }

    /** Whether automatic re-login is possible when the tokens expire. */
    public function canAutoRelogin(): bool
    {
        return (bool) $this->password;
    }

    /** Domain of the connected Garmin account. */
    public function domain(): string
    {
        return 'garmin.com';
    }
}
