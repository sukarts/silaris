<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Air — MAWB/HAWB, segments de vol.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('air_waybills', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('shipment_id');
            $table->uuid('parent_id')->nullable()->comment('HAWB → son MAWB');
            $table->string('type');
            $table->string('number', 16)->comment('MAWB : 11 chiffres validés mod 7 ; HAWB : format libre transitaire');
            $table->uuid('airline_id')->nullable();
            $table->decimal('gross_weight_kg', 12, 3)->nullable();
            $table->decimal('volume_m3', 12, 3)->nullable();
            $table->unsignedInteger('packages_count')->nullable();
            $table->string('status')->default('draft');
            $table->jsonb('shipper')->default('{}');
            $table->jsonb('consignee')->default('{}');
            $table->text('goods_description')->nullable();
            $table->timestampTz('issued_at')->nullable();
            $table->uuid('issued_by')->nullable();
            $table->timestampsTz();
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('shipment_id')->references('id')->on('shipments');
            $table->foreign('airline_id')->references('id')->on('airlines');
            $table->foreign('issued_by')->references('id')->on('users');
            $table->unique(['tenant_id', 'number'], 'ux_awb_tenant_number');
            $table->index('shipment_id', 'ix_awb_shipment');
        });
        // FK auto-référente ajoutée après création (la PK doit exister avant la FK).
        Schema::table('air_waybills', function (Blueprint $table) {
            $table->foreign('parent_id')->references('id')->on('air_waybills');
        });
        DB::statement("ALTER TABLE air_waybills ADD CONSTRAINT ck_awb_type CHECK (type IN ('master','house'))");
        DB::statement("ALTER TABLE air_waybills ADD CONSTRAINT ck_awb_status CHECK (status IN ('draft','issued','executed'))");
        DB::statement("ALTER TABLE air_waybills ADD CONSTRAINT ck_awb_master_no_parent CHECK (type <> 'master' OR parent_id IS NULL)");
        DB::statement("ALTER TABLE air_waybills ADD CONSTRAINT ck_awb_master_mod7 CHECK (type <> 'master' OR awb_mod7(number))");
        // Poids taxable IATA : max(brut, volume × 166.667 kg/m³) — colonne générée, incohérence impossible.
        DB::statement('ALTER TABLE air_waybills ADD COLUMN chargeable_weight_kg numeric(12,3) GENERATED ALWAYS AS (GREATEST(COALESCE(gross_weight_kg, 0), COALESCE(volume_m3, 0) * 166.667)) STORED');

        Schema::create('flight_legs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('awb_id');
            $table->unsignedInteger('position');
            $table->string('flight_number', 8);
            $table->char('origin_iata', 3);
            $table->char('destination_iata', 3);
            $table->timestampTz('departure_at')->nullable();
            $table->timestampTz('arrival_at')->nullable();
            $table->timestampsTz();
            $table->foreign('awb_id')->references('id')->on('air_waybills')->cascadeOnDelete();
            $table->foreign('origin_iata')->references('iata')->on('airports');
            $table->foreign('destination_iata')->references('iata')->on('airports');
            $table->unique(['awb_id', 'position'], 'ux_flight_legs_position');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flight_legs');
        Schema::dropIfExists('air_waybills');
    }
};
