<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Suivi aérien — le relevé ShipsGo remplit les heures réelles des segments,
 * l'état d'acheminement de la LTA et l'historique des mouvements de vol.
 *
 * Les segments portaient déjà des horaires prévus ; on y ajoute le réalisé,
 * sans écraser la prévision — l'écart prévu/réel est ce qui intéresse
 * l'exploitant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flight_legs', function (Blueprint $table) {
            $table->timestampTz('actual_departure_at')->nullable()->after('departure_at');
            $table->timestampTz('actual_arrival_at')->nullable()->after('arrival_at');
        });

        Schema::table('air_waybills', function (Blueprint $table) {
            $table->string('tracking_status')->nullable()->comment('booked|en_route|landed|delivered|unknown');
            $table->char('last_location_iata', 3)->nullable();
            $table->timestampTz('last_tracked_at')->nullable();
            // Référence de l'expédition chez ShipsGo : la relire ne coûte pas de
            // crédit, la réenregistrer si. On garde donc l'identifiant.
            $table->string('shipsgo_ref')->nullable();
        });
        DB::statement("ALTER TABLE air_waybills ADD CONSTRAINT ck_awb_tracking_status CHECK (tracking_status IS NULL OR tracking_status IN ('booked','en_route','landed','delivered','unknown'))");

        Schema::create('air_tracking_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('awb_id');
            $table->string('status')->comment('état normalisé : booked|en_route|landed|delivered|unknown');
            $table->string('raw_event')->nullable()->comment('code brut ShipsGo (RCS, DEP, ARR, RCF, DLV…)');
            $table->char('location_iata', 3)->nullable();
            $table->string('flight_number', 8)->nullable();
            $table->timestampTz('occurred_at');
            // Empreinte du mouvement : même relevé relu ne recrée pas d'événement.
            $table->string('event_hash')->unique();
            $table->jsonb('raw_payload')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('awb_id')->references('id')->on('air_waybills')->cascadeOnDelete();
            $table->index(['awb_id', 'occurred_at'], 'ix_air_tracking_events_awb');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('air_tracking_events');
        Schema::table('air_waybills', function (Blueprint $table) {
            DB::statement('ALTER TABLE air_waybills DROP CONSTRAINT IF EXISTS ck_awb_tracking_status');
            $table->dropColumn(['tracking_status', 'last_location_iata', 'last_tracked_at', 'shipsgo_ref']);
        });
        Schema::table('flight_legs', function (Blueprint $table) {
            $table->dropColumn(['actual_departure_at', 'actual_arrival_at']);
        });
    }
};
