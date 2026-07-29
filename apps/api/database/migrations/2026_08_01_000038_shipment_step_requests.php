<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Demandes d'avancement d'étape.
 *
 * L'agent transit tient le dossier au quotidien, mais chaque franchissement
 * d'étape engage : un booking confirmé réserve du fret, un départ déclaré
 * déclenche des délais, une clôture ferme la facturation. Le responsable
 * exploitation valide donc le passage, l'agent le prépare.
 *
 * La demande est conservée après décision : elle dit qui a proposé, qui a
 * tranché et quand — ce qu'un simple changement d'étape ne raconterait pas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipment_step_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('shipment_id');
            $table->string('from_step', 64);
            $table->string('to_step', 64);
            $table->string('status')->default('pending')->comment('pending|approved|rejected');
            $table->uuid('requested_by');
            $table->timestampTz('requested_at');
            $table->uuid('decided_by')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('shipment_id')->references('id')->on('shipments')->cascadeOnDelete();
            $table->foreign('requested_by')->references('id')->on('users');
            $table->foreign('decided_by')->references('id')->on('users');
            $table->index(['tenant_id', 'status'], 'ix_step_requests_status');
        });

        // Une seule demande en attente par dossier : sinon deux agents
        // proposeraient deux étapes différentes et la validation deviendrait
        // ambiguë.
        DB::statement(<<<'SQL'
CREATE UNIQUE INDEX ux_step_requests_pending ON shipment_step_requests (shipment_id)
    WHERE status = 'pending'
SQL);

        DB::unprepared(<<<'SQL'
ALTER TABLE shipment_step_requests ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON shipment_step_requests
    USING (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid OR tenant_id IS NULL)
    WITH CHECK (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid);
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_step_requests');
    }
};
