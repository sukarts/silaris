<?php

declare(strict_types=1);

namespace Silaris\Modules\OdooSync\Interface\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Silaris\Modules\OdooSync\Infrastructure\Transport\OdooClient;
use Silaris\Modules\Shared\Infrastructure\Tenancy\TenantContext;

class OdooController
{
    public function __construct(private readonly TenantContext $tenant) {}

    /** GET /v1/odoo/status — santé, backlog, derniers échanges. */
    public function status(): JsonResponse
    {
        $connection = DB::table('odoo_connections')->where('tenant_id', $this->tenant->id())->first(
            ['base_url', 'database', 'username', 'odoo_version', 'is_active', 'health_status', 'last_healthcheck_at'],
        );

        return response()->json([
            'connection' => $connection,
            'health' => DB::table('v_odoo_sync_health')->where('tenant_id', $this->tenant->id())->get(),
            'dead_letters' => DB::table('odoo_sync_logs')
                ->where('tenant_id', $this->tenant->id())
                ->where('status', 'dead_letter')
                ->orderByDesc('created_at')->limit(10)
                ->get(['id', 'entity_type', 'entity_id', 'error', 'created_at']),
            'recent' => DB::table('odoo_sync_logs')
                ->where('tenant_id', $this->tenant->id())
                ->orderByDesc('created_at')->limit(20)
                ->get(['entity_type', 'entity_id', 'direction', 'status', 'attempts', 'duration_ms', 'created_at']),
        ]);
    }

    /** PUT /v1/odoo/config — configuration de la connexion (credentials chiffrés). */
    public function configure(Request $request): JsonResponse
    {
        $data = $request->validate([
            'base_url' => ['required', 'url', 'starts_with:https://,http://localhost'],
            'database' => ['required', 'string', 'max:128'],
            'username' => ['required', 'string', 'max:128'],
            'api_key' => ['required', 'string', 'max:256'],
            'odoo_version' => ['sometimes', 'in:16,17,18'],
        ]);

        // Test de connexion avant enregistrement.
        $client = new OdooClient(rtrim($data['base_url'], '/'), $data['database'], $data['username'], $data['api_key']);
        $healthy = $client->healthcheck();

        DB::table('odoo_connections')->updateOrInsert(
            ['tenant_id' => $this->tenant->id()],
            [
                'id' => DB::table('odoo_connections')->where('tenant_id', $this->tenant->id())->value('id') ?? (string) Str::uuid7(),
                'base_url' => rtrim($data['base_url'], '/'),
                'database' => $data['database'],
                'username' => $data['username'],
                'api_key' => Crypt::encryptString($data['api_key']),
                'odoo_version' => $data['odoo_version'] ?? '17',
                'is_active' => $healthy,
                'health_status' => $healthy ? 'healthy' : 'down',
                'last_healthcheck_at' => now(),
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );

        return response()->json(['configured' => true, 'healthy' => $healthy], $healthy ? 200 : 202);
    }
}
