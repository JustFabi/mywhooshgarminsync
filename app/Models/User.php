<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A plain model for the single implicit local user (see App\Support\LocalUser).
 * Not an Authenticatable — there is no login, registration or verification.
 */
class User extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'selected_device_id',
    ];

    public function myWhoosh(): HasOne
    {
        return $this->hasOne(MyWhooshAccount::class);
    }

    public function garmin(): HasOne
    {
        return $this->hasOne(GarminAccount::class);
    }

    public function selectedDevice(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'selected_device_id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class);
    }
}
