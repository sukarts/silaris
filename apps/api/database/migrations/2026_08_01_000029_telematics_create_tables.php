<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Télématique — suivi du dernier kilomètre : une balise posée sur le véhicule
 * émet ses positions, rattachées à la mission routière en cours.
 *
 * Agnostique du matériel : toute balise (Teltonika, Queclink…), plateforme
 * (Traccar, Flespi) ou application mobile capable d'un POST HTTP alimente
 * l'ingestion. L'authentification se fait par clé de balise (hachée), jamais
 * par session utilisateur — un traceur n'a pas d'utilisateur.
 *
 * Vie privée : tracer un véhicule revient à tracer son conducteur. Les
 * positions sont donc conservées de façon bornée (purge) et l'exposition
 * publique est limitée aux missions en cours, à précision réduite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracking_devices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('identifier', 64)->comment('IMEI, n° de série ou identifiant plateforme');
            $table->string('label');
            $table->string('kind')->default('beacon')->comment('beacon|phone|gateway');
            $table->string('api_key_hash')->comment('Clé d\'ingestion hachée — affichée une seule fois');
            $table->string('key_prefix', 12)->comment('Préfixe affichable pour identifier la clé');
            $table->uuid('truck_id')->nullable()->comment('Véhicule porteur — détermine la mission courante');
            $table->boolean('is_active')->default(true);
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('truck_id')->references('id')->on('trucks')->nullOnDelete();
            $table->unique(['tenant_id', 'identifier'], 'ux_tracking_devices_identifier');
            $table->index('key_prefix', 'ix_tracking_devices_prefix');
        });

        Schema::create('device_positions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('device_id');
            $table->uuid('mission_id')->nullable()->comment('Mission en cours au moment de l\'émission');
            $table->decimal('latitude', 9, 6);
            $table->decimal('longitude', 9, 6);
            $table->decimal('speed_kmh', 6, 2)->nullable();
            $table->unsignedSmallInteger('heading')->nullable()->comment('Cap 0-359°');
            $table->timestampTz('recorded_at')->comment('Horodatage de la balise');
            $table->timestampTz('received_at')->useCurrent();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->foreign('device_id')->references('id')->on('tracking_devices')->cascadeOnDelete();
            $table->foreign('mission_id')->references('id')->on('missions')->nullOnDelete();
            // Rejeu hors couverture : la balise renvoie son tampon, on ignore les doublons.
            $table->unique(['device_id', 'recorded_at'], 'ux_device_positions_point');
            $table->index(['mission_id', 'recorded_at'], 'ix_device_positions_mission');
        });

        foreach (['tracking_devices', 'device_positions'] as $table) {
            DB::unprepared(sprintf(<<<'SQL'
ALTER TABLE %1$s ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON %1$s
    USING (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid OR tenant_id IS NULL)
    WITH CHECK (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid);
SQL, $table));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('device_positions');
        Schema::dropIfExists('tracking_devices');
    }
};
