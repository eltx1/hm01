<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call([
            IdentityAccessSeeder::class,
            InventoryDeliverySeeder::class,
            AdFormatSeeder::class,
            PrebidSeeder::class,
            DemandNetworkSeeder::class,
            ReportingSeeder::class,
        ]);
    }
}
