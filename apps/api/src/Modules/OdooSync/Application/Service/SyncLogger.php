<?php

declare(strict_types=1);

namespace Silaris\Modules\OdooSync\Application\Service;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Silaris\Modules\Shared\Infrastructure\Tenancy\TenantContext;

final readonly class SyncLogger
{
    public function __construct(private TenantContext $tenant) {}

    public function log(string $entityType, ?string $entityId, string $direction, string $status, ?array $payload = null, ?string $error = null, int $attempts = 0, ?int $durationMs = null): void
    {
        DB::table('odoo_sync_logs')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $this->tenant->id(),
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'direction' => $direction,
            'status' => $status,
            'payload' => $payload !== null ? json_encode($payload) : null,
            'error' => $error !== null ? substr($error, 0, 4000) : null,
            'attempts' => $attempts,
            'duration_ms' => $durationMs,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
