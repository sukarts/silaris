<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Découple l'export comptable du cycle de vie de la facture.
 *
 * La facture ne connaissait que quatre états, dont deux — synced, sync_failed —
 * décrivaient en réalité une comptabilité tierce : Odoo. Une facture parfaitement
 * émise tombait ainsi en « sync_failed » parce qu'un système extérieur était
 * indisponible, comme si son sort en dépendait. Or SILARIS facture désormais
 * dans ses murs ; l'export vers une comptabilité — Odoo, Sage, un fichier FEC —
 * est un débouché, pas une étape de la facture.
 *
 * Le statut se réduit donc à ce qui appartient à la facture : brouillon,
 * validée. L'export devient un état à part, avec sa référence et son erreur, que
 * n'importe quel connecteur alimente sans toucher au statut.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->string('accounting_export_status', 16)->default('none')
                ->comment('none, pending, exported, failed — export vers une comptabilité tierce');
            $table->string('accounting_ref', 64)->nullable()->comment('Référence rendue par la comptabilité (ex. account.move Odoo)');
            $table->string('accounting_error', 500)->nullable();
            $table->timestampTz('accounting_exported_at')->nullable();
        });

        // Reprise : ce qui était « synced » est une facture validée bel et bien
        // exportée ; « sync_failed », une facture validée dont l'export a échoué.
        // Le statut redevient « validated » dans les deux cas.
        DB::statement(<<<'SQL'
UPDATE invoices SET
    accounting_export_status = 'exported',
    accounting_ref = odoo_id::text,
    accounting_exported_at = updated_at,
    status = 'validated'
WHERE status = 'synced'
SQL);
        DB::statement(<<<'SQL'
UPDATE invoices SET
    accounting_export_status = 'failed',
    status = 'validated'
WHERE status = 'sync_failed'
SQL);

        // Le statut ne décrit plus qu'un fait interne à la facture.
        DB::statement('ALTER TABLE invoices DROP CONSTRAINT ck_invoices_status');
        DB::statement("ALTER TABLE invoices ADD CONSTRAINT ck_invoices_status CHECK (status IN ('draft', 'validated'))");
        DB::statement(<<<'SQL'
ALTER TABLE invoices ADD CONSTRAINT ck_invoices_accounting_export
    CHECK (accounting_export_status IN ('none', 'pending', 'exported', 'failed'))
SQL);
    }

    public function down(): void
    {
        // Rétablir les anciens états depuis l'export avant de retirer les colonnes.
        DB::statement('ALTER TABLE invoices DROP CONSTRAINT ck_invoices_status');
        DB::statement("ALTER TABLE invoices ADD CONSTRAINT ck_invoices_status CHECK (status IN ('draft','validated','synced','sync_failed'))");
        DB::statement("UPDATE invoices SET status = 'synced' WHERE accounting_export_status = 'exported'");
        DB::statement("UPDATE invoices SET status = 'sync_failed' WHERE accounting_export_status = 'failed'");

        DB::statement('ALTER TABLE invoices DROP CONSTRAINT ck_invoices_accounting_export');
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropColumn(['accounting_export_status', 'accounting_ref', 'accounting_error', 'accounting_exported_at']);
        });
    }
};
