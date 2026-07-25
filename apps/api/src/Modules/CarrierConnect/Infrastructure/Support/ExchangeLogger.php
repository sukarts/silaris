<?php

declare(strict_types=1);

namespace Silaris\Modules\CarrierConnect\Infrastructure\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Silaris\Modules\Shared\Infrastructure\Tenancy\TenantContext;

/** Journalise chaque échange API compagnie (audit + diagnostic quotas). */
final readonly class ExchangeLogger
{
    public function __construct(private TenantContext $tenant) {}

    public function log(string $scac, string $operation, string $subjectNumber, bool $success, ?int $httpStatus, int $durationMs, ?string $error = null): void
    {
        DB::table('carrier_exchange_logs')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $this->tenant->id(),
            'carrier_scac' => $scac,
            'operation' => $operation,
            'subject_number' => $subjectNumber,
            'http_status' => $httpStatus,
            'duration_ms' => $durationMs,
            'success' => $success,
            'error' => $error !== null ? substr($error, 0, 2000) : null,
        ]);
    }
}
