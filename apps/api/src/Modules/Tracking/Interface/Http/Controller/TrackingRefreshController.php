<?php

declare(strict_types=1);

namespace Silaris\Modules\Tracking\Interface\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Silaris\Modules\CarrierConnect\Infrastructure\Support\CircuitBreaker;
use Silaris\Modules\Tracking\Application\Service\TrackingPoller;
use Silaris\Modules\Tracking\Domain\Contract\CarrierUnavailable;

/**
 * Actualisation MANUELLE du suivi d'un dossier : interroge immédiatement les
 * compagnies pour tous les abonnements actifs du dossier, sans attendre la
 * cadence planifiée (quotidienne par défaut — maîtrise des quotas API).
 */
class TrackingRefreshController
{
    public function __construct(
        private readonly TrackingPoller $poller,
        private readonly CircuitBreaker $breaker,
    ) {}

    public function __invoke(string $shipmentId): JsonResponse
    {
        $active = DB::table('tracking_subscriptions')
            ->where('shipment_id', $shipmentId)
            ->where('status', 'active')
            ->get();

        // Sans compagnie, l'agrégateur n'a rien à interroger : l'abonnement
        // existe mais reste en attente du booking. Le dire plutôt que de le
        // taire, sinon le dossier paraît suivi alors qu'il ne l'est pas.
        $subscriptions = $active->whereNotNull('carrier_scac')->values();
        $pending = $active->whereNull('carrier_scac')->values();

        $polled = 0;
        $newEvents = 0;
        $errors = [];

        foreach ($subscriptions as $subscription) {
            if ($this->breaker->isOpen($subscription->carrier_scac)) {
                $errors[] = "{$subscription->subject_number} : compagnie temporairement indisponible (circuit ouvert).";

                continue;
            }
            try {
                $newEvents += $this->poller->poll($subscription);
                $polled++;
            } catch (CarrierUnavailable $e) {
                $errors[] = "{$subscription->subject_number} : {$e->getMessage()}";
            }
        }

        foreach ($pending as $subscription) {
            $errors[] = "{$subscription->subject_number} : compagnie inconnue — renseignez le booking du dossier.";
        }

        return response()->json([
            'subscriptions' => $subscriptions->count(),
            'pending_carrier' => $pending->count(),
            'polled' => $polled,
            'new_events' => $newEvents,
            'errors' => $errors,
        ]);
    }
}
