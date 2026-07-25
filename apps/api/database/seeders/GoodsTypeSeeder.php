<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Types de marchandises — génériques + classes IMO (marchandises dangereuses).
 */
class GoodsTypeSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        $rows = [
            // code, fr, en, imo_class, dangerous
            ['GEN', 'Marchandise générale', 'General cargo', null, false],
            ['REEFER', 'Denrées périssables (froid)', 'Perishable (reefer)', null, false],
            ['DRY_FOOD', 'Denrées sèches', 'Dry foodstuff', null, false],
            ['TEXTILE', 'Textiles', 'Textiles', null, false],
            ['ELEC', 'Électronique', 'Electronics', null, false],
            ['VEHICLE', 'Véhicules', 'Vehicles', null, false],
            ['MACHINE', 'Machines et équipements', 'Machinery & equipment', null, false],
            ['CONSTR', 'Matériaux de construction', 'Construction materials', null, false],
            ['CHEM_NH', 'Produits chimiques non dangereux', 'Non-hazardous chemicals', null, false],
            ['PHARMA', 'Produits pharmaceutiques', 'Pharmaceuticals', null, false],
            ['COCOA', 'Cacao', 'Cocoa', null, false],
            ['COFFEE', 'Café', 'Coffee', null, false],
            ['CASHEW', 'Noix de cajou', 'Cashew nuts', null, false],
            ['COTTON', 'Coton', 'Cotton', null, false],
            ['RUBBER', 'Caoutchouc', 'Rubber', null, false],
            ['TIMBER', 'Bois', 'Timber', null, false],
            ['IMO1', 'Explosifs (classe 1)', 'Explosives (class 1)', '1', true],
            ['IMO2', 'Gaz (classe 2)', 'Gases (class 2)', '2', true],
            ['IMO3', 'Liquides inflammables (classe 3)', 'Flammable liquids (class 3)', '3', true],
            ['IMO4', 'Solides inflammables (classe 4)', 'Flammable solids (class 4)', '4', true],
            ['IMO5', 'Comburants et peroxydes (classe 5)', 'Oxidizers & peroxides (class 5)', '5', true],
            ['IMO6', 'Matières toxiques (classe 6)', 'Toxic substances (class 6)', '6', true],
            ['IMO7', 'Matières radioactives (classe 7)', 'Radioactive material (class 7)', '7', true],
            ['IMO8', 'Matières corrosives (classe 8)', 'Corrosives (class 8)', '8', true],
            ['IMO9', 'Matières dangereuses diverses (classe 9)', 'Miscellaneous dangerous goods (class 9)', '9', true],
        ];

        foreach ($rows as [$code, $fr, $en, $imo, $dangerous]) {
            DB::table('goods_types')->updateOrInsert(
                ['code' => $code],
                ['id' => DB::table('goods_types')->where('code', $code)->value('id') ?? (string) Str::uuid7(),
                    'label_fr' => $fr, 'label_en' => $en, 'imo_class' => $imo, 'is_dangerous' => $dangerous,
                    'created_at' => $now, 'updated_at' => $now],
            );
        }
    }
}
