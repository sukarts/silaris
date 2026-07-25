<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Compagnies maritimes — les 9 connecteurs V1 (clé connecteur = registre CarrierConnect).
 */
class CarrierSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        $rows = [
            ['MSCU', 'MSC — Mediterranean Shipping Company', 'msc'],
            ['CMDU', 'CMA CGM', 'cma_cgm'],
            ['MAEU', 'Maersk', 'maersk'],
            ['HLCU', 'Hapag-Lloyd', 'hapag_lloyd'],
            ['COSU', 'COSCO Shipping Lines', 'cosco'],
            ['EGLV', 'Evergreen Line', 'evergreen'],
            ['ONEY', 'Ocean Network Express (ONE)', 'one'],
            ['OOLU', 'OOCL', 'oocl'],
            ['YMLU', 'Yang Ming', 'yang_ming'],
        ];

        foreach ($rows as [$scac, $name, $connector]) {
            DB::table('carriers')->updateOrInsert(
                ['scac' => $scac],
                ['id' => DB::table('carriers')->where('scac', $scac)->value('id') ?? (string) Str::uuid7(),
                    'name' => $name, 'connector_key' => $connector, 'is_active' => true,
                    'created_at' => $now, 'updated_at' => $now],
            );
        }
    }
}
