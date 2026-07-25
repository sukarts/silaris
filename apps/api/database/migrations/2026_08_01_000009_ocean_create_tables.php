<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ocean — navires, voyages, bookings, conteneurs, BL, consolidations LCL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vessels', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('imo_number', 12)->nullable()->unique();
            $table->string('mmsi', 12)->nullable();
            $table->char('flag_country', 2)->nullable();
            $table->timestampsTz();
            $table->foreign('flag_country')->references('code2')->on('countries');
        });

        Schema::create('voyages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('vessel_id');
            $table->string('voyage_number', 24);
            $table->uuid('carrier_id')->nullable();
            $table->timestampsTz();
            $table->foreign('vessel_id')->references('id')->on('vessels');
            $table->foreign('carrier_id')->references('id')->on('carriers');
            $table->unique(['vessel_id', 'voyage_number'], 'ux_voyages_vessel_number');
        });

        Schema::create('port_calls', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('voyage_id');
            $table->char('port_locode', 5);
            $table->unsignedInteger('position');
            $table->timestampTz('eta')->nullable();
            $table->timestampTz('etd')->nullable();
            $table->timestampTz('ata')->nullable();
            $table->timestampTz('atd')->nullable();
            $table->timestampsTz();
            $table->foreign('voyage_id')->references('id')->on('voyages')->cascadeOnDelete();
            $table->foreign('port_locode')->references('locode')->on('ports');
            $table->unique(['voyage_id', 'position'], 'ux_port_calls_position');
        });

        Schema::create('bookings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('shipment_id');
            $table->uuid('carrier_id');
            $table->uuid('voyage_id')->nullable();
            $table->string('booking_number', 32)->nullable();
            $table->string('status')->default('requested');
            $table->timestampTz('vgm_cutoff')->nullable();
            $table->timestampTz('doc_cutoff')->nullable();
            $table->timestampTz('port_cutoff')->nullable();
            $table->timestampTz('confirmed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('shipment_id')->references('id')->on('shipments');
            $table->foreign('carrier_id')->references('id')->on('carriers');
            $table->foreign('voyage_id')->references('id')->on('voyages');
            $table->index(['tenant_id', 'status'], 'ix_bookings_tenant_status');
            $table->index('shipment_id', 'ix_bookings_shipment');
        });
        DB::statement("ALTER TABLE bookings ADD CONSTRAINT ck_bookings_status CHECK (status IN ('requested','confirmed','rolled','cancelled'))");

        Schema::create('containers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('number', 11);
            $table->string('size_type', 8);
            $table->decimal('tare_kg', 12, 3)->nullable();
            $table->decimal('max_payload_kg', 12, 3)->nullable();
            $table->timestampsTz();
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->unique(['tenant_id', 'number'], 'ux_containers_tenant_number');
        });
        DB::statement('ALTER TABLE containers ADD CONSTRAINT ck_containers_iso6346 CHECK (iso6346_check(number))');
        DB::statement("ALTER TABLE containers ADD CONSTRAINT ck_containers_size_type CHECK (size_type IN ('20GP','40GP','40HC','45HC','20RF','40RF','20OT','40OT','20FR','40FR','20TK'))");

        Schema::create('container_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('container_id');
            $table->uuid('shipment_id');
            $table->uuid('booking_id')->nullable();
            $table->string('seal_number', 32)->nullable();
            $table->decimal('vgm_kg', 12, 3)->nullable();
            $table->timestampTz('vgm_verified_at')->nullable();
            $table->unsignedSmallInteger('free_time_days')->nullable();
            $table->timestampTz('free_time_ends_at')->nullable()->comment('Calculée à l\'ATA — alertes surestaries');
            $table->timestampTz('gate_in_at')->nullable();
            $table->timestampTz('loaded_at')->nullable();
            $table->timestampTz('discharged_at')->nullable();
            $table->timestampTz('gate_out_at')->nullable();
            $table->timestampTz('returned_at')->nullable();
            $table->timestampsTz();
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('container_id')->references('id')->on('containers');
            $table->foreign('shipment_id')->references('id')->on('shipments');
            $table->foreign('booking_id')->references('id')->on('bookings');
            $table->index('shipment_id', 'ix_container_assignments_shipment');
        });
        DB::statement('CREATE UNIQUE INDEX ux_assignment_active ON container_assignments (container_id) WHERE returned_at IS NULL');
        DB::statement('CREATE INDEX ix_assignments_free_time ON container_assignments (tenant_id, free_time_ends_at) WHERE returned_at IS NULL AND free_time_ends_at IS NOT NULL');

        Schema::create('bills_of_lading', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('shipment_id');
            $table->uuid('parent_id')->nullable()->comment('HBL → son MBL');
            $table->string('type');
            $table->string('number', 32);
            $table->string('release_type')->default('original');
            $table->string('status')->default('draft');
            $table->jsonb('shipper')->default('{}')->comment('Snapshot figé à l\'émission');
            $table->jsonb('consignee')->default('{}');
            $table->jsonb('notify_party')->default('{}');
            $table->text('goods_description')->nullable();
            $table->decimal('gross_weight_kg', 12, 3)->nullable();
            $table->decimal('volume_m3', 12, 3)->nullable();
            $table->unsignedInteger('packages_count')->nullable();
            $table->timestampTz('issued_at')->nullable();
            $table->uuid('issued_by')->nullable();
            $table->timestampsTz();
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('shipment_id')->references('id')->on('shipments');
            $table->foreign('issued_by')->references('id')->on('users');
            $table->unique(['tenant_id', 'number'], 'ux_bl_tenant_number');
            $table->index('shipment_id', 'ix_bl_shipment');
            $table->index('parent_id', 'ix_bl_parent');
        });
        // FK auto-référente ajoutée après création (la PK doit exister avant la FK).
        Schema::table('bills_of_lading', function (Blueprint $table) {
            $table->foreign('parent_id')->references('id')->on('bills_of_lading');
        });
        DB::statement("ALTER TABLE bills_of_lading ADD CONSTRAINT ck_bl_type CHECK (type IN ('master','house'))");
        DB::statement("ALTER TABLE bills_of_lading ADD CONSTRAINT ck_bl_release CHECK (release_type IN ('original','telex','seaway'))");
        DB::statement("ALTER TABLE bills_of_lading ADD CONSTRAINT ck_bl_status CHECK (status IN ('draft','verified','issued','surrendered'))");
        DB::statement("ALTER TABLE bills_of_lading ADD CONSTRAINT ck_bl_master_no_parent CHECK (type <> 'master' OR parent_id IS NULL)");

        Schema::create('consolidations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('reference', 32);
            $table->uuid('container_id')->nullable();
            $table->uuid('master_bl_id')->nullable();
            $table->string('status')->default('open');
            $table->timestampTz('closed_at')->nullable();
            $table->timestampTz('deconsolidated_at')->nullable();
            $table->timestampsTz();
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('container_id')->references('id')->on('containers');
            $table->foreign('master_bl_id')->references('id')->on('bills_of_lading');
            $table->unique(['tenant_id', 'reference'], 'ux_consolidations_reference');
        });
        DB::statement("ALTER TABLE consolidations ADD CONSTRAINT ck_consolidations_status CHECK (status IN ('open','closed','deconsolidated'))");

        Schema::create('consolidation_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('consolidation_id');
            $table->uuid('shipment_id');
            $table->uuid('house_bl_id')->nullable();
            $table->decimal('weight_kg', 12, 3)->nullable();
            $table->decimal('volume_m3', 12, 3)->nullable();
            $table->timestampsTz();
            $table->foreign('consolidation_id')->references('id')->on('consolidations')->cascadeOnDelete();
            $table->foreign('shipment_id')->references('id')->on('shipments');
            $table->foreign('house_bl_id')->references('id')->on('bills_of_lading');
            $table->unique(['consolidation_id', 'shipment_id'], 'ux_consolidation_items_shipment');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consolidation_items');
        Schema::dropIfExists('consolidations');
        Schema::dropIfExists('bills_of_lading');
        Schema::dropIfExists('container_assignments');
        Schema::dropIfExists('containers');
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('port_calls');
        Schema::dropIfExists('voyages');
        Schema::dropIfExists('vessels');
    }
};
