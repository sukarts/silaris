<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Franchise de détention — le nombre de jours pendant lesquels la compagnie
 * n'facture pas l'immobilisation de ses conteneurs.
 *
 * Elle se négocie par document, non par conteneur : au connaissement à
 * l'import, au booking à l'export. Tous les conteneurs du document en héritent.
 *
 * L'échéance, elle, reste propre à chaque conteneur : le décompte part de son
 * déchargement (import) ou de la sortie du vide (export), et deux boîtes du
 * même connaissement ne sortent pas le même jour. D'où la franchise sur le
 * document et l'échéance sur l'affectation.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['bills_of_lading' => 'import', 'bookings' => 'export'] as $table => $sens) {
            Schema::table($table, function (Blueprint $blueprint) use ($sens): void {
                $blueprint->unsignedSmallInteger('free_time_days')->nullable()
                    ->comment("Jours de franchise négociés — {$sens}");
            });
        }

        // La franchise saisie jusqu'ici au niveau conteneur remonte au document
        // du dossier : c'est la même valeur, rangée au bon endroit.
        DB::statement(<<<'SQL'
UPDATE bills_of_lading AS b
SET free_time_days = source.days
FROM (
    SELECT ca.shipment_id, MAX(ca.free_time_days) AS days
    FROM container_assignments AS ca
    WHERE ca.free_time_days IS NOT NULL
    GROUP BY ca.shipment_id
) AS source
WHERE source.shipment_id = b.shipment_id AND b.type = 'master'
SQL);
    }

    public function down(): void
    {
        foreach (['bills_of_lading', 'bookings'] as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropColumn('free_time_days');
            });
        }
    }
};
