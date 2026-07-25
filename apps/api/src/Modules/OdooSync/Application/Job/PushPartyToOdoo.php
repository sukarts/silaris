<?php

declare(strict_types=1);

namespace Silaris\Modules\OdooSync\Application\Job;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Silaris\Modules\Crm\Infrastructure\Persistence\Model\PartyModel;
use Silaris\Modules\OdooSync\Application\Service\EntityMap;
use Silaris\Modules\OdooSync\Application\Service\SyncLogger;
use Silaris\Modules\OdooSync\Application\Translator\PartyTranslator;
use Silaris\Modules\OdooSync\Infrastructure\Transport\OdooClientFactory;
use Silaris\Modules\OdooSync\Infrastructure\Transport\OdooRequestFailed;
use Silaris\Modules\Shared\Infrastructure\Tenancy\TenantContext;
use Throwable;

class PushPartyToOdoo implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 5;

    /** @return list<int> Backoff exponentiel (mode dégradé Odoo indisponible). */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function __construct(
        public readonly string $tenantId,
        public readonly string $partyId,
    ) {
        $this->onQueue('odoo');
    }

    public function handle(
        TenantContext $tenant,
        OdooClientFactory $factory,
        PartyTranslator $translator,
        EntityMap $map,
        SyncLogger $logger,
    ): void {
        $tenant->set($this->tenantId);
        if (! $factory->isConfigured()) {
            return;
        }

        $start = hrtime(true);
        $party = PartyModel::with(['addresses', 'contacts'])->findOrFail($this->partyId);
        $payload = $translator->toOdoo($party);
        $checksum = $translator->checksum($party);

        try {
            $client = $factory->forCurrentTenant();
            $existingId = $map->odooIdOf('party', $party->id);

            if ($existingId !== null) {
                $client->execute('res.partner', 'write', [[$existingId], $payload]);
                $odooId = $existingId;
            } else {
                $odooId = (int) $client->execute('res.partner', 'create', [$payload]);
            }

            $map->remember('party', $party->id, 'res.partner', $odooId, 'push', $checksum);
            $party->updateQuietly(['odoo_id' => $odooId]);
            $logger->log('party', $party->id, 'push', 'success', $payload, attempts: $this->attempts(), durationMs: (int) ((hrtime(true) - $start) / 1e6));
        } catch (OdooRequestFailed $e) {
            // Erreur métier : inutile de retenter — dead letter immédiat.
            $logger->log('party', $party->id, 'push', 'dead_letter', $payload, $e->getMessage(), $this->attempts());
            $this->fail($e);
        } catch (Throwable $e) {
            $logger->log('party', $party->id, 'push', 'failed', $payload, $e->getMessage(), $this->attempts());
            throw $e; // retry backoff
        }
    }

    public function failed(Throwable $exception): void
    {
        app(TenantContext::class)->set($this->tenantId);
        app(SyncLogger::class)->log('party', $this->partyId, 'push', 'dead_letter', null, $exception->getMessage(), $this->tries);
    }
}
