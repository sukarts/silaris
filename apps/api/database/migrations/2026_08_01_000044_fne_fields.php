<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Facture Normalisée Électronique (FNE) — DGI Côte d'Ivoire.
 *
 * Obligatoire depuis juin 2025 : une facture doit porter un numéro fiscal, un
 * sceau et un QR de certification délivrés en temps réel par la DGI. La
 * certification s'obtient par API, chaque société transitaire s'enrôlant pour
 * son compte — d'où des identifiants et une clé propres à chaque société, et
 * non partagés par le tenant.
 *
 * Le NCC du client est obligatoire pour une facture B2B : sans lui, la DGI
 * refuse la certification. Il vit donc sur le tiers, à côté du RCCM.
 *
 * La clé d'API est un secret : colonne à part, chiffrée au repos, jamais
 * exposée par l'API applicative.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            // Identifiants d'enrôlement, non secrets : NCC de la société, point
            // de vente et établissement déclarés à la DGI, et l'interrupteur qui
            // dit si la certification est active pour cette société.
            $table->jsonb('fne_settings')->default('{}')
                ->comment('ncc, point_of_sale, establishment, enabled — identifiants FNE de la société');
            // Clé d'API délivrée par la DGI, chiffrée au repos.
            $table->text('fne_api_key')->nullable();
        });

        // NCC et régime d'imposition du client — le NCC est requis en B2B, le
        // régime figure sur la facture normalisée à côté de lui.
        Schema::table('parties', function (Blueprint $table): void {
            $table->string('ncc', 32)->nullable()->comment('Numéro de Compte Contribuable — requis en B2B');
            $table->string('tax_regime', 32)->nullable()->comment("Régime d'imposition, mention FNE (TEE, réel…)");
        });

        // Ce que la DGI renvoie et qui doit figurer sur la facture : numéro
        // fiscal, jeton de vérification (porte le QR), solde de stickers restant.
        Schema::table('invoices', function (Blueprint $table): void {
            $table->string('fne_reference', 64)->nullable()->comment('Numéro fiscal délivré par la DGI');
            $table->text('fne_token')->nullable()->comment('Jeton de vérification — source du QR code');
            $table->integer('fne_balance_sticker')->nullable()->comment('Stickers restants au compte DGI');
            $table->string('fne_template', 8)->nullable()->comment('B2B, B2C, B2F, B2G retenu à la certification');
            $table->string('fne_seller_name')->nullable()->comment('Nom du vendeur porté sur la facture normalisée');
            $table->timestampTz('fne_certified_at')->nullable();
        });

        // Une facture n'est certifiée qu'une fois : le numéro fiscal est unique.
        DB::statement('CREATE UNIQUE INDEX ux_invoices_fne_reference ON invoices (fne_reference) WHERE fne_reference IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS ux_invoices_fne_reference');
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropColumn(['fne_reference', 'fne_token', 'fne_balance_sticker', 'fne_template', 'fne_seller_name', 'fne_certified_at']);
        });
        Schema::table('parties', function (Blueprint $table): void {
            $table->dropColumn(['ncc', 'tax_regime']);
        });
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn(['fne_settings', 'fne_api_key']);
        });
    }
};
