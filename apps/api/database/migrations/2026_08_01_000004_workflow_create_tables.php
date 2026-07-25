<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Workflow configurable + séquences de numérotation sans trou.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_definitions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('name');
            $table->string('transport_mode')->comment('sea_fcl|sea_lcl|air|road|multimodal|any');
            $table->string('direction')->comment('import|export|any');
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->index(['tenant_id', 'transport_mode', 'direction'], 'ix_workflow_defs_selector');
        });
        DB::statement("ALTER TABLE workflow_definitions ADD CONSTRAINT ck_wfd_mode CHECK (transport_mode IN ('sea_fcl','sea_lcl','air','road','multimodal','any'))");
        DB::statement("ALTER TABLE workflow_definitions ADD CONSTRAINT ck_wfd_direction CHECK (direction IN ('import','export','any'))");

        Schema::create('workflow_steps', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workflow_definition_id');
            $table->string('key')->comment('creation|booking|departure|transit|arrival|customs|delivery|closure|custom_*');
            $table->string('label');
            $table->unsignedInteger('position');
            $table->jsonb('transitions')->default('[]')->comment('Clés des étapes suivantes autorisées');
            $table->jsonb('conditions')->default('{}')->comment('required_documents[], requires_approval, auto_advance_on[]');
            $table->jsonb('actions')->default('{}')->comment('notifications à déclencher, tâches à créer');
            $table->timestampsTz();
            $table->foreign('workflow_definition_id')->references('id')->on('workflow_definitions')->cascadeOnDelete();
            $table->unique(['workflow_definition_id', 'key'], 'ux_workflow_steps_key');
            $table->unique(['workflow_definition_id', 'position'], 'ux_workflow_steps_position');
        });

        Schema::create('sequences', function (Blueprint $table) {
            $table->uuid('tenant_id');
            $table->string('scope')->comment('shipment:BRANCH:YEAR | invoice:COMPANY:TYPE | quote:YEAR');
            $table->bigInteger('last_value')->default(0);
            $table->primary(['tenant_id', 'scope']);
            $table->foreign('tenant_id')->references('id')->on('tenants');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sequences');
        Schema::dropIfExists('workflow_steps');
        Schema::dropIfExists('workflow_definitions');
    }
};
