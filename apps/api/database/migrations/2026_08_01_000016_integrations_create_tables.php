<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Intégrations — connexion Odoo, mapping d'entités, journaux de sync,
 * credentials connecteurs compagnies, webhooks sortants.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('odoo_connections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->unique();
            $table->string('base_url');
            $table->string('database');
            $table->string('username');
            $table->text('api_key')->comment('Chiffré applicativement (cast encrypted)');
            $table->string('odoo_version', 8)->default('17');
            $table->jsonb('entity_config')->default('{}')->comment('Activation + mapping par entité');
            $table->boolean('is_active')->default(false);
            $table->timestampTz('last_healthcheck_at')->nullable();
            $table->string('health_status')->default('unknown');
            $table->timestampsTz();
            $table->foreign('tenant_id')->references('id')->on('tenants');
        });
        DB::statement("ALTER TABLE odoo_connections ADD CONSTRAINT ck_odoo_health CHECK (health_status IN ('unknown','healthy','degraded','down'))");

        Schema::create('odoo_entity_maps', function (Blueprint $table) {
            $table->uuid('tenant_id');
            $table->string('entity_type')->comment('party|product|quote|invoice|tax|currency');
            $table->uuid('silaris_id');
            $table->string('odoo_model', 64);
            $table->integer('odoo_id');
            $table->timestampTz('last_pushed_at')->nullable();
            $table->timestampTz('last_pulled_at')->nullable();
            $table->string('checksum', 64)->nullable()->comment('Détection modification concurrente');
            $table->timestampsTz();
            $table->primary(['tenant_id', 'entity_type', 'silaris_id']);
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->unique(['tenant_id', 'odoo_model', 'odoo_id'], 'ux_odoo_map_reverse');
        });

        Schema::create('odoo_sync_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('entity_type');
            $table->uuid('entity_id')->nullable();
            $table->string('direction');
            $table->string('status');
            $table->jsonb('payload')->nullable();
            $table->text('error')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestampsTz();
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->index(['tenant_id', 'status', 'created_at'], 'ix_odoo_sync_logs_status');
            $table->index(['tenant_id', 'entity_type', 'entity_id'], 'ix_odoo_sync_logs_entity');
        });
        DB::statement("ALTER TABLE odoo_sync_logs ADD CONSTRAINT ck_odoo_sync_direction CHECK (direction IN ('push','pull'))");
        DB::statement("ALTER TABLE odoo_sync_logs ADD CONSTRAINT ck_odoo_sync_status CHECK (status IN ('pending','success','failed','conflict','dead_letter'))");

        Schema::create('carrier_api_credentials', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('carrier_scac', 4);
            $table->jsonb('credentials')->comment('Chiffré applicativement — structure propre à chaque connecteur');
            $table->boolean('is_active')->default(true);
            $table->timestampTz('last_success_at')->nullable();
            $table->timestampTz('circuit_open_until')->nullable()->comment('Circuit breaker');
            $table->timestampsTz();
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->unique(['tenant_id', 'carrier_scac'], 'ux_carrier_credentials');
        });

        Schema::create('carrier_exchange_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('carrier_scac', 4);
            $table->string('operation')->comment('track_container|track_bl|schedule');
            $table->string('subject_number', 32)->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->boolean('success');
            $table->text('error')->nullable();
            $table->jsonb('request_meta')->nullable();
            $table->timestampTz('occurred_at')->useCurrent();
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->index(['tenant_id', 'carrier_scac', 'occurred_at'], 'ix_carrier_logs_scac');
        });

        Schema::create('webhook_endpoints', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('url');
            $table->text('secret')->comment('Chiffré — signature HMAC des payloads');
            $table->jsonb('subscribed_events')->default('[]');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->foreign('tenant_id')->references('id')->on('tenants');
        });

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('webhook_endpoint_id');
            $table->string('event_type');
            $table->jsonb('payload');
            $table->string('status')->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampsTz();
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('webhook_endpoint_id')->references('id')->on('webhook_endpoints')->cascadeOnDelete();
            $table->index(['tenant_id', 'status'], 'ix_webhook_deliveries_status');
        });
        DB::statement("ALTER TABLE webhook_deliveries ADD CONSTRAINT ck_webhook_deliveries_status CHECK (status IN ('pending','delivered','failed','exhausted'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_endpoints');
        Schema::dropIfExists('carrier_exchange_logs');
        Schema::dropIfExists('carrier_api_credentials');
        Schema::dropIfExists('odoo_sync_logs');
        Schema::dropIfExists('odoo_entity_maps');
        Schema::dropIfExists('odoo_connections');
    }
};
