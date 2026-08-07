<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Barème des débours — tarifs standards de la maison, proposés à la saisie et
 * toujours modifiables. Repris de la procédure de cotation SAHA.
 *
 * Deux montants là où le poste dépend du conteneur (TC20 / TC40) ; une note
 * libre pour les postes calculés sur une base (pourcentage de valeur, tranches
 * de magasinage) qu'un montant fixe ne saurait porter.
 */
return new class extends Migration
{
    /** code catalogue => [tc20, tc40, note] ; null = pas de montant proposé. */
    private const TARIFS = [
        'ACCONAGE' => [202000, 405000, 'Selon conteneur'],
        'TIRAGE' => [100000, 200000, null],
        'ECHANGE_BL' => [360000, 560000, 'Selon compagnie'],
        'PASSAGE_DOUANE' => [100000, 100000, 'Par conteneur (× TC)'],
        'VISITE_EMPOTAGE' => [200000, 200000, 'Par conteneur'],
        'SURESTARIES' => [29500, 59000, 'Par jour'],
        'COMMISSION' => [300000, 300000, 'Par conteneur'],
        'PRESTATION' => [250000, 300000, null],
        // Postes sur base variable : pas de montant fixe, seulement la règle.
        'LIVRAISON' => [null, null, 'Selon zone et mode (Zone I / II / III)'],
        'ASSURANCE' => [null, null, '0,15 à 0,30 % de la valeur + 2 500'],
        'FDI_RFCV' => [null, null, '25 000 à 70 000 selon le client'],
        'AGIOS' => [null, null, '0,2 % de la valeur marchandise'],
        'MAGASINAGE' => [null, null, 'Tranches 850 / 1 250 / 1 850 F par tonne et par jour'],
    ];

    public function up(): void
    {
        Schema::table('service_catalog', function (Blueprint $table): void {
            $table->decimal('default_tc20', 14, 2)->nullable();
            $table->decimal('default_tc40', 14, 2)->nullable();
            $table->string('pricing_note', 120)->nullable();
        });

        foreach (self::TARIFS as $code => [$tc20, $tc40, $note]) {
            DB::table('service_catalog')->where('code', $code)->update([
                'default_tc20' => $tc20,
                'default_tc40' => $tc40,
                'pricing_note' => $note,
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('service_catalog', function (Blueprint $table): void {
            $table->dropColumn(['default_tc20', 'default_tc40', 'pricing_note']);
        });
    }
};
