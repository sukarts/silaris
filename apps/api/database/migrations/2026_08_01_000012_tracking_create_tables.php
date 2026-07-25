<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tracking — abonnements de suivi, événements normalisés DCSA (partitionnés), mapping statuts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracking_subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('subject_type')->comment('container|bl|awb');
            $table->string('subject_number', 32);
            $table->uuid('shipment_id');
            $table->string('carrier_scac', 4)->nullable()->comment('NULL = saisie manuelle');
            $table->string('status')->default('active');
            $table->timestampTz('last_polled_at')->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestampsTz();
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('shipment_id')->references('id')->on('shipments')->cascadeOnDelete();
            $table->unique(['tenant_id', 'subject_type', 'subject_number', 'shipment_id'], 'ux_tracking_subs_subject');
        });
        DB::statement("ALTER TABLE tracking_subscriptions ADD CONSTRAINT ck_tracking_subs_type CHECK (subject_type IN ('container','bl','awb'))");
        DB::statement("ALTER TABLE tracking_subscriptions ADD CONSTRAINT ck_tracking_subs_status CHECK (status IN ('active','completed','error','paused'))");
        DB::statement("CREATE INDEX ix_tracking_subs_active ON tracking_subscriptions (last_polled_at NULLS FIRST) WHERE status = 'active'");

        // Table partitionnée — hors Schema Builder (non supporté).
        DB::unprepared(<<<'SQL'
CREATE TABLE tracking_events (
    id uuid NOT NULL,
    tenant_id uuid NOT NULL REFERENCES tenants (id),
    subscription_id uuid NOT NULL REFERENCES tracking_subscriptions (id) ON DELETE CASCADE,
    shipment_id uuid REFERENCES shipments (id),
    dcsa_event_code text NOT NULL,
    raw_status text,
    location_locode char(5),
    vessel_imo text,
    occurred_at timestamptz NOT NULL,
    event_hash text NOT NULL,
    raw_payload jsonb,
    created_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (id, occurred_at)
) PARTITION BY RANGE (occurred_at);

CREATE UNIQUE INDEX ux_tracking_events_hash ON tracking_events (event_hash, occurred_at);
CREATE INDEX ix_tracking_events_shipment ON tracking_events (shipment_id, occurred_at DESC);
CREATE INDEX ix_tracking_events_subscription ON tracking_events (subscription_id, occurred_at DESC);

-- Partition par défaut + 3 premiers mois ; les suivantes créées par le scheduler (commande artisan).
CREATE TABLE tracking_events_default PARTITION OF tracking_events DEFAULT;
CREATE TABLE tracking_events_2026_08 PARTITION OF tracking_events
    FOR VALUES FROM ('2026-08-01') TO ('2026-09-01');
CREATE TABLE tracking_events_2026_09 PARTITION OF tracking_events
    FOR VALUES FROM ('2026-09-01') TO ('2026-10-01');
CREATE TABLE tracking_events_2026_10 PARTITION OF tracking_events
    FOR VALUES FROM ('2026-10-01') TO ('2026-11-01');
SQL);

        Schema::create('carrier_status_mappings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('carrier_scac', 4);
            $table->string('raw_status');
            $table->string('dcsa_event_code')->comment('Référentiel unifié : DEPA, ARRI, LOAD, DISC, GTIN, GTOT, STUF, STRP…');
            $table->timestampsTz();
            $table->unique(['carrier_scac', 'raw_status'], 'ux_carrier_status_map');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carrier_status_mappings');
        DB::unprepared('DROP TABLE IF EXISTS tracking_events CASCADE');
        Schema::dropIfExists('tracking_subscriptions');
    }
};
