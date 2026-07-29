<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Tarif douanier ivoirien — extrait du fichier officiel du guichet unique
 * (guce.gouv.ci), position par position.
 *
 * Référentiel commun à tous les transitaires : le tarif est public et ne
 * dépend d'aucun tenant. Il se réactualise en rejouant ce seeder après mise à
 * jour du fichier source.
 */
class CustomsTariffSeeder extends Seeder
{
    private const CHUNK = 500;

    public function run(): void
    {
        $path = database_path('data/customs_tariffs.csv');
        if (! is_readable($path)) {
            $this->command?->warn('Fichier tarif absent — référentiel douanier non chargé.');

            return;
        }

        $handle = fopen($path, 'rb');
        fgetcsv($handle); // en-têtes

        $batch = [];
        $loaded = 0;
        while (($row = fgetcsv($handle)) !== false) {
            $batch[] = [
                'hs_code' => $row[0],
                'description' => $row[1],
                'duty_rate' => (float) $row[2],
                'vat_rate' => (float) $row[3],
                'all_in_rate' => $row[4] === '' ? null : (float) $row[4],
                'created_at' => now(),
                'updated_at' => now(),
            ];

            if (count($batch) >= self::CHUNK) {
                $loaded += $this->flush($batch);
                $batch = [];
            }
        }
        $loaded += $this->flush($batch);
        fclose($handle);

        $this->command?->info("{$loaded} positions tarifaires chargées.");
    }

    /** @param  list<array<string, mixed>>  $batch */
    private function flush(array $batch): int
    {
        if ($batch === []) {
            return 0;
        }

        DB::table('customs_tariffs')->upsert($batch, ['hs_code'], ['description', 'duty_rate', 'vat_rate', 'all_in_rate', 'updated_at']);

        return count($batch);
    }
}
