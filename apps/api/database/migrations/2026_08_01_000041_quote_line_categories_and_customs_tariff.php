<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Débours douane et débours divers, et le tarif qui les alimente.
 *
 * Une offre de transit import ne se lit pas comme une facture de prestation :
 * le client veut distinguer ce qui part à la douane — droits, taxes, redevances,
 * qu'il paie sans marge — de ce que le transitaire facture pour son travail.
 * D'où deux sous-totaux, et la catégorie portée par chaque ligne.
 *
 * Le tarif douanier ivoirien accompagne : à partir de la position tarifaire et
 * de la valeur CAF, les huit lignes de débours douane se calculent au lieu de
 * se saisir. Les taux viennent du fichier officiel du guichet unique.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quote_lines', function (Blueprint $table): void {
            $table->string('category', 16)->default('other')
                ->comment('customs = débours douane, other = débours divers');
        });

        DB::statement(<<<'SQL'
ALTER TABLE quote_lines ADD CONSTRAINT ck_quote_lines_category
    CHECK (category IN ('customs', 'other'))
SQL);

        // Référentiel partagé par tous les transitaires : le tarif douanier est
        // public et identique pour tous, il ne relève d'aucun tenant.
        Schema::create('customs_tariffs', function (Blueprint $table): void {
            $table->string('hs_code', 12)->primary()->comment('Position tarifaire (SH 10 chiffres)');
            $table->string('description', 500);
            $table->decimal('duty_rate', 6, 3)->comment('Droit de douane, % de la valeur CAF');
            $table->decimal('vat_rate', 6, 3)->comment('TVA, % de la base taxable');
            $table->decimal('all_in_rate', 7, 3)->nullable()
                ->comment('Taux global officiel sur la valeur CAF, droits et taxes cumulés');
            $table->timestampsTz();

            $table->index('description', 'ix_customs_tariffs_description');
        });

        // La cotation retient la position et la valeur qui ont servi au calcul :
        // sans elles, un droit de douane recalculé plus tard ne retomberait pas
        // sur le même montant et la différence serait inexplicable.
        Schema::table('quotes', function (Blueprint $table): void {
            $table->string('hs_code', 12)->nullable()->comment('Position tarifaire de la marchandise');
            $table->decimal('customs_value', 18, 2)->nullable()->comment('Valeur CAF servant de base aux droits');
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table): void {
            $table->dropColumn(['hs_code', 'customs_value']);
        });
        Schema::dropIfExists('customs_tariffs');
        DB::statement('ALTER TABLE quote_lines DROP CONSTRAINT ck_quote_lines_category');
        Schema::table('quote_lines', function (Blueprint $table): void {
            $table->dropColumn('category');
        });
    }
};
