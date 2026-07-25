<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Silaris\Modules\Billing\Infrastructure\Persistence\Model\InvoiceModel;
use Silaris\Modules\Crm\Infrastructure\Persistence\Model\PartyModel;
use Silaris\Modules\Ocean\Infrastructure\Persistence\Model\BookingModel;
use Silaris\Modules\Ocean\Infrastructure\Persistence\Model\ContainerModel;
use Silaris\Modules\Shared\Infrastructure\Tenancy\TenantContext;
use Silaris\Modules\Shipment\Infrastructure\Persistence\Model\ShipmentModel;

/**
 * Réindexation Meilisearch complète, tenant par tenant (les scopes globaux
 * exigent un contexte). À lancer une fois au câblage puis après tout
 * changement de mapping ; l'indexation courante suit les mutations Eloquent.
 */
final class ReindexSearch extends Command
{
    protected $signature = 'search:reindex';

    protected $description = 'Synchronise les réglages des index Meilisearch puis réindexe toutes les données, tenant par tenant.';

    private const MODELS = [
        ShipmentModel::class,
        PartyModel::class,
        ContainerModel::class,
        BookingModel::class,
        InvoiceModel::class,
    ];

    public function handle(TenantContext $context): int
    {
        if (config('scout.driver') !== 'meilisearch') {
            $this->warn('SCOUT_DRIVER n\'est pas "meilisearch" — rien à faire.');

            return self::SUCCESS;
        }

        $this->call('scout:sync-index-settings');

        $tenants = DB::table('tenants')->where('is_active', true)->pluck('id');
        foreach ($tenants as $tenantId) {
            $context->set((string) $tenantId);
            foreach (self::MODELS as $model) {
                $count = $model::query()->count();
                $model::makeAllSearchable();
                $this->line(sprintf('  %s · %s : %d enregistrement(s)', substr((string) $tenantId, 0, 8), class_basename($model), $count));
            }
            $context->forget();
        }

        $this->info('Réindexation terminée.');

        return self::SUCCESS;
    }
}
