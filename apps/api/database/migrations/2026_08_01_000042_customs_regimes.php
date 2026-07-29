<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Régimes douaniers.
 *
 * Le tarif dit combien la marchandise coûterait à l'importation ; le régime dit
 * si elle la subit. Une marchandise en transit vers le Mali ou le Burkina ne
 * paie aucun droit ivoirien — chiffrer une offre sans le régime revient à
 * facturer au client des droits qu'il ne devra jamais, ou à oublier ceux qu'il
 * devra.
 *
 * Les régimes suspensifs (admission temporaire, entrepôt, transit) suspendent
 * les droits sans toujours les annuler : la marchandise reste sous surveillance
 * et les droits redeviennent exigibles si elle est mise à la consommation. La
 * cotation retient donc le régime, pas seulement son effet du jour.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customs_regimes', function (Blueprint $table): void {
            $table->string('code', 8)->primary();
            $table->string('name');
            $table->boolean('duty_applies')->comment('Le droit de douane est-il exigible ?');
            $table->boolean('vat_applies')->comment('La TVA est-elle exigible ?');
            $table->boolean('levies_apply')->default(true)->comment('Redevances communautaires exigibles');
            $table->boolean('is_suspensive')->default(false)->comment('Droits suspendus, non éteints');
            $table->string('note', 300)->nullable();
            $table->timestampsTz();
        });

        Schema::table('quotes', function (Blueprint $table): void {
            $table->string('customs_regime', 8)->nullable()->comment('Régime sous lequel la marchandise est déclarée');
            $table->foreign('customs_regime')->references('code')->on('customs_regimes');
        });

        // Régimes usuels du transit ivoirien. Chaque transitaire peut en
        // ajouter : la table est un référentiel, pas une liste figée.
        $now = now();
        DB::table('customs_regimes')->insert([
            [
                'code' => 'IM4', 'name' => 'Mise à la consommation',
                'duty_applies' => true, 'vat_applies' => true, 'levies_apply' => true, 'is_suspensive' => false,
                'note' => 'Régime de droit commun : droits et taxes intégralement exigibles.',
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'code' => 'IM8', 'name' => 'Transit',
                'duty_applies' => false, 'vat_applies' => false, 'levies_apply' => true, 'is_suspensive' => true,
                'note' => 'Marchandise traversant le pays — Mali, Burkina, Niger. Aucun droit ivoirien, redevances de passage seules.',
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'code' => 'IM5', 'name' => 'Admission temporaire',
                'duty_applies' => false, 'vat_applies' => false, 'levies_apply' => true, 'is_suspensive' => true,
                'note' => 'Droits suspendus tant que la marchandise ressort dans le délai accordé.',
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'code' => 'IM7', 'name' => 'Entrepôt de stockage',
                'duty_applies' => false, 'vat_applies' => false, 'levies_apply' => true, 'is_suspensive' => true,
                'note' => 'Droits suspendus pendant le séjour en entrepôt, exigibles à la sortie pour consommation.',
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'code' => 'IM6', 'name' => 'Réimportation',
                'duty_applies' => false, 'vat_applies' => true, 'levies_apply' => true, 'is_suspensive' => false,
                'note' => 'Retour de marchandise exportée : droit non dû, TVA sur la valeur ajoutée à l\'étranger.',
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'code' => 'EXO', 'name' => 'Exonération',
                'duty_applies' => false, 'vat_applies' => false, 'levies_apply' => false, 'is_suspensive' => false,
                'note' => 'Franchise diplomatique, projet agréé, don. Exige la pièce justificative.',
                'created_at' => $now, 'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table): void {
            $table->dropForeign(['customs_regime']);
            $table->dropColumn('customs_regime');
        });
        Schema::dropIfExists('customs_regimes');
    }
};
