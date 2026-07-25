<?php

declare(strict_types=1);

namespace Silaris\Modules\OdooSync\Application\Service;

use Illuminate\Support\Facades\DB;
use Silaris\Modules\OdooSync\Infrastructure\Transport\OdooClientFactory;
use Silaris\Modules\Shared\Infrastructure\Tenancy\TenantContext;

/**
 * payment_state Odoo → invoices.payment_status.
 * Seul OdooSync écrit ce champ (Odoo = source de vérité paiements).
 */
final readonly class PullPaymentStatuses
{
    private const STATE_MAP = [
        'not_paid' => 'unpaid',
        'in_payment' => 'partial',
        'partial' => 'partial',
        'paid' => 'paid',
        'reversed' => 'paid',
    ];

    public function __construct(
        private TenantContext $tenant,
        private OdooClientFactory $factory,
        private SyncLogger $logger,
    ) {}

    public function run(): int
    {
        $mappings = DB::table('odoo_entity_maps')
            ->where('tenant_id', $this->tenant->id())
            ->where('entity_type', 'invoice')
            ->pluck('silaris_id', 'odoo_id');

        if ($mappings->isEmpty()) {
            return 0;
        }

        $client = $this->factory->forCurrentTenant();
        $moves = $client->execute('account.move', 'search_read',
            [[['id', 'in', $mappings->keys()->map(fn ($id) => (int) $id)->all()]]],
            ['fields' => ['id', 'payment_state']],
        );

        $updated = 0;
        foreach ((array) $moves as $move) {
            $status = self::STATE_MAP[$move['payment_state'] ?? ''] ?? null;
            $silarisId = $mappings[$move['id']] ?? null;
            if ($status === null || $silarisId === null) {
                continue;
            }
            $updated += DB::table('invoices')
                ->where('id', $silarisId)
                ->where('payment_status', '<>', $status)
                ->update(['payment_status' => $status, 'updated_at' => now()]);
        }

        $this->logger->log('payment_status', null, 'pull', 'success', ['checked' => count((array) $moves), 'updated' => $updated]);

        return $updated;
    }
}
