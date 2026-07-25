<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Technique — audit append-only (partitionné), outbox transactionnel,
 * vues sauvegardées, widgets dashboard, tables files Laravel.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Audit partitionné, immuable par droits pg (REVOKE dans la migration RLS).
        DB::unprepared(<<<'SQL'
CREATE TABLE audit_logs (
    id uuid NOT NULL,
    tenant_id uuid NOT NULL REFERENCES tenants (id),
    user_id uuid,
    portal_account_id uuid,
    action text NOT NULL,
    entity_type text NOT NULL,
    entity_id uuid,
    old_values jsonb,
    new_values jsonb,
    ip inet,
    user_agent text,
    request_id uuid,
    occurred_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (id, occurred_at)
) PARTITION BY RANGE (occurred_at);

CREATE INDEX ix_audit_tenant_entity ON audit_logs (tenant_id, entity_type, entity_id, occurred_at DESC);
CREATE INDEX ix_audit_tenant_user ON audit_logs (tenant_id, user_id, occurred_at DESC);

CREATE TABLE audit_logs_default PARTITION OF audit_logs DEFAULT;
CREATE TABLE audit_logs_2026_08 PARTITION OF audit_logs
    FOR VALUES FROM ('2026-08-01') TO ('2026-09-01');
CREATE TABLE audit_logs_2026_09 PARTITION OF audit_logs
    FOR VALUES FROM ('2026-09-01') TO ('2026-10-01');
CREATE TABLE audit_logs_2026_10 PARTITION OF audit_logs
    FOR VALUES FROM ('2026-10-01') TO ('2026-11-01');
SQL);

        Schema::create('outbox_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('aggregate_type');
            $table->uuid('aggregate_id');
            $table->string('event_type');
            $table->jsonb('payload');
            $table->timestampTz('occurred_at')->useCurrent();
            $table->timestampTz('published_at')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->foreign('tenant_id')->references('id')->on('tenants');
        });
        DB::statement('CREATE INDEX ix_outbox_unpublished ON outbox_events (occurred_at) WHERE published_at IS NULL');

        Schema::create('saved_views', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('user_id');
            $table->string('resource')->comment('shipments|quotes|invoices…');
            $table->string('name');
            $table->jsonb('filters')->default('{}');
            $table->jsonb('columns')->default('[]');
            $table->string('sort')->nullable();
            $table->boolean('is_shared')->default(false)->comment('Visible par toute l\'agence');
            $table->timestampsTz();
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['tenant_id', 'user_id', 'resource'], 'ix_saved_views_user');
        });

        Schema::create('dashboard_widgets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('user_id');
            $table->string('widget_key')->comment('kpi_active_shipments|chart_volumes|alerts…');
            $table->unsignedInteger('position');
            $table->jsonb('config')->default('{}');
            $table->timestampsTz();
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['user_id', 'widget_key'], 'ux_dashboard_widgets_user_key');
        });

        // Tables framework Laravel (queues DB fallback, batches, cache locks).
        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        Schema::create('job_batches', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->integer('total_jobs');
            $table->integer('pending_jobs');
            $table->integer('failed_jobs');
            $table->longText('failed_job_ids');
            $table->mediumText('options')->nullable();
            $table->integer('cancelled_at')->nullable();
            $table->integer('created_at');
            $table->integer('finished_at')->nullable();
        });

        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestampTz('failed_at')->useCurrent();
        });

        Schema::create('cache', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration');
        });

        Schema::create('cache_locks', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('owner');
            $table->integer('expiration');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cache_locks');
        Schema::dropIfExists('cache');
        Schema::dropIfExists('failed_jobs');
        Schema::dropIfExists('job_batches');
        Schema::dropIfExists('jobs');
        Schema::dropIfExists('dashboard_widgets');
        Schema::dropIfExists('saved_views');
        Schema::dropIfExists('outbox_events');
        DB::unprepared('DROP TABLE IF EXISTS audit_logs CASCADE');
    }
};
