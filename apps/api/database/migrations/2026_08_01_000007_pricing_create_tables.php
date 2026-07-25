<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pricing — grilles tarifaires et devis (avant shipments : shipments.quote_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tariffs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('name');
            $table->string('mode')->comment('sea_fcl|sea_lcl|air|road|any');
            $table->string('side')->comment('buy = coût fournisseur | sell = prix client');
            $table->char('origin_locode', 5)->nullable()->comment('NULL = toutes origines');
            $table->char('destination_locode', 5)->nullable();
            $table->uuid('supplier_id')->nullable()->comment('Grille achat : fournisseur concerné');
            $table->uuid('party_id')->nullable()->comment('Grille vente dédiée à un client, NULL = générale');
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampTz('deleted_at')->nullable();
            $table->timestampsTz();
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('supplier_id')->references('id')->on('parties');
            $table->foreign('party_id')->references('id')->on('parties');
            $table->index(['tenant_id', 'mode', 'origin_locode', 'destination_locode'], 'ix_tariffs_route');
        });
        DB::statement("ALTER TABLE tariffs ADD CONSTRAINT ck_tariffs_mode CHECK (mode IN ('sea_fcl','sea_lcl','air','road','any'))");
        DB::statement("ALTER TABLE tariffs ADD CONSTRAINT ck_tariffs_side CHECK (side IN ('buy','sell'))");
        DB::statement('ALTER TABLE tariffs ADD CONSTRAINT ck_tariffs_validity CHECK (valid_to IS NULL OR valid_to >= valid_from)');

        Schema::create('tariff_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tariff_id');
            $table->string('service_code')->comment('freight|insurance|handling|customs|transport|baf|caf|other');
            $table->string('description');
            $table->string('unit')->comment('container|kg|m3|wm|flat|percent');
            $table->string('container_size_type', 8)->nullable()->comment('Si unit=container');
            $table->decimal('unit_price', 14, 4);
            $table->char('currency_code', 3);
            $table->decimal('minimum', 14, 2)->nullable()->comment('Minimum de perception');
            $table->decimal('weight_from_kg', 12, 3)->nullable()->comment('Tranche poids (aérien)');
            $table->decimal('weight_to_kg', 12, 3)->nullable();
            $table->timestampsTz();
            $table->foreign('tariff_id')->references('id')->on('tariffs')->cascadeOnDelete();
            $table->foreign('currency_code')->references('code')->on('currencies');
            $table->index('tariff_id', 'ix_tariff_lines_tariff');
        });
        DB::statement("ALTER TABLE tariff_lines ADD CONSTRAINT ck_tariff_lines_unit CHECK (unit IN ('container','kg','m3','wm','flat','percent'))");
        DB::statement('ALTER TABLE tariff_lines ADD CONSTRAINT ck_tariff_lines_price CHECK (unit_price >= 0)');

        Schema::create('quotes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('company_id');
            $table->string('number', 32);
            $table->unsignedInteger('revision')->default(1);
            $table->uuid('party_id');
            $table->uuid('owner_id')->comment('Commercial');
            $table->string('status')->default('draft');
            $table->string('mode');
            $table->string('direction');
            $table->char('origin_locode', 5);
            $table->char('destination_locode', 5);
            $table->char('incoterm_code', 3);
            $table->jsonb('cargo_summary')->default('{}')->comment('Poids, volume, conteneurs demandés');
            $table->char('currency_code', 3);
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->decimal('total_buy_amount', 14, 2)->nullable()->comment('Coût estimé → marge');
            $table->date('valid_until');
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('rejected_at')->nullable();
            $table->string('rejection_reason')->nullable();
            $table->integer('odoo_id')->nullable();
            $table->timestampsTz();
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('company_id')->references('id')->on('companies');
            $table->foreign('party_id')->references('id')->on('parties');
            $table->foreign('owner_id')->references('id')->on('users');
            $table->foreign('incoterm_code')->references('code')->on('incoterms');
            $table->foreign('currency_code')->references('code')->on('currencies');
            $table->unique(['tenant_id', 'number', 'revision'], 'ux_quotes_number_revision');
            $table->index(['tenant_id', 'status'], 'ix_quotes_tenant_status');
            $table->index(['tenant_id', 'party_id'], 'ix_quotes_tenant_party');
        });
        DB::statement("ALTER TABLE quotes ADD CONSTRAINT ck_quotes_status CHECK (status IN ('draft','sent','accepted','rejected','expired'))");
        DB::statement("ALTER TABLE quotes ADD CONSTRAINT ck_quotes_mode CHECK (mode IN ('sea_fcl','sea_lcl','air','road','multimodal'))");
        DB::statement("ALTER TABLE quotes ADD CONSTRAINT ck_quotes_direction CHECK (direction IN ('import','export'))");

        Schema::create('quote_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('quote_id');
            $table->unsignedInteger('position');
            $table->string('service_code');
            $table->string('description');
            $table->decimal('quantity', 12, 3)->default(1);
            $table->string('unit');
            $table->decimal('unit_price', 14, 4);
            $table->char('currency_code', 3);
            $table->decimal('buy_price', 14, 4)->nullable();
            $table->decimal('line_total', 14, 2)->storedAs('round(quantity * unit_price, 2)');
            $table->timestampsTz();
            $table->foreign('quote_id')->references('id')->on('quotes')->cascadeOnDelete();
            $table->foreign('currency_code')->references('code')->on('currencies');
            $table->index('quote_id', 'ix_quote_lines_quote');
        });
        DB::statement("ALTER TABLE quote_lines ADD CONSTRAINT ck_quote_lines_unit CHECK (unit IN ('container','kg','m3','wm','flat','percent','unit'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_lines');
        Schema::dropIfExists('quotes');
        Schema::dropIfExists('tariff_lines');
        Schema::dropIfExists('tariffs');
    }
};
