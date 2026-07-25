<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Silaris\Modules\OdooSync\Application\Service\PullPaymentStatuses;
use Silaris\Modules\OdooSync\Application\Service\PullTaxes;
use Silaris\Modules\OdooSync\Infrastructure\Transport\OdooClientFactory;
use Silaris\Modules\Shared\Infrastructure\Tenancy\TenantContext;
use Throwable;

class OdooSyncCommand extends Command
{
    protected $signature = 'odoo:sync {--tenant=} {--taxes} {--payments}';

    protected $description = 'Synchronisation Odoo : pull taxes et statuts de paiement, healthcheck';

    public function handle(TenantContext $tenantContext): int
    {
        $tenants = DB::table('odoo_connections')->where('is_active', true)
            ->join('tenants', 'tenants.id', '=', 'odoo_connections.tenant_id')
            ->when($this->option('tenant'), fn ($q, $slug) => $q->where('tenants.slug', $slug))
            ->get(['tenants.id', 'tenants.slug']);

        foreach ($tenants as $tenant) {
            $tenantContext->set($tenant->id);
            $factory = app(OdooClientFactory::class);

            try {
                $healthy = $factory->forCurrentTenant()->healthcheck();
                DB::table('odoo_connections')->where('tenant_id', $tenant->id)->update([
                    'health_status' => $healthy ? 'healthy' : 'down',
                    'last_healthcheck_at' => now(),
                ]);
                if (! $healthy) {
                    $this->warn("[{$tenant->slug}] Odoo injoignable — pulls reportés");

                    continue;
                }

                if ($this->option('taxes') || ! $this->option('payments')) {
                    $count = app(PullTaxes::class)->run();
                    $this->info("[{$tenant->slug}] taxes synchronisées : {$count}");
                }
                if ($this->option('payments') || ! $this->option('taxes')) {
                    $count = app(PullPaymentStatuses::class)->run();
                    $this->info("[{$tenant->slug}] statuts de paiement mis à jour : {$count}");
                }
            } catch (Throwable $e) {
                DB::table('odoo_connections')->where('tenant_id', $tenant->id)->update(['health_status' => 'down', 'last_healthcheck_at' => now()]);
                $this->error("[{$tenant->slug}] {$e->getMessage()}");
            }

            $tenantContext->forget();
        }

        return self::SUCCESS;
    }
}
