<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Incoterms 2020 — les 11 règles ICC.
 * cost_allocation : qui paie quoi (seller/buyer) — utilisé par le moteur de cotation.
 */
class IncotermSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        $rows = [
            ['EXW', 'Ex Works', ['main_carriage' => 'buyer', 'insurance' => 'buyer', 'export_customs' => 'buyer', 'import_customs' => 'buyer', 'delivery' => 'buyer']],
            ['FCA', 'Free Carrier', ['main_carriage' => 'buyer', 'insurance' => 'buyer', 'export_customs' => 'seller', 'import_customs' => 'buyer', 'delivery' => 'buyer']],
            ['FAS', 'Free Alongside Ship', ['main_carriage' => 'buyer', 'insurance' => 'buyer', 'export_customs' => 'seller', 'import_customs' => 'buyer', 'delivery' => 'buyer']],
            ['FOB', 'Free On Board', ['main_carriage' => 'buyer', 'insurance' => 'buyer', 'export_customs' => 'seller', 'import_customs' => 'buyer', 'delivery' => 'buyer']],
            ['CFR', 'Cost and Freight', ['main_carriage' => 'seller', 'insurance' => 'buyer', 'export_customs' => 'seller', 'import_customs' => 'buyer', 'delivery' => 'buyer']],
            ['CIF', 'Cost, Insurance and Freight', ['main_carriage' => 'seller', 'insurance' => 'seller', 'export_customs' => 'seller', 'import_customs' => 'buyer', 'delivery' => 'buyer']],
            ['CPT', 'Carriage Paid To', ['main_carriage' => 'seller', 'insurance' => 'buyer', 'export_customs' => 'seller', 'import_customs' => 'buyer', 'delivery' => 'buyer']],
            ['CIP', 'Carriage and Insurance Paid To', ['main_carriage' => 'seller', 'insurance' => 'seller', 'export_customs' => 'seller', 'import_customs' => 'buyer', 'delivery' => 'buyer']],
            ['DAP', 'Delivered At Place', ['main_carriage' => 'seller', 'insurance' => 'seller', 'export_customs' => 'seller', 'import_customs' => 'buyer', 'delivery' => 'seller']],
            ['DPU', 'Delivered at Place Unloaded', ['main_carriage' => 'seller', 'insurance' => 'seller', 'export_customs' => 'seller', 'import_customs' => 'buyer', 'delivery' => 'seller']],
            ['DDP', 'Delivered Duty Paid', ['main_carriage' => 'seller', 'insurance' => 'seller', 'export_customs' => 'seller', 'import_customs' => 'seller', 'delivery' => 'seller']],
        ];

        DB::table('incoterms')->upsert(
            array_map(fn (array $r) => [
                'code' => $r[0], 'label' => $r[1], 'version' => '2020',
                'cost_allocation' => json_encode($r[2]),
                'created_at' => $now, 'updated_at' => $now,
            ], $rows),
            ['code'],
            ['label', 'version', 'cost_allocation', 'updated_at'],
        );
    }
}
