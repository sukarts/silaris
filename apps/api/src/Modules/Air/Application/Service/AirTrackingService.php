<?php

declare(strict_types=1);

namespace Silaris\Modules\Air\Application\Service;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Silaris\Modules\Air\Domain\Contract\AirTrackingResult;
use Silaris\Modules\Air\Infrastructure\Persistence\Model\AirWaybillModel;
use Silaris\Modules\CarrierConnect\Infrastructure\Connector\ShipsGoAirConnector;
use Silaris\Modules\CarrierConnect\Infrastructure\Support\ExchangeLogger;
use Silaris\Modules\CarrierConnect\Infrastructure\Support\ShipsGoAirTranslator;
use Silaris\Modules\Shared\Infrastructure\Tenancy\TenantContext;
use Silaris\Modules\Tracking\Domain\Contract\CarrierUnavailable;

/**
 * Met une LTA sous suivi ShipsGo et range son relevé : heures réelles des
 * segments, état d'acheminement, historique des mouvements.
 *
 * Le relevé arrive une fois ; on ne réécrit que ce qui a changé. Les
 * mouvements sont dédoublonnés par empreinte : relire la même expédition
 * n'ajoute rien.
 */
final readonly class AirTrackingService
{
    public function __construct(
        private TenantContext $tenant,
        private ExchangeLogger $logger,
        private ShipsGoAirTranslator $translator,
    ) {}

    /**
     * @return array{status: string, new_events: int, last_location: ?string}
     */
    public function track(AirWaybillModel $awb): array
    {
        $awb->loadMissing('airline');

        $result = $this->connector()->trackByAwb($awb->number, $awb->airline?->awb_prefix);

        $newEvents = $this->recordEvents($awb, $result);
        $this->applyLegs($awb, $result);
        $this->applyAwb($awb, $result);

        return [
            'status' => $result->status,
            'new_events' => $newEvents,
            'last_location' => $result->lastLocationIata,
        ];
    }

    private function recordEvents(AirWaybillModel $awb, AirTrackingResult $result): int
    {
        $count = 0;
        foreach ($result->events as $event) {
            $hash = sha1(implode('|', [
                $awb->id,
                strtoupper((string) $event->rawEvent),
                $event->occurredAt->format(DATE_ATOM),
                (string) $event->locationIata,
            ]));

            $inserted = DB::table('air_tracking_events')->insertOrIgnore([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $this->tenant->id(),
                'awb_id' => $awb->id,
                'status' => $event->status,
                'raw_event' => $event->rawEvent,
                'location_iata' => $event->locationIata,
                'flight_number' => $event->flightNumber,
                'occurred_at' => $event->occurredAt,
                'event_hash' => $hash,
                'raw_payload' => json_encode($event->rawPayload),
                'created_at' => now(),
            ]);

            $count += $inserted;
        }

        return $count;
    }

    /** Rapproche chaque relevé de segment d'un segment de la LTA et y porte le réel. */
    private function applyLegs(AirWaybillModel $awb, AirTrackingResult $result): void
    {
        foreach ($result->legs as $leg) {
            $actuals = array_filter([
                'actual_departure_at' => $leg->actualDepartureAt,
                'actual_arrival_at' => $leg->actualArrivalAt,
            ], static fn ($v) => $v !== null);

            if ($actuals === []) {
                continue;
            }

            $query = DB::table('flight_legs')->where('awb_id', $awb->id);
            if ($leg->flightNumber !== null) {
                $query->whereRaw('upper(flight_number) = ?', [strtoupper($leg->flightNumber)]);
            } elseif ($leg->originIata !== null && $leg->destinationIata !== null) {
                $query->where('origin_iata', $leg->originIata)->where('destination_iata', $leg->destinationIata);
            } else {
                continue;
            }

            $query->update([...$actuals, 'updated_at' => now()]);
        }
    }

    private function applyAwb(AirWaybillModel $awb, AirTrackingResult $result): void
    {
        $awb->forceFill([
            'tracking_status' => $result->status,
            'last_location_iata' => $result->lastLocationIata,
            'last_tracked_at' => now(),
            'shipsgo_ref' => $result->shipsgoRef ?? $awb->shipsgo_ref,
        ])->save();
    }

    private function connector(): ShipsGoAirConnector
    {
        $apiKey = $this->apiKey();
        if ($apiKey === null || $apiKey === '') {
            throw new CarrierUnavailable('Aucune clé ShipsGo configurée — suivi aérien indisponible.');
        }

        return new ShipsGoAirConnector(
            $apiKey,
            rtrim((string) config('services.shipsgo.base_url'), '/'),
            $this->logger,
            $this->translator,
        );
    }

    /**
     * Clé du tenant si un credential ShipsGo lui est propre, sinon la clé de la
     * plateforme — même résolution que le suivi maritime.
     */
    private function apiKey(): ?string
    {
        $row = DB::table('carrier_api_credentials')
            ->where('tenant_id', $this->tenant->id())
            ->where('carrier_scac', 'SGOO')
            ->where('is_active', true)
            ->value('credentials');

        if ($row !== null) {
            $decoded = json_decode(decrypt($row, false) ?: (string) $row, true);
            if (is_array($decoded) && isset($decoded['api_key']) && is_string($decoded['api_key']) && $decoded['api_key'] !== '') {
                return $decoded['api_key'];
            }
        }

        $platform = config('services.shipsgo.api_key');

        return is_string($platform) ? $platform : null;
    }
}
