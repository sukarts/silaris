<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Référentiels globaux — partagés entre tous les tenants, sans tenant_id.
 * Lecture seule pour le rôle applicatif ; alimentés par seeders / silaris_admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('countries', function (Blueprint $table) {
            $table->char('code2', 2)->primary();
            $table->char('code3', 3)->unique();
            $table->string('name_fr');
            $table->string('name_en');
            $table->timestampsTz();
        });

        Schema::create('currencies', function (Blueprint $table) {
            $table->char('code', 3)->primary();
            $table->string('name');
            $table->string('symbol', 8);
            $table->unsignedTinyInteger('decimals')->default(2);
            $table->timestampsTz();
        });

        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->char('base_code', 3);
            $table->char('quote_code', 3);
            $table->date('rate_date');
            $table->decimal('rate', 18, 8);
            $table->string('source')->default('manual');
            $table->timestampsTz();
            $table->primary(['base_code', 'quote_code', 'rate_date']);
            $table->foreign('base_code')->references('code')->on('currencies');
            $table->foreign('quote_code')->references('code')->on('currencies');
        });

        Schema::create('ports', function (Blueprint $table) {
            $table->char('locode', 5)->primary();
            $table->string('name');
            $table->char('country_code', 2);
            $table->decimal('latitude', 9, 6)->nullable();
            $table->decimal('longitude', 9, 6)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->foreign('country_code')->references('code2')->on('countries');
            $table->index('country_code', 'ix_ports_country');
        });

        Schema::create('airports', function (Blueprint $table) {
            $table->char('iata', 3)->primary();
            $table->char('icao', 4)->nullable()->unique();
            $table->char('locode', 5)->nullable()->unique();
            $table->string('name');
            $table->char('country_code', 2);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->foreign('country_code')->references('code2')->on('countries');
        });

        Schema::create('incoterms', function (Blueprint $table) {
            $table->char('code', 3)->primary();
            $table->string('label');
            $table->string('version', 8)->default('2020');
            $table->text('description')->nullable();
            $table->jsonb('cost_allocation')->default('{}');
            $table->timestampsTz();
        });

        Schema::create('carriers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('scac', 4)->unique();
            $table->string('name');
            $table->string('connector_key')->nullable()->comment('Clé du connecteur CarrierConnect, null = pas d\'API');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        Schema::create('airlines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->char('awb_prefix', 3)->unique();
            $table->char('iata', 2)->nullable();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        Schema::create('goods_types', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code')->unique();
            $table->string('label_fr');
            $table->string('label_en');
            $table->string('imo_class', 8)->nullable()->comment('Classe IMO si marchandise dangereuse');
            $table->boolean('is_dangerous')->default(false);
            $table->timestampsTz();
        });

        DB::statement("COMMENT ON TABLE countries IS 'Référentiel global ISO 3166 — sans tenant'");
        DB::statement("COMMENT ON TABLE ports IS 'Référentiel global UN/LOCODE — sans tenant'");
    }

    public function down(): void
    {
        Schema::dropIfExists('goods_types');
        Schema::dropIfExists('airlines');
        Schema::dropIfExists('carriers');
        Schema::dropIfExists('incoterms');
        Schema::dropIfExists('airports');
        Schema::dropIfExists('ports');
        Schema::dropIfExists('exchange_rates');
        Schema::dropIfExists('currencies');
        Schema::dropIfExists('countries');
    }
};
