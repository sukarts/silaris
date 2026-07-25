<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Road — flotte, chauffeurs, missions, preuve de livraison.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trucks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('plate_number', 16);
            $table->string('type')->nullable()->comment('tracteur|porteur|fourgon…');
            $table->decimal('capacity_kg', 12, 3)->nullable();
            $table->date('inspection_due')->nullable();
            $table->date('insurance_due')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->unique(['tenant_id', 'plate_number'], 'ux_trucks_plate');
        });

        Schema::create('trailers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('plate_number', 16);
            $table->string('type')->nullable()->comment('plateau|benne|frigo|porte-conteneur…');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->unique(['tenant_id', 'plate_number'], 'ux_trailers_plate');
        });

        Schema::create('drivers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('user_id')->nullable()->comment('Compte de connexion (rôle Chauffeur)');
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('license_number', 32)->nullable();
            $table->string('license_categories', 32)->nullable();
            $table->date('license_expiry')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('user_id')->references('id')->on('users');
        });

        Schema::create('missions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('reference', 32);
            $table->uuid('shipment_id')->nullable();
            $table->uuid('truck_id')->nullable();
            $table->uuid('trailer_id')->nullable();
            $table->uuid('driver_id')->nullable();
            $table->string('type')->default('delivery')->comment('delivery|pickup|transfer');
            $table->string('status')->default('planned');
            $table->timestampTz('window_start')->nullable();
            $table->timestampTz('window_end')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->string('failure_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('shipment_id')->references('id')->on('shipments');
            $table->foreign('truck_id')->references('id')->on('trucks');
            $table->foreign('trailer_id')->references('id')->on('trailers');
            $table->foreign('driver_id')->references('id')->on('drivers');
            $table->unique(['tenant_id', 'reference'], 'ux_missions_reference');
            $table->index(['tenant_id', 'driver_id', 'status'], 'ix_missions_driver_status');
            $table->index(['tenant_id', 'status', 'window_start'], 'ix_missions_planning');
        });
        DB::statement("ALTER TABLE missions ADD CONSTRAINT ck_missions_type CHECK (type IN ('delivery','pickup','transfer'))");
        DB::statement("ALTER TABLE missions ADD CONSTRAINT ck_missions_status CHECK (status IN ('planned','in_progress','delivered','failed','cancelled'))");

        Schema::create('mission_stops', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('mission_id');
            $table->unsignedInteger('position');
            $table->string('label');
            $table->jsonb('address')->default('{}');
            $table->decimal('latitude', 9, 6)->nullable();
            $table->decimal('longitude', 9, 6)->nullable();
            $table->timestampTz('planned_at')->nullable();
            $table->timestampTz('arrived_at')->nullable();
            $table->timestampsTz();
            $table->foreign('mission_id')->references('id')->on('missions')->cascadeOnDelete();
            $table->unique(['mission_id', 'position'], 'ux_mission_stops_position');
        });

        Schema::create('proof_of_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('mission_id')->unique();
            $table->string('recipient_name');
            $table->text('signature_data')->nullable()->comment('Data URI PNG signature écran');
            $table->jsonb('photo_document_ids')->default('[]')->comment('Réfs documents type photo');
            $table->decimal('latitude', 9, 6)->nullable();
            $table->decimal('longitude', 9, 6)->nullable();
            $table->timestampTz('delivered_at');
            $table->text('remarks')->nullable();
            $table->timestampsTz();
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('mission_id')->references('id')->on('missions');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proof_of_deliveries');
        Schema::dropIfExists('mission_stops');
        Schema::dropIfExists('missions');
        Schema::dropIfExists('drivers');
        Schema::dropIfExists('trailers');
        Schema::dropIfExists('trucks');
    }
};
