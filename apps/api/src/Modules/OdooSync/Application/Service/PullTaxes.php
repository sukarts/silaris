<?php

declare(strict_types=1);

namespace Silaris\Modules\OdooSync\Application\Service;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Silaris\Modules\OdooSync\Infrastructure\Transport\OdooClientFactory;
use Silaris\Modules\Shared\Infrastructure\Tenancy\TenantContext;

/** account.tax (ventes) → tax_rates SILARIS. Odoo est maître sur les taxes. */
final readonly class PullTaxes
{
    public function __construct(
        private TenantContext $tenant,
        private OdooClientFactory $factory,
        private EntityMap $map,
        private SyncLogger $logger,
    ) {}

    public function run(): int
    {
        $client = $this->factory->forCurrentTenant();
        $taxes = $client->execute('account.tax', 'search_read',
            [[['type_tax_use', '=', 'sale'], ['active', '=', true]]],
            ['fields' => ['id', 'name', 'amount']],
        );

        $count = 0;
        foreach ((array) $taxes as $tax) {
            $existing = DB::table('odoo_entity_maps')
                ->where('tenant_id', $this->tenant->id())
                ->where('entity_type', 'tax')
                ->where('odoo_model', 'account.tax')
                ->where('odoo_id', $tax['id'])
                ->value('silaris_id');

            if ($existing !== null) {
                DB::table('tax_rates')->where('id', $existing)->update([
                    'name' => $tax['name'], 'rate_percent' => $tax['amount'], 'updated_at' => now(),
                ]);
                $silarisId = (string) $existing;
            } else {
                $silarisId = (string) Str::uuid7();
                DB::table('tax_rates')->insert([
                    'id' => $silarisId, 'tenant_id' => $this->tenant->id(),
                    'name' => $tax['name'], 'rate_percent' => $tax['amount'],
                    'odoo_id' => $tax['id'], 'is_active' => true,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $this->map->remember('tax', $silarisId, 'account.tax', (int) $tax['id'], 'pull');
            $count++;
        }

        $this->logger->log('tax', null, 'pull', 'success', ['count' => $count]);

        return $count;
    }
}
