<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Device extends Model
{
    protected $fillable = [
        'name',
        'manufacturer',
        'product_id',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'manufacturer' => 'integer',
            'product_id' => 'integer',
            'is_default' => 'boolean',
        ];
    }

    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class);
    }

    public static function default(): ?self
    {
        return static::query()->where('is_default', true)->first()
            ?? static::query()->orderBy('product_id')->first();
    }
}
