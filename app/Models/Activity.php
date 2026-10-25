<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Activity extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SYNCED = 'synced';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'user_id',
        'device_id',
        'mywhoosh_id',
        'activity_file_id',
        'title',
        'start_at',
        'distance_km',
        'duration_seconds',
        'watt',
        'heartrate',
        'elevation_m',
        'watt_per_kg',
        'sport_type',
        'route_name',
        'status',
        'error_message',
        'synced_at',
        'fit_fixed_path',
    ];

    protected function casts(): array
    {
        return [
            'start_at' => 'datetime',
            'distance_km' => 'float',
            'duration_seconds' => 'integer',
            'watt' => 'float',
            'heartrate' => 'integer',
            'elevation_m' => 'float',
            'watt_per_kg' => 'float',
            'synced_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isSynced(): bool
    {
        return $this->status === self::STATUS_SYNCED;
    }

    /** Formatted duration "1h31m54s" from seconds. */
    protected function durationAttribute(): Attribute
    {
        return Attribute::get(function ($value, array $attributes): ?string {
            $seconds = $attributes['duration_seconds'] ?? null;
            if ($seconds === null) {
                return null;
            }

            $h = intdiv($seconds, 3600);
            $m = intdiv($seconds % 3600, 60);
            $s = $seconds % 60;

            return $h > 0
                ? sprintf('%dh%dm%ds', $h, $m, $s)
                : sprintf('%dm%ds', $m, $s);
        });
    }
}
