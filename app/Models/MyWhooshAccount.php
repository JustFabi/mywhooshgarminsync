<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MyWhooshAccount extends Model
{
    protected $table = 'mywhoosh_accounts';

    protected $fillable = [
        'user_id',
        'email',
        'password',
        'access_token',
        'whoosh_id',
        'connected_at',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'access_token' => 'encrypted',
            'connected_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class);
    }

    public function hasAccessToken(): bool
    {
        return (bool) $this->access_token;
    }

    /** Whether automatic re-login is possible when the token is rejected. */
    public function canAutoRelogin(): bool
    {
        return (bool) $this->password;
    }

    protected function password(): Attribute
    {
        return Attribute::set(fn ($value) => $value === '' ? null : $value);
    }
}
