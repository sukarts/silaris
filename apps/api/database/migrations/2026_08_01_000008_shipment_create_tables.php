<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Shipment — dossiers de transit, timeline, tâches, segments multimodaux, marchandises.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('reference', 32);
            $table->uuid('client_id');
            $table->uuid('branch_id');
            $table->uuid('company_id');
            $table->uuid('agent_id');
            $table->uuid('supervisor_id')->nullable();
            $table->string('direction');
            $table->string('mode');
            $table->string('status')->comment('Clé de l\'étape workflow courante');
            $table->uuid('workflow_definition_id')->comment('Figé à la création');
            $table->char('incoterm_code', 3);
            $table->char('origin_locode', 5);
            $table->char('destination_locode', 5);
            $table->string('priority')->default('normal');
            $table->timestampTz('etd')->nullable();
            $table->timestampTz('eta')->nullable();
            $table->timestampTz('atd')->nullable();
            $table->timestampTz('ata')->nullable();
            $table->timestampTz('eta_initial')->nullable()->comment('Première ETA — mesure de dérive');
            $table->decimal('estimated_cost', 14, 2)->nullable();
            $table->decimal('estimated_revenue', 14, 2)->nullable();
            $table->char('currency_code', 3)->nullable();
            $table->uuid('quote_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->uuid('closed_by')->nullable();
            $table->boolean('is_archived')->default(false);
            $table->timestampsTz();
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('client_id')->references('id')->on('parties');
            $table->foreign('branch_id')->references('id')->on('branches');
            $table->foreign('company_id')->references('id')->on('companies');
            $table->foreign('agent_id')->references('id')->on('users');
            $table->foreign('supervisor_id')->references('id')->on('users');
            $table->foreign('workflow_definition_id')->references('id')->on('workflow_definitions');
            $table->foreign('incoterm_code')->references('code')->on('incoterms');
            $table->foreign('quote_id')->references('id')->on('quotes');
            $table->foreign('closed_by')->references('id')->on('users');
            $table->unique(['tenant_id', 'reference'], 'ux_shipments_tenant_reference');
            $table->index(['tenant_id', 'status', 'eta'], 'ix_shipments_tenant_status_eta');
            $table->index(['tenant_id', 'client_id'], 'ix_shipments_tenant_client');
        });
        DB::statement("ALTER TABLE shipments ADD CONSTRAINT ck_shipments_direction CHECK (direction IN ('import','export'))");
        DB::statement("ALTER TABLE shipments ADD CONSTRAINT ck_shipments_mode CHECK (mode IN ('sea_fcl','sea_lcl','air','road','multimodal'))");
        DB::statement("ALTER TABLE shipments ADD CONSTRAINT ck_shipments_priority CHECK (priority IN ('low','normal','high','critical'))");
        DB::statement('CREATE INDEX ix_shipments_tenant_agent_open ON shipments (tenant_id, agent_id) WHERE closed_at IS NULL');

        // Garde-fou : client_id doit référencer une partie de type client.
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION check_shipment_client() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM parties WHERE id = NEW.client_id AND type = 'client') THEN
        RAISE EXCEPTION 'shipments.client_id % ne référence pas une partie de type client', NEW.client_id;
    END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER trg_shipments_client_type
    BEFORE INSERT OR UPDATE OF client_id ON shipments
    FOR EACH ROW EXECUTE FUNCTION check_shipment_client();
SQL);

        Schema::create('shipment_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('shipment_id');
            $table->string('type');
            $table->string('title');
            $table->jsonb('payload')->default('{}');
            $table->string('source')->default('internal');
            $table->uuid('actor_id')->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at')->useCurrent();
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('shipment_id')->references('id')->on('shipments')->cascadeOnDelete();
            $table->foreign('actor_id')->references('id')->on('users');
            $table->index(['shipment_id', 'occurred_at'], 'ix_shipment_events_shipment_date');
        });
        DB::statement("ALTER TABLE shipment_events ADD CONSTRAINT ck_shipment_events_type CHECK (type IN ('status_change','tracking','document','comment','task','billing','system'))");
        DB::statement("ALTER TABLE shipment_events ADD CONSTRAINT ck_shipment_events_source CHECK (source IN ('internal','carrier_api','odoo','portal','system'))");

        Schema::create('shipment_tasks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('shipment_id');
            $table->string('title');
            $table->text('description')->nullable();
            $table->uuid('assignee_id')->nullable();
            $table->timestampTz('due_at')->nullable();
            $table->string('status')->default('open');
            $table->boolean('requires_approval')->default(false);
            $table->uuid('approved_by')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('shipment_id')->references('id')->on('shipments')->cascadeOnDelete();
            $table->foreign('assignee_id')->references('id')->on('users');
            $table->foreign('approved_by')->references('id')->on('users');
            $table->index(['tenant_id', 'assignee_id', 'status'], 'ix_shipment_tasks_assignee');
        });
        DB::statement("ALTER TABLE shipment_tasks ADD CONSTRAINT ck_shipment_tasks_status CHECK (status IN ('open','in_progress','done','cancelled'))");

        Schema::create('shipment_comments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('shipment_id');
            $table->text('body');
            $table->string('visibility')->default('internal')->comment('internal | client (visible portail)');
            $table->uuid('author_id')->nullable()->comment('NULL si auteur portail');
            $table->uuid('portal_author_id')->nullable();
            $table->timestampsTz();
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('shipment_id')->references('id')->on('shipments')->cascadeOnDelete();
            $table->foreign('author_id')->references('id')->on('users');
            $table->foreign('portal_author_id')->references('id')->on('portal_accounts');
            $table->index(['shipment_id', 'created_at'], 'ix_shipment_comments_shipment');
        });
        DB::statement("ALTER TABLE shipment_comments ADD CONSTRAINT ck_shipment_comments_visibility CHECK (visibility IN ('internal','client'))");
        DB::statement('ALTER TABLE shipment_comments ADD CONSTRAINT ck_shipment_comments_author CHECK (author_id IS NOT NULL OR portal_author_id IS NOT NULL)');

        Schema::create('transport_segments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('shipment_id');
            $table->unsignedInteger('position');
            $table->string('mode')->comment('sea|air|road');
            $table->char('origin_locode', 5);
            $table->char('destination_locode', 5);
            $table->timestampTz('etd')->nullable();
            $table->timestampTz('eta')->nullable();
            $table->timestampTz('atd')->nullable();
            $table->timestampTz('ata')->nullable();
            $table->timestampsTz();
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('shipment_id')->references('id')->on('shipments')->cascadeOnDelete();
            $table->unique(['shipment_id', 'position'], 'ux_transport_segments_position');
        });
        DB::statement("ALTER TABLE transport_segments ADD CONSTRAINT ck_transport_segments_mode CHECK (mode IN ('sea','air','road'))");

        Schema::create('cargo_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('shipment_id');
            $table->uuid('goods_type_id')->nullable();
            $table->string('description');
            $table->unsignedInteger('packages_count')->nullable();
            $table->string('package_type')->nullable()->comment('carton|palette|caisse|vrac…');
            $table->decimal('gross_weight_kg', 12, 3)->nullable();
            $table->decimal('volume_m3', 12, 3)->nullable();
            $table->string('un_number', 8)->nullable()->comment('Si marchandise dangereuse');
            $table->string('hs_code', 12)->nullable();
            $table->decimal('declared_value', 14, 2)->nullable();
            $table->char('currency_code', 3)->nullable();
            $table->timestampsTz();
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('shipment_id')->references('id')->on('shipments')->cascadeOnDelete();
            $table->foreign('goods_type_id')->references('id')->on('goods_types');
            $table->index('shipment_id', 'ix_cargo_items_shipment');
        });

        // FK différée depuis complaints (créée avant shipments)
        Schema::table('complaints', function (Blueprint $table) {
            $table->foreign('shipment_id')->references('id')->on('shipments');
        });
    }

    public function down(): void
    {
        Schema::table('complaints', function (Blueprint $table) {
            $table->dropForeign(['shipment_id']);
        });
        Schema::dropIfExists('cargo_items');
        Schema::dropIfExists('transport_segments');
        Schema::dropIfExists('shipment_comments');
        Schema::dropIfExists('shipment_tasks');
        Schema::dropIfExists('shipment_events');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_shipments_client_type ON shipments; DROP FUNCTION IF EXISTS check_shipment_client()');
        Schema::dropIfExists('shipments');
    }
};
