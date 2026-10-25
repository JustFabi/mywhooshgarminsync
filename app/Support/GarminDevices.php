<?php

namespace App\Support;

use App\Models\Device;

/**
 * Curated catalog of real Garmin device product IDs, used to spoof the
 * recording device on MyWhoosh FIT files. Product IDs are uint16 values for
 * FIT field `garmin_product`, extracted from Garmin's official profile
 * enumeration (the same source the Go tool used).
 */
final class GarminDevices
{
    /** @var array<int, array{name: string, product_id: int}> */
    private const CATALOG = [
        // Fenix family
        ['name' => 'Garmin Fenix 6S Pro', 'product_id' => 3288],
        ['name' => 'Garmin Fenix 6', 'product_id' => 3290],
        ['name' => 'Garmin Fenix 6X Pro', 'product_id' => 3291],
        ['name' => 'Garmin Fenix 7', 'product_id' => 3906],
        ['name' => 'Garmin Fenix 7S', 'product_id' => 3905],
        ['name' => 'Garmin Fenix 7X', 'product_id' => 3907],
        ['name' => 'Garmin Fenix 7 Pro', 'product_id' => 4375],
        ['name' => 'Garmin Fenix 7X Pro', 'product_id' => 4376],
        ['name' => 'Garmin Fenix 8', 'product_id' => 4536],
        ['name' => 'Garmin Fenix 8 Solar', 'product_id' => 4532],
        ['name' => 'Garmin Fenix 8 Pro', 'product_id' => 4631],
        // Forerunner family
        ['name' => 'Garmin Forerunner 245', 'product_id' => 3076],
        ['name' => 'Garmin Forerunner 255', 'product_id' => 3992],
        ['name' => 'Garmin Forerunner 265', 'product_id' => 4257],
        ['name' => 'Garmin Forerunner 935', 'product_id' => 2691],
        ['name' => 'Garmin Forerunner 945', 'product_id' => 3113],
        ['name' => 'Garmin Forerunner 955', 'product_id' => 4024],
        ['name' => 'Garmin Forerunner 965', 'product_id' => 4315],
        // Edge cycling computers
        ['name' => 'Garmin Edge 530', 'product_id' => 3121],
        ['name' => 'Garmin Edge 830', 'product_id' => 3122],
        ['name' => 'Garmin Edge 540', 'product_id' => 4061],
        ['name' => 'Garmin Edge 840', 'product_id' => 4062],
        ['name' => 'Garmin Edge 1030', 'product_id' => 2713],
        ['name' => 'Garmin Edge 1030 Plus', 'product_id' => 3570],
        ['name' => 'Garmin Edge 1050', 'product_id' => 4440],
        // Other wearables
        ['name' => 'Garmin Epix (Gen 2)', 'product_id' => 3943],
        ['name' => 'Garmin Enduro 2', 'product_id' => 4341],
        ['name' => 'Garmin Instinct 2', 'product_id' => 3888],
        ['name' => 'Garmin Instinct 2X', 'product_id' => 4394],
        ['name' => 'Garmin Venu 2', 'product_id' => 3703],
        ['name' => 'Garmin Venu 2 Plus', 'product_id' => 3851],
        ['name' => 'Garmin Venu 3', 'product_id' => 4260],
        ['name' => 'Garmin Vivoactive 4', 'product_id' => 3225],
        ['name' => 'Garmin Vivoactive 5', 'product_id' => 4426],
        ['name' => 'Garmin Vivoactive 6', 'product_id' => 4625],
        // Trainers
        ['name' => 'Tacx Neo 2T', 'product_id' => 4267],
    ];

    public const DEFAULT_PRODUCT_ID = 3288; // Garmin Fenix 6S Pro

    /**
     * @return array<int, array{name: string, product_id: int}>
     */
    public static function catalog(): array
    {
        return self::CATALOG;
    }

    /**
     * @return array{name: string, product_id: int}
     */
    public static function default(): array
    {
        return ['name' => 'Garmin Fenix 6S Pro', 'product_id' => self::DEFAULT_PRODUCT_ID];
    }

    /**
     * Synchronize the catalog into the devices table. Rows that exist are
     * left untouched; missing rows are created.
     */
    public static function seed(): void
    {
        foreach (self::CATALOG as $entry) {
            Device::query()->firstOrCreate(
                ['product_id' => $entry['product_id']],
                ['name' => $entry['name'], 'is_default' => $entry['product_id'] === self::DEFAULT_PRODUCT_ID],
            );
        }

        // Guarantee exactly one default, even if re-seeded after changes.
        $default = Device::query()->where('is_default', true)->first();
        if ($default === null) {
            Device::query()
                ->where('product_id', self::DEFAULT_PRODUCT_ID)
                ->update(['is_default' => true]);
        }
    }
}
