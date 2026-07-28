<?php

declare(strict_types=1);

namespace Silaris\Modules\CarrierConnect\Infrastructure\Connector;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Http;
use Silaris\Modules\CarrierConnect\Infrastructure\Support\ExchangeLogger;
use Silaris\Modules\CarrierConnect\Infrastructure\Support\StatusNormalizer;
use Silaris\Modules\Tracking\Domain\Contract\CarrierTrackingProvider;
use Silaris\Modules\Tracking\Domain\Contract\CarrierUnavailable;
use Silaris\Modules\Tracking\Domain\Contract\TrackingEventDto;
use Silaris\Modules\Tracking\Domain\Contract\TrackingResult;

/**
 * Connecteur agrégateur JSONCargo — une seule clé API pour ~95 % des compagnies
 * (Maersk, MSC, CMA CGM, Hapag-Lloyd, ONE, COSCO, Evergreen, Yang Ming, ZIM,
 * HMM, PIL). L'API renvoie un INSTANTANÉ du conteneur (statut courant,
 * localisations, ETA/ATD, navire) — traduit en un événement normalisé : la
 * déduplication par event_hash de l'ingestion fait qu'un instantané inchangé
 * ne crée rien, un changement de statut crée un nouvel événement.
 *
 * Statuts propriétaires → DCSA via carrier_status_mappings (scac JSONCARGO),
 * inconnus conservés bruts en UNKN.
 */
class JsonCargoConnector implements CarrierTrackingProvider
{
    private const MAX_BL_CONTAINERS = 3;

    public function __construct(
        private readonly string $shippingLine,
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly ExchangeLogger $logger,
        private readonly StatusNormalizer $normalizer,
    ) {}

    public function trackContainer(string $containerNumber): TrackingResult
    {
        $data = $this->call("/containers/{$containerNumber}", $containerNumber, 'track_container');

        return $this->resultFromSnapshot($data);
    }

    public function trackBillOfLading(string $blNumber): TrackingResult
    {
        $data = $this->call("/containers/bol/{$blNumber}", $blNumber, 'track_bl');
        $containers = array_slice((array) ($data['associated_container_numbers'] ?? []), 0, self::MAX_BL_CONTAINERS);

        $events = [];
        $snapshot = [];
        $eta = $etd = $ata = $atd = null;
        foreach ($containers as $containerNumber) {
            $result = $this->trackContainer((string) $containerNumber);
            $events = array_merge($events, $result->events);
            $snapshot = $snapshot === [] ? $result->snapshot : $snapshot;
            $eta ??= $result->eta;
            $etd ??= $result->etd;
            $ata ??= $result->ata;
            $atd ??= $result->atd;
        }

        return new TrackingResult(
            events: $events, eta: $eta, etd: $etd, ata: $ata, atd: $atd,
            containerNumbers: array_values(array_map('strval', $containers)),
            snapshot: $snapshot,
        );
    }

    public function capabilities(): array
    {
        return ['container_tracking', 'bl_tracking'];
    }

    /** @return array<string, mixed> */
    private function call(string $path, string $subject, string $operation): array
    {
        $started = hrtime(true);
        try {
            $response = Http::withHeaders(['x-api-key' => $this->apiKey])
                ->timeout(20)
                ->get($this->baseUrl.$path, ['shipping_line' => $this->shippingLine]);
        } catch (\Throwable $e) {
            $this->logger->log('JCGO', $operation, $subject, false, null, $this->elapsedMs($started), $e->getMessage());
            throw new CarrierUnavailable("JSONCargo injoignable : {$e->getMessage()}");
        }

        $duration = $this->elapsedMs($started);
        if (! $response->successful()) {
            $this->logger->log('JCGO', $operation, $subject, false, $response->status(), $duration, substr($response->body(), 0, 500));
            throw new CarrierUnavailable("JSONCargo HTTP {$response->status()} pour {$subject}");
        }

        $this->logger->log('JCGO', $operation, $subject, true, $response->status(), $duration);

        $payload = $response->json();

        return (array) ($payload['data'] ?? $payload ?? []);
    }

    /** @param array<string, mixed> $data */
    private function resultFromSnapshot(array $data): TrackingResult
    {
        $events = [];
        $rawStatus = (string) ($data['container_status'] ?? '');
        $occurredAt = $this->parseDate(
            $data['last_movement_timestamp'] ?? $data['timestamp_of_last_location'] ?? $data['last_updated'] ?? null,
        );

        if ($rawStatus !== '' && $occurredAt !== null) {
            $events[] = new TrackingEventDto(
                dcsaEventCode: $this->normalizer->normalize('JCGO', $rawStatus),
                rawStatus: $rawStatus,
                locationLocode: null, // JSONCargo renvoie des noms de lieux, pas des LOCODE — conservés dans raw_payload
                occurredAt: $occurredAt,
                vesselImo: null,
                rawPayload: array_intersect_key($data, array_flip([
                    'container_id', 'container_status', 'last_location', 'last_location_terminal',
                    'next_location', 'current_vessel_name', 'current_voyage_number',
                    'loading_port', 'discharging_port', 'customs_clearance', 'shipping_line_name',
                ])),
            );
        }

        return new TrackingResult(
            events: $events,
            eta: $this->parseDate($data['eta_final_destination'] ?? null),
            atd: $this->parseDate($data['atd_origin'] ?? null),
            snapshot: self::snapshotOf($data),
        );
    }

    /**
     * Photo exploitable du relevé. L'API ne renvoie pas d'historique : ce qu'elle
     * sait du voyage tient dans ces champs, qu'il serait dommage de réduire au
     * seul statut.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function snapshotOf(array $data): array
    {
        $keep = [
            'container_status', 'container_type', 'shipping_line_name',
            'last_location', 'last_location_terminal', 'next_location', 'next_location_terminal',
            'current_vessel_name', 'current_voyage_number', 'last_vessel_name', 'last_voyage_number',
            'loading_port', 'discharging_port', 'shipped_from', 'shipped_to',
            'atd_origin', 'atd_last_location', 'eta_next_destination', 'eta_final_destination',
            'customs_clearance', 'timestamp_of_last_location', 'last_updated', 'bill_of_lading',
        ];

        return array_filter(
            array_intersect_key($data, array_flip($keep)),
            static fn ($value) => $value !== null && $value !== '',
        );
    }

    private function parseDate(mixed $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '' || ! is_string($value)) {
            return null;
        }

        try {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (\Throwable) {
            return null;
        }
    }

    private function elapsedMs(int|float $startedNs): int
    {
        return (int) ((hrtime(true) - $startedNs) / 1_000_000);
    }
}
