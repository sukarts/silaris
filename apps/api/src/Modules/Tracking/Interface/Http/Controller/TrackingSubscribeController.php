<?php

declare(strict_types=1);

namespace Silaris\Modules\Tracking\Interface\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Silaris\Modules\Shared\Infrastructure\Tenancy\TenantContext;
use Silaris\Modules\Tracking\Application\Service\TrackingPoller;
use Silaris\Modules\Tracking\Application\Service\TrackingSubscriber;
use Silaris\Modules\Tracking\Domain\Contract\CarrierUnavailable;
use Silaris\Modules\Tracking\Domain\Contract\TrackingResult;

/**
 * Mise sous suivi d'un dossier à partir d'un numéro.
 *
 * À l'import, le transitaire ne fait pas le booking — c'est le chargeur à
 * l'origine. Il ne dispose souvent que du connaissement. La compagnie le lui
 * apprend alors les conteneurs rattachés : ceux-ci sont créés et affectés au
 * dossier dans la foulée, puis suivis à leur tour.
 */
class TrackingSubscribeController
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly TrackingSubscriber $subscriber,
        private readonly TrackingPoller $poller,
    ) {}

    /** POST /v1/shipments/{id}/tracking/subscribe */
    public function __invoke(Request $request, string $shipmentId): JsonResponse
    {
        $data = $request->validate([
            'subject_type' => ['required', Rule::in(['bl', 'container'])],
            'number' => ['required', 'string', 'max:32'],
            'carrier_scac' => ['nullable', 'string', 'size:4', 'exists:carriers,scac'],
        ]);

        // La compagnie saisie vaut pour tout le dossier : les conteneurs déjà
        // affectés attendaient précisément cette information.
        $this->subscriber->attachCarrier($shipmentId, $data['carrier_scac'] ?? null);

        $subscriptionId = $this->subscriber->subscribe(
            $this->tenant->id(), $shipmentId, $data['subject_type'], $data['number'], $data['carrier_scac'] ?? null,
        );

        if ($subscriptionId === null) {
            return response()->json(['message' => 'Numéro vide.'], 422);
        }

        $subscription = DB::table('tracking_subscriptions')->where('id', $subscriptionId)->first();
        if ($subscription->carrier_scac === null) {
            return response()->json([
                'subscription_id' => $subscriptionId,
                'carrier_known' => false,
                'message' => 'Abonnement enregistré. Précisez la compagnie pour lancer l\'interrogation.',
            ], 201);
        }

        // Interrogation immédiate : l'exploitant vient de saisir le numéro, il
        // doit savoir tout de suite si la compagnie le reconnaît.
        try {
            $polled = $this->poller->pollDetailed($subscription);
        } catch (CarrierUnavailable $e) {
            return response()->json([
                'subscription_id' => $subscriptionId,
                'carrier_known' => true,
                'new_events' => 0,
                'message' => $e->getMessage(),
            ], 201);
        }

        $containers = $data['subject_type'] === 'bl'
            ? $this->attachContainersOf($subscription, $shipmentId, $polled['result'])
            : ['attached' => [], 'busy' => []];

        return response()->json([
            'subscription_id' => $subscriptionId,
            'carrier_known' => true,
            'new_events' => $polled['events'],
            'containers' => $containers['attached'],
            'containers_busy' => $containers['busy'],
        ], 201);
    }

    /**
     * Crée et affecte les conteneurs que la compagnie rattache au connaissement.
     *
     * @return array{attached: list<string>, busy: list<string>}
     */
    private function attachContainersOf(object $subscription, string $shipmentId, TrackingResult $result): array
    {
        $attached = [];
        $busy = [];
        foreach ($result->containerNumbers as $number) {
            $number = strtoupper(str_replace([' ', '-'], '', $number));
            if ($number === '') {
                continue;
            }

            $containerId = DB::table('containers')->where('number', $number)->value('id');
            if ($containerId === null) {
                $containerId = (string) Str::uuid7();
                DB::table('containers')->insert([
                    'id' => $containerId, 'tenant_id' => $this->tenant->id(), 'number' => $number,
                    'size_type' => null, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            // Un conteneur n'a qu'une affectation active : s'il voyage encore
            // pour un autre dossier, on le signale plutôt que de forcer.
            $activeElsewhere = DB::table('container_assignments')
                ->where('container_id', $containerId)
                ->whereNull('returned_at')
                ->where('shipment_id', '<>', $shipmentId)
                ->exists();

            if ($activeElsewhere) {
                $busy[] = $number;

                continue;
            }

            $alreadyAssigned = DB::table('container_assignments')
                ->where('container_id', $containerId)->where('shipment_id', $shipmentId)->exists();

            if (! $alreadyAssigned) {
                DB::table('container_assignments')->insert([
                    'id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id(),
                    'container_id' => $containerId, 'shipment_id' => $shipmentId,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $attached[] = $number;
            }

            $containerSubscription = $this->subscriber->subscribe(
                $this->tenant->id(), $shipmentId, 'container', $number, $subscription->carrier_scac,
            );

            // Le relevé du connaissement décrit le voyage de ses conteneurs :
            // le reprendre évite un appel par boîte à la souscription.
            if ($containerSubscription !== null && $result->snapshot !== []) {
                DB::table('tracking_subscriptions')->where('id', $containerSubscription)
                    ->whereNull('last_snapshot')
                    ->update(['last_snapshot' => json_encode($result->snapshot), 'updated_at' => now()]);
            }
        }

        return ['attached' => $attached, 'busy' => $busy];
    }
}
