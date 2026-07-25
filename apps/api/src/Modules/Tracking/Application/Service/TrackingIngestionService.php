<?php

declare(strict_types=1);

namespace Silaris\Modules\Tracking\Application\Service;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Silaris\Modules\Shared\Infrastructure\Events\DomainEventPublisher;
use Silaris\Modules\Shared\Infrastructure\Tenancy\TenantContext;
use Silaris\Modules\Shipment\Infrastructure\Persistence\EloquentShipmentRepository;
use Silaris\Modules\Tracking\Domain\Contract\TrackingResult;

/**
 * Ingestion d'un résultat de tracking :
 *  1. déduplication par event_hash (idempotent — repolling sans doublons) ;
 *  2. insertion tracking_events + entrée timeline dossier ;
 *  3. DEPA/ARRI → ATD/ATA automatiques sur le dossier ;
 *  4. nouvelle ETA → agrégat (DelayDetected si dérive ≥ seuil tenant).
 */
final readonly class TrackingIngestionService
{
    private const TIMELINE_LABELS = [
        'GTIN' => 'Conteneur entré au terminal',
        'LOAD' => 'Conteneur chargé à bord',
        'DEPA' => 'Départ navire',
        'TRSH' => 'Transbordement',
        'ARRI' => 'Arrivée navire',
        'DISC' => 'Conteneur déchargé',
        'GTOT' => 'Conteneur sorti du terminal',
        'RETU' => 'Conteneur vide restitué',
    ];

    public function __construct(
        private TenantContext $tenant,
        private EloquentShipmentRepository $shipments,
        private DomainEventPublisher $events,
    ) {}

    /** @return int Nombre de nouveaux événements ingérés. */
    public function ingest(object $subscription, TrackingResult $result): int
    {
        $inserted = 0;

        foreach ($result->events as $event) {
            $hash = hash('sha256', $subscription->id.$event->dcsaEventCode.($event->locationLocode ?? '').$event->occurredAt->format('c'));

            $isNew = DB::table('tracking_events')->insertOrIgnore([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $this->tenant->id(),
                'subscription_id' => $subscription->id,
                'shipment_id' => $subscription->shipment_id,
                'dcsa_event_code' => $event->dcsaEventCode,
                'raw_status' => $event->rawStatus,
                'location_locode' => $event->locationLocode,
                'vessel_imo' => $event->vesselImo,
                'occurred_at' => $event->occurredAt->format('Y-m-d H:i:sP'),
                'event_hash' => $hash,
                'raw_payload' => json_encode($event->rawPayload),
            ]);

            if ($isNew === 0) {
                continue;
            }
            $inserted++;

            // Timeline dossier
            $label = self::TIMELINE_LABELS[$event->dcsaEventCode] ?? $event->rawStatus;
            DB::table('shipment_events')->insert([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $this->tenant->id(),
                'shipment_id' => $subscription->shipment_id,
                'type' => 'tracking',
                'title' => $label.($event->locationLocode ? " — {$event->locationLocode}" : ''),
                'payload' => json_encode(['dcsa' => $event->dcsaEventCode, 'raw' => $event->rawStatus]),
                'source' => 'carrier_api',
                'occurred_at' => $event->occurredAt->format('Y-m-d H:i:sP'),
            ]);

            // Jalons automatiques sur le dossier
            $shipment = $this->shipments->get($subscription->shipment_id);
            if ($event->dcsaEventCode === 'DEPA' && $shipment->schedule()->atd === null) {
                $shipment->confirmDeparture($event->occurredAt);
                $this->shipments->save($shipment);
            }
            if ($event->dcsaEventCode === 'ARRI' && $shipment->schedule()->ata === null) {
                $shipment->confirmArrival($event->occurredAt);
                $this->shipments->save($shipment);
            }
        }

        // ETA compagnie → agrégat (détection de retard selon seuil tenant)
        if ($result->eta !== null) {
            $shipment = $this->shipments->get($subscription->shipment_id);
            $currentEta = $shipment->schedule()->eta;
            if ($currentEta === null || abs($result->eta->getTimestamp() - $currentEta->getTimestamp()) > 3600) {
                $threshold = (int) (DB::table('tenants')->where('id', $this->tenant->id())->value('settings->delay_threshold_hours') ?? 24);
                $shipment->updateEta($result->eta, $threshold);
                $this->shipments->save($shipment);
                $this->events->publishFrom($shipment);
            }
        }

        return $inserted;
    }
}
