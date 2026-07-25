<?php

declare(strict_types=1);

namespace Silaris\Modules\OdooSync\Application\Job;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Silaris\Modules\Billing\Infrastructure\Persistence\Model\InvoiceModel;
use Silaris\Modules\Crm\Infrastructure\Persistence\Model\PartyModel;
use Silaris\Modules\OdooSync\Application\Service\EntityMap;
use Silaris\Modules\OdooSync\Application\Service\SyncLogger;
use Silaris\Modules\OdooSync\Application\Translator\InvoiceTranslator;
use Silaris\Modules\OdooSync\Application\Translator\PartyTranslator;
use Silaris\Modules\OdooSync\Infrastructure\Transport\OdooClientFactory;
use Silaris\Modules\OdooSync\Infrastructure\Transport\OdooRequestFailed;
use Silaris\Modules\Shared\Infrastructure\Tenancy\TenantContext;
use Throwable;

class PushInvoiceToOdoo implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 5;

    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function __construct(
        public readonly string $tenantId,
        public readonly string $invoiceId,
    ) {
        $this->onQueue('odoo');
    }

    public function handle(
        TenantContext $tenant,
        OdooClientFactory $factory,
        InvoiceTranslator $translator,
        PartyTranslator $partyTranslator,
        EntityMap $map,
        SyncLogger $logger,
    ): void {
        $tenant->set($this->tenantId);
        if (! $factory->isConfigured()) {
            return;
        }

        $start = hrtime(true);
        $invoice = InvoiceModel::with(['lines', 'party', 'shipment'])->findOrFail($this->invoiceId);

        try {
            $client = $factory->forCurrentTenant();

            // 1. Le client doit exister côté Odoo (push à la volée si absent).
            $partnerId = $map->odooIdOf('party', $invoice->party_id);
            if ($partnerId === null) {
                $party = PartyModel::with(['addresses', 'contacts'])->findOrFail($invoice->party_id);
                $partnerId = (int) $client->execute('res.partner', 'create', [$partyTranslator->toOdoo($party)]);
                $map->remember('party', $party->id, 'res.partner', $partnerId, 'push', $partyTranslator->checksum($party));
                $party->updateQuietly(['odoo_id' => $partnerId]);
            }

            // 2. Taxes mappées (pull préalable odoo:sync — sinon lignes sans taxe, ajustées dans Odoo).
            $taxMap = DB::table('odoo_entity_maps')
                ->where('tenant_id', $this->tenantId)
                ->where('entity_type', 'tax')
                ->pluck('odoo_id', 'silaris_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $payload = $translator->toOdoo($invoice, $partnerId, $taxMap);

            // 3. Devise résolue par code ISO.
            $currencyIds = $client->execute('res.currency', 'search', [[['name', '=', $invoice->currency_code]]], ['limit' => 1]);
            if (is_array($currencyIds) && $currencyIds !== []) {
                $payload['currency_id'] = (int) $currencyIds[0];
            } else {
                unset($payload['currency_id']);
            }

            // 4. Idempotence : facture déjà mappée → update, sinon create.
            $existingId = $map->odooIdOf('invoice', $invoice->id);
            if ($existingId !== null) {
                $updatePayload = $payload;
                unset($updatePayload['invoice_line_ids'], $updatePayload['move_type']);
                $client->execute('account.move', 'write', [[$existingId], $updatePayload]);
                $odooId = $existingId;
            } else {
                $odooId = (int) $client->execute('account.move', 'create', [$payload]);
            }

            $map->remember('invoice', $invoice->id, 'account.move', $odooId, 'push');
            $invoice->updateQuietly(['odoo_id' => $odooId, 'status' => 'synced']);
            $logger->log('invoice', $invoice->id, 'push', 'success', $payload, attempts: $this->attempts(), durationMs: (int) ((hrtime(true) - $start) / 1e6));
        } catch (OdooRequestFailed $e) {
            $invoice->updateQuietly(['status' => 'sync_failed']);
            $logger->log('invoice', $invoice->id, 'push', 'dead_letter', null, $e->getMessage(), $this->attempts());
            $this->fail($e);
        } catch (Throwable $e) {
            $logger->log('invoice', $invoice->id, 'push', 'failed', null, $e->getMessage(), $this->attempts());
            throw $e;
        }
    }

    public function failed(Throwable $exception): void
    {
        app(TenantContext::class)->set($this->tenantId);
        InvoiceModel::where('id', $this->invoiceId)->update(['status' => 'sync_failed']);
        app(SyncLogger::class)->log('invoice', $this->invoiceId, 'push', 'dead_letter', null, $exception->getMessage(), $this->tries);
    }
}
