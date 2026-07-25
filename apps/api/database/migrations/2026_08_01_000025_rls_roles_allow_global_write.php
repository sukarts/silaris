<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rôles système (tenant_id NULL) : le seed / la gestion doivent pouvoir les
 * écrire. La policy fail-closed (000024) impose WITH CHECK tenant_id = tenant
 * courant → l'insertion d'un rôle global (tenant_id NULL) est refusée quand
 * l'app n'est pas superutilisateur (cas Render : rôle propriétaire non-bypass).
 *
 * On assouplit UNIQUEMENT la table `roles` : WITH CHECK autorise aussi
 * tenant_id IS NULL (référentiel partagé). Les tables de DONNÉES tenant gardent
 * un WITH CHECK strict (aucune écriture cross-tenant / globale possible).
 *
 * Durcissement P1 (voir plan de maintenance) : rôle DB dédié BYPASSRLS pour le
 * seed système, permettant de re-strictifier ce WITH CHECK.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
DROP POLICY IF EXISTS tenant_isolation ON roles;
CREATE POLICY tenant_isolation ON roles
    USING (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid OR tenant_id IS NULL)
    WITH CHECK (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid OR tenant_id IS NULL);
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP POLICY IF EXISTS tenant_isolation ON roles;
CREATE POLICY tenant_isolation ON roles
    USING (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid OR tenant_id IS NULL)
    WITH CHECK (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid);
SQL);
    }
};
