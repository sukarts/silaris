<?php

declare(strict_types=1);

namespace Silaris\Modules\CarrierConnect\Infrastructure\Support;

use Illuminate\Support\Facades\DB;
use Silaris\Modules\Shared\Infrastructure\Tenancy\TenantContext;

/**
 * Circuit breaker par compagnie+tenant — persisté (carrier_api_credentials.circuit_open_until)
 * pour survivre aux redémarrages de workers.
 */
final readonly class CircuitBreaker
{
    private const OPEN_MINUTES = 15;

    private const FAILURE_THRESHOLD = 5;

    public function __construct(private TenantContext $tenant) {}

    public function isOpen(string $scac): bool
    {
        $until = DB::table('carrier_api_credentials')
            ->where('tenant_id', $this->tenant->id())
            ->where('carrier_scac', $scac)
            ->value('circuit_open_until');

        return $until !== null && now()->lt($until);
    }

    public function recordSuccess(string $scac): void
    {
        DB::table('carrier_api_credentials')
            ->where('tenant_id', $this->tenant->id())
            ->where('carrier_scac', $scac)
            ->update(['last_success_at' => now(), 'circuit_open_until' => null]);
    }

    public function recordFailure(string $scac, int $consecutiveFailures): void
    {
        if ($consecutiveFailures >= self::FAILURE_THRESHOLD) {
            DB::table('carrier_api_credentials')
                ->where('tenant_id', $this->tenant->id())
                ->where('carrier_scac', $scac)
                ->update(['circuit_open_until' => now()->addMinutes(self::OPEN_MINUTES)]);
        }
    }
}
