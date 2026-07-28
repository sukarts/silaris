<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Silaris\Modules\Shipment\Application\Service\StandardWorkflowInstaller;

/**
 * Rattrapage : les tenants provisionnés avant l'installation automatique du
 * workflow standard n'en ont aucun. Leur premier dossier échouait donc à la
 * mise en service, sans message exploitable.
 *
 * La migration pose le workflow standard pour tout tenant qui en manque ; ceux
 * qui en ont déjà un ne sont pas touchés.
 */
return new class extends Migration
{
    public function up(): void
    {
        $installer = app(StandardWorkflowInstaller::class);

        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            // Les policies RLS gouvernent l'écriture : le contexte doit désigner
            // le tenant servi, pas celui de la session courante.
            DB::statement("SELECT set_config('app.tenant_id', ?, false)", [$tenantId]);
            $installer->installFor((string) $tenantId);
        }

        DB::statement("SELECT set_config('app.tenant_id', '', false)");
    }

    public function down(): void
    {
        // Retirer un workflow retirerait les dossiers qui s'y rattachent :
        // le rattrapage n'est pas réversible.
    }
};
