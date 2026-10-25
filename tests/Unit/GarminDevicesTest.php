<?php

use App\Support\GarminDevices;
use App\Models\Device;

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds the watch catalog with exactly one default watch', function () {
    GarminDevices::seed();

    expect(Device::query()->count())->toBe(count(GarminDevices::catalog()))
        ->and(Device::query()->where('is_default', true)->count())->toBe(1)
        ->and(Device::query()->where('is_default', true)->first())
        ->name->toBe('Garmin Fenix 6S Pro')
        ->product_id->toBe(3288);
});

it('is idempotent when seeded twice', function () {
    GarminDevices::seed();
    GarminDevices::seed();

    expect(Device::query()->count())->toBe(count(GarminDevices::catalog()));
});

it('keeps spoofing constants the Go tool used', function () {
    // product 3288 = Garmin Fenix 6S Pro, the hardcoded value of the
    // original mywoosh2garmin implementation.
    $default = collect(GarminDevices::catalog())->firstWhere('product_id', 3288);

    expect($default)
        ->not->toBeNull()
        ->and($default['name'])->toBe('Garmin Fenix 6S Pro');
});
