<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Compagnies aériennes cargo — préfixes AWB IATA.
 */
class AirlineSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        $rows = [
            ['057', 'AF', 'Air France Cargo'],
            ['074', 'KL', 'KLM Cargo'],
            ['176', 'EK', 'Emirates SkyCargo'],
            ['157', 'QR', 'Qatar Airways Cargo'],
            ['235', 'TK', 'Turkish Cargo'],
            ['071', 'ET', 'Ethiopian Cargo'],
            ['020', 'LH', 'Lufthansa Cargo'],
            ['172', 'CV', 'Cargolux'],
            ['160', 'CX', 'Cathay Cargo'],
            ['618', 'SQ', 'Singapore Airlines Cargo'],
            ['406', 'DE', 'DHL Aviation'],
            ['023', 'FX', 'FedEx Express'],
            ['925', 'KP', 'ASKY Cargo'],
            ['147', 'AT', 'Royal Air Maroc Cargo'],
        ];

        foreach ($rows as [$prefix, $iata, $name]) {
            DB::table('airlines')->updateOrInsert(
                ['awb_prefix' => $prefix],
                ['id' => DB::table('airlines')->where('awb_prefix', $prefix)->value('id') ?? (string) Str::uuid7(),
                    'iata' => $iata, 'name' => $name, 'is_active' => true,
                    'created_at' => $now, 'updated_at' => $now],
            );
        }
    }
}
