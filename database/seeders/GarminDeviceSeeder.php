<?php

namespace Database\Seeders;

use App\Support\GarminDevices;
use Illuminate\Database\Seeder;

class GarminDeviceSeeder extends Seeder
{
    public function run(): void
    {
        GarminDevices::seed();
    }
}
