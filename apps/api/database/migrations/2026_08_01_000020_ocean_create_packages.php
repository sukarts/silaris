<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Colis LCL — unité de suivi individuelle, étiquetable (QR), scannée à chaque jalon :
 * réception entrepôt → empotage (lien conteneur) → dépotage → remise au destinataire.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('packages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('shipment_id');
            $table->string('reference', 32)->comment('Ex. TAL-2026-00126-C0001 — imprimée sur l\'étiquette');
            $table->string('description')->nullable();
            $table->decimal('weight_kg', 12, 3)->nullable();
            $table->decimal('volume_m3', 12, 3)->nullable();
            $table->string('status')->default('received');
            $table->uuid('container_id')->nullable()->comment('Posé à l\'empotage');
            $table->uuid('consolidation_id')->nullable();
            $table->timestampTz('received_at')->nullable();
            $table->uuid('received_by')->nullable();
            $table->timestampTz('stuffed_at')->nullable();
            $table->timestampTz('unstuffed_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->string('delivered_to')->nullable()->comment('Nom du réceptionnaire à la remise');
            $table->timestampsTz();
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('shipment_id')->references('id')->on('shipments');
            $table->foreign('container_id')->references('id')->on('containers');
            $table->foreign('consolidation_id')->references('id')->on('consolidations');
            $table->foreign('received_by')->references('id')->on('users');
            $table->unique(['tenant_id', 'reference'], 'ux_packages_reference');
            $table->index(['shipment_id'], 'ix_packages_shipment');
            $table->index(['container_id'], 'ix_packages_container');
        });
        DB::statement("ALTER TABLE packages ADD CONSTRAINT ck_packages_status CHECK (status IN ('received','stuffed','unstuffed','delivered'))");

        // RLS comme les autres tables tenant
        DB::unprepared(<<<'SQL'
ALTER TABLE packages ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON packages
    USING (tenant_id = current_setting('app.tenant_id')::uuid)
    WITH CHECK (tenant_id = current_setting('app.tenant_id')::uuid);
GRANT SELECT, INSERT, UPDATE, DELETE ON packages TO silaris_app;
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('packages');
    }
};
