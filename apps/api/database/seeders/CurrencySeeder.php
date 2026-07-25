<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CurrencySeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        $rows = [
            ['XOF', 'Franc CFA (UEMOA)', 'FCFA', 0],
            ['XAF', 'Franc CFA (CEMAC)', 'FCFA', 0],
            ['EUR', 'Euro', '€', 2],
            ['USD', 'Dollar américain', '$', 2],
            ['GBP', 'Livre sterling', '£', 2],
            ['CNY', 'Yuan renminbi', '¥', 2],
            ['JPY', 'Yen japonais', '¥', 0],
            ['CHF', 'Franc suisse', 'CHF', 2],
            ['CAD', 'Dollar canadien', 'C$', 2],
            ['AED', 'Dirham des ÉAU', 'د.إ', 2],
            ['INR', 'Roupie indienne', '₹', 2],
            ['TRY', 'Livre turque', '₺', 2],
            ['MAD', 'Dirham marocain', 'DH', 2],
            ['DZD', 'Dinar algérien', 'DA', 2],
            ['TND', 'Dinar tunisien', 'DT', 3],
            ['EGP', 'Livre égyptienne', 'E£', 2],
            ['NGN', 'Naira nigérian', '₦', 2],
            ['GHS', 'Cedi ghanéen', 'GH₵', 2],
            ['ZAR', 'Rand sud-africain', 'R', 2],
            ['KES', 'Shilling kényan', 'KSh', 2],
            ['ETB', 'Birr éthiopien', 'Br', 2],
            ['GNF', 'Franc guinéen', 'FG', 0],
            ['SGD', 'Dollar de Singapour', 'S$', 2],
            ['HKD', 'Dollar de Hong Kong', 'HK$', 2],
            ['KRW', 'Won sud-coréen', '₩', 0],
            ['BRL', 'Réal brésilien', 'R$', 2],
        ];

        DB::table('currencies')->upsert(
            array_map(fn (array $r) => [
                'code' => $r[0], 'name' => $r[1], 'symbol' => $r[2], 'decimals' => $r[3],
                'created_at' => $now, 'updated_at' => $now,
            ], $rows),
            ['code'],
            ['name', 'symbol', 'decimals', 'updated_at'],
        );
    }
}
