<?php

declare(strict_types=1);

namespace Silaris\Modules\Tracking\Application\Service;

use Illuminate\Support\Facades\DB;
use Silaris\Modules\CarrierConnect\Infrastructure\CarrierRegistry;
use Silaris\Modules\CarrierConnect\Infrastructure\Support\CircuitBreaker;
use Silaris\Modules\Tracking\Domain\Contract\CarrierUnavailable;
use Silaris\Modules\Tracking\Domain\Contract\TrackingResult;

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
        return $this->pollDetailed($subscription)['events'];
    }

    /**
     * Interroge et rend aussi le relevé, pour éviter un second appel à
     * l'appelant qui en aurait besoin — le quota transporteur se compte.
     *
     * @return array{events: int, result: TrackingResult}
     */
    public function pollDetailed(object $subscription): array
    {
        try {
            $connector = $this->registry->resolve($subscription->carrier_scac);
            $result = $subscription->subject_type === 'bl'
                ? $connector->trackBillOfLading($subscription->subject_number)
                : $connector->trackContainer($subscription->subject_number);

            $inserted = $this->ingestion->ingest($subscription, $result);

            DB::table('tracking_subscriptions')->where('id', $subscription->id)->update([
                'last_polled_at' => now(),
                'consecutive_failures' => 0,
                // La photo prime sur le seul statut : c'est elle qui porte le
                // navire, les escales et l'ETA.
                'last_snapshot' => $result->snapshot === [] ? null : json_encode($result->snapshot),
                'updated_at' => now(),
            ]);
            $this->breaker->recordSuccess($subscription->carrier_scac);

            return ['events' => $inserted, 'result' => $result];
        } catch (CarrierUnavailable $e) {
            $failures = $subscription->consecutive_failures + 1;
            DB::table('tracking_subscriptions')->where('id', $subscription->id)
                ->update(['last_polled_at' => now(), 'consecutive_failures' => $failures, 'updated_at' => now()]);
            $this->breaker->recordFailure($subscription->carrier_scac, $failures);

            throw $e;
        }
    }
}
