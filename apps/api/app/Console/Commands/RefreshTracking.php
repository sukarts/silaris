<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Silaris\Modules\CarrierConnect\Infrastructure\Support\CircuitBreaker;
use Silaris\Modules\Shared\Infrastructure\Tenancy\TenantContext;
use Silaris\Modules\Tracking\Application\Service\TrackingPoller;
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
            $refreshMinutes = (int) (json_decode((string) $tenant->settings, true)['tracking_refresh_minutes'] ?? 1440);

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

            $breaker = app(CircuitBreaker::class);
            $poller = app(TrackingPoller::class);

            foreach ($subscriptions as $subscription) {
                if ($breaker->isOpen($subscription->carrier_scac)) {
                    $this->warn("  [{$tenant->slug}] {$subscription->subject_number}: circuit ouvert ({$subscription->carrier_scac})");

                    continue;
                }

                try {
                    $inserted = $poller->poll($subscription);

                    $this->info("  [{$tenant->slug}] {$subscription->subject_number}: {$inserted} nouvel(s) événement(s)");
                } catch (CarrierUnavailable $e) {
                    $this->error("  [{$tenant->slug}] {$subscription->subject_number}: {$e->getMessage()}");
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
