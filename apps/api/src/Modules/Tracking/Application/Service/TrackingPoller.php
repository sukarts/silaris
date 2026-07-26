<?php

declare(strict_types=1);

namespace Silaris\Modules\Tracking\Application\Service;

use Illuminate\Support\Facades\DB;
use Silaris\Modules\CarrierConnect\Infrastructure\CarrierRegistry;
use Silaris\Modules\CarrierConnect\Infrastructure\Support\CircuitBreaker;
use Silaris\Modules\Tracking\Domain\Contract\CarrierUnavailable;

/**
 * Interrogation d'UN abonnement de tracking : connecteur → ingestion →
 * last_polled_at + compteur d'échecs + circuit breaker. Partagé par le
 * rafraîchissement planifié (quotidien par défaut — maîtrise des quotas API)
 * et l'actualisation manuelle depuis le dossier (immédiate, hors cadence).
 *
 * @throws CarrierUnavailable après enregistrement de l'échec
 */
final readonly class TrackingPoller
{
    public function __construct(
        private CarrierRegistry $registry,
        private CircuitBreaker $breaker,
        private TrackingIngestionService $ingestion,
    ) {}

    /** @return int Nombre de nouveaux événements ingérés. */
    public function poll(object $subscription): int
    {
        try {
            $connector = $this->registry->resolve($subscription->carrier_scac);
            $result = $subscription->subject_type === 'bl'
                ? $connector->trackBillOfLading($subscription->subject_number)
                : $connector->trackContainer($subscription->subject_number);

            $inserted = $this->ingestion->ingest($subscription, $result);

            DB::table('tracking_subscriptions')->where('id', $subscription->id)
                ->update(['last_polled_at' => now(), 'consecutive_failures' => 0, 'updated_at' => now()]);
            $this->breaker->recordSuccess($subscription->carrier_scac);

            return $inserted;
        } catch (CarrierUnavailable $e) {
            $failures = $subscription->consecutive_failures + 1;
            DB::table('tracking_subscriptions')->where('id', $subscription->id)
                ->update(['last_polled_at' => now(), 'consecutive_failures' => $failures, 'updated_at' => now()]);
            $this->breaker->recordFailure($subscription->carrier_scac, $failures);

            throw $e;
        }
    }
}
