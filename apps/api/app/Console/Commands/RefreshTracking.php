<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Silaris\Modules\CarrierConnect\Infrastructure\CarrierRegistry;
use Silaris\Modules\CarrierConnect\Infrastructure\Support\CircuitBreaker;
use Silaris\Modules\Shared\Infrastructure\Tenancy\TenantContext;
use Silaris\Modules\Tracking\Application\Service\TrackingIngestionService;
use Silaris\Modules\Tracking\Domain\Contract\CarrierUnavailable;
use Throwable;

class RefreshTracking extends Command
{
    protected $signature = 'tracking:refresh {--tenant= : Slug tenant (défaut : tous)} {--subscription= : ID abonnement précis}';

    protected $description = 'Rafraîchit les abonnements de tracking actifs via les connecteurs compagnies';

    public function handle(TenantContext $tenantContext): int
    {
        $tenants = DB::table('tenants')->where('is_active', true)
            ->when($this->option('tenant'), fn ($q, $slug) => $q->where('slug', $slug))
            ->get(['id', 'slug', 'settings']);

        foreach ($tenants as $tenant) {
            $tenantContext->set($tenant->id);
            $refreshMinutes = (int) (json_decode((string) $tenant->settings, true)['tracking_refresh_minutes'] ?? 120);

            $subscriptions = DB::table('tracking_subscriptions')
                ->where('tenant_id', $tenant->id)
                ->where('status', 'active')
                ->whereNotNull('carrier_scac')
                ->when($this->option('subscription'), fn ($q, $id) => $q->where('id', $id))
                ->when(! $this->option('subscription'), fn ($q) => $q->where(
                    fn ($w) => $w->whereNull('last_polled_at')->orWhere('last_polled_at', '<=', now()->subMinutes($refreshMinutes)),
                ))
                ->orderBy('last_polled_at')
                ->limit(200)
                ->get();

            $registry = app(CarrierRegistry::class);
            $breaker = app(CircuitBreaker::class);
            $ingestion = app(TrackingIngestionService::class);

            foreach ($subscriptions as $subscription) {
                if ($breaker->isOpen($subscription->carrier_scac)) {
                    $this->warn("  [{$tenant->slug}] {$subscription->subject_number}: circuit ouvert ({$subscription->carrier_scac})");

                    continue;
                }

                try {
                    $connector = $registry->resolve($subscription->carrier_scac);
                    $result = $subscription->subject_type === 'bl'
                        ? $connector->trackBillOfLading($subscription->subject_number)
                        : $connector->trackContainer($subscription->subject_number);

                    $inserted = $ingestion->ingest($subscription, $result);

                    DB::table('tracking_subscriptions')->where('id', $subscription->id)
                        ->update(['last_polled_at' => now(), 'consecutive_failures' => 0, 'updated_at' => now()]);
                    $breaker->recordSuccess($subscription->carrier_scac);

                    $this->info("  [{$tenant->slug}] {$subscription->subject_number}: {$inserted} nouvel(s) événement(s)");
                } catch (CarrierUnavailable $e) {
                    $failures = $subscription->consecutive_failures + 1;
                    DB::table('tracking_subscriptions')->where('id', $subscription->id)
                        ->update(['last_polled_at' => now(), 'consecutive_failures' => $failures, 'updated_at' => now()]);
                    $breaker->recordFailure($subscription->carrier_scac, $failures);
                    $this->error("  [{$tenant->slug}] {$subscription->subject_number}: {$e->getMessage()} (échec n°{$failures})");
                } catch (Throwable $e) {
                    report($e);
                    $this->error("  [{$tenant->slug}] {$subscription->subject_number}: erreur interne — {$e->getMessage()}");
                }
            }

            $tenantContext->forget();
        }

        return self::SUCCESS;
    }
}
