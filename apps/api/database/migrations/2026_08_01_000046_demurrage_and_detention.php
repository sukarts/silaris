<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Séparer surestaries et détention.
 *
 * La compagnie facture deux immobilisations distinctes, et accorde pour chacune
 * sa propre franchise :
 *
 *  - la surestarie court tant que le conteneur reste AU TERMINAL — du
 *    déchargement à sa sortie du port à l'import, de l'entrée du plein à son
 *    chargement sur le navire à l'export ;
 *  - la détention court tant que le conteneur est CHEZ LE CLIENT — de la sortie
 *    du port à la restitution du vide à l'import, de l'enlèvement du vide à
 *    l'entrée du plein à l'export.
 *
 * Le module ne suivait qu'un seul compteur, du déchargement à la restitution :
 * il mêlait les deux et masquait la surestarie. Le compteur unique existant
 * s'arrêtait à la restitution du vide — c'était la détention ; les colonnes
 * free_time deviennent donc la détention, et la surestarie s'ajoute à côté.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Franchises, sur le document porteur ──────────────────────────────
        foreach (['bills_of_lading', 'bookings'] as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->unsignedSmallInteger('demurrage_free_days')->nullable()
                    ->comment('Franchise surestaries — immobilisation au terminal');
                $blueprint->unsignedSmallInteger('detention_free_days')->nullable()
                    ->comment('Franchise détention — conteneur chez le client');
            });
            // Le free_time négocié jusqu'ici était la détention (arrêt à la
            // restitution du vide) : il y remonte tel quel.
            DB::statement("UPDATE {$table} SET detention_free_days = free_time_days WHERE free_time_days IS NOT NULL");
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn('free_time_days'));
        }

        // ── Échéances, sur l'affectation du conteneur ────────────────────────
        Schema::table('container_assignments', function (Blueprint $table): void {
            $table->unsignedSmallInteger('demurrage_days')->nullable();
            $table->timestampTz('demurrage_ends_at')->nullable()->comment('Échéance surestaries — sortie du terminal à faire avant');
            $table->unsignedSmallInteger('detention_days')->nullable();
            $table->timestampTz('detention_ends_at')->nullable()->comment('Échéance détention — restitution du vide à faire avant');
        });

        // L'ancien compteur (arrêt à la restitution) était la détention.
        DB::statement('UPDATE container_assignments SET detention_days = free_time_days, detention_ends_at = free_time_ends_at');

        // La vue d'alerte dépend de free_time_ends_at : la reconstruire sur les
        // deux compteurs avant de retirer la colonne.
        DB::statement('DROP VIEW IF EXISTS v_demurrage_alerts');
        DB::statement(<<<'SQL'
CREATE VIEW v_demurrage_alerts AS
SELECT ca.id, ca.tenant_id, ca.shipment_id, ca.container_id, c.number AS container_number,
       'demurrage' AS kind, ca.demurrage_ends_at AS ends_at,
       EXTRACT(day FROM ca.demurrage_ends_at - now())::int AS days_remaining
FROM container_assignments ca
JOIN containers c ON c.id = ca.container_id
WHERE ca.gate_out_at IS NULL AND ca.demurrage_ends_at IS NOT NULL
UNION ALL
SELECT ca.id, ca.tenant_id, ca.shipment_id, ca.container_id, c.number AS container_number,
       'detention' AS kind, ca.detention_ends_at AS ends_at,
       EXTRACT(day FROM ca.detention_ends_at - now())::int AS days_remaining
FROM container_assignments ca
JOIN containers c ON c.id = ca.container_id
WHERE ca.returned_at IS NULL AND ca.detention_ends_at IS NOT NULL
SQL);

        // Index de balayage : un par compteur, restreint aux affectations encore
        // en cours pour le compteur considéré.
        DB::statement('DROP INDEX IF EXISTS ix_assignments_free_time');
        DB::statement(<<<'SQL'
CREATE INDEX ix_assignments_demurrage ON container_assignments (tenant_id, demurrage_ends_at)
    WHERE gate_out_at IS NULL AND demurrage_ends_at IS NOT NULL
SQL);
        DB::statement(<<<'SQL'
CREATE INDEX ix_assignments_detention ON container_assignments (tenant_id, detention_ends_at)
    WHERE returned_at IS NULL AND detention_ends_at IS NOT NULL
SQL);

        Schema::table('container_assignments', fn (Blueprint $table) => $table->dropColumn(['free_time_days', 'free_time_ends_at']));
    }

    public function down(): void
    {
        Schema::table('container_assignments', function (Blueprint $table): void {
            $table->unsignedSmallInteger('free_time_days')->nullable();
            $table->timestampTz('free_time_ends_at')->nullable();
        });
        DB::statement('UPDATE container_assignments SET free_time_days = detention_days, free_time_ends_at = detention_ends_at');
        DB::statement('DROP VIEW IF EXISTS v_demurrage_alerts');
        DB::statement(<<<'SQL'
CREATE VIEW v_demurrage_alerts AS
SELECT ca.id, ca.tenant_id, ca.shipment_id, ca.container_id, c.number AS container_number,
       ca.free_time_ends_at,
       EXTRACT(day FROM ca.free_time_ends_at - now())::int AS days_remaining
FROM container_assignments ca
JOIN containers c ON c.id = ca.container_id
WHERE ca.returned_at IS NULL AND ca.free_time_ends_at IS NOT NULL
SQL);
        DB::statement('DROP INDEX IF EXISTS ix_assignments_demurrage');
        DB::statement('DROP INDEX IF EXISTS ix_assignments_detention');
        DB::statement(<<<'SQL'
CREATE INDEX ix_assignments_free_time ON container_assignments (tenant_id, free_time_ends_at)
    WHERE returned_at IS NULL AND free_time_ends_at IS NOT NULL
SQL);
        Schema::table('container_assignments', fn (Blueprint $table) => $table->dropColumn(['demurrage_days', 'demurrage_ends_at', 'detention_days', 'detention_ends_at']));

        foreach (['bills_of_lading', 'bookings'] as $table) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->unsignedSmallInteger('free_time_days')->nullable());
            DB::statement("UPDATE {$table} SET free_time_days = detention_free_days WHERE detention_free_days IS NOT NULL");
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn(['demurrage_free_days', 'detention_free_days']));
        }
    }
};
