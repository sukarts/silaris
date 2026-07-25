<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Seeders de référentiels — idempotents (upsert), exécutables en production.
 * Le tenant de démonstration est séparé : php artisan db:seed --class=DemoTenantSeeder
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            CurrencySeeder::class,
            CountrySeeder::class,
            PortSeeder::class,
            AirportSeeder::class,
            IncotermSeeder::class,
            CarrierSeeder::class,
            AirlineSeeder::class,
            GoodsTypeSeeder::class,
            CarrierStatusMappingSeeder::class,
            PermissionSeeder::class,
        ]);
    }
}
