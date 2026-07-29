<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Services et chefs de service.
 *
 * Une maison de transit se découpe par métier — import, export, livraison,
 * aérien — chacun tenu par un chef qui répond de ses dossiers devant le
 * responsable exploitation. Le chef valide les passages d'étape de ses agents ;
 * le responsable garde la main sur l'ensemble.
 *
 * Le service est propre au transitaire : la liste ci-dessous n'est qu'un
 * amorçage, chacun crée les siens.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('code', 16);
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->unique(['tenant_id', 'code'], 'ux_services_code');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->uuid('service_id')->nullable()
                ->comment('Service de rattachement — détermine qui valide ses dossiers');
            $table->foreign('service_id')->references('id')->on('services')->nullOnDelete();
        });

        // Le dossier porte son service : c'est lui qui désigne le chef
        // compétent, indépendamment de l'agent qui le tient à un instant donné.
        Schema::table('shipments', function (Blueprint $table): void {
            $table->uuid('service_id')->nullable();
            $table->foreign('service_id')->references('id')->on('services')->nullOnDelete();
            $table->index(['tenant_id', 'service_id'], 'ix_shipments_service');
        });

        DB::unprepared(<<<'SQL'
ALTER TABLE services ENABLE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON services
    USING (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid OR tenant_id IS NULL)
    WITH CHECK (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid);
SQL);

        // Amorçage : les services usuels du transit, que chacun ajuste ensuite.
        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            foreach ([
                ['IMP', 'Import'],
                ['EXP', 'Export'],
                ['LIV', 'Livraison'],
                ['AER', 'Aérien'],
            ] as [$code, $name]) {
                DB::table('services')->insert([
                    'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId,
                    'code' => $code, 'name' => $name, 'is_active' => true,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table): void {
            $table->dropForeign(['service_id']);
            $table->dropIndex('ix_shipments_service');
            $table->dropColumn('service_id');
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['service_id']);
            $table->dropColumn('service_id');
        });
        Schema::dropIfExists('services');
    }
};
