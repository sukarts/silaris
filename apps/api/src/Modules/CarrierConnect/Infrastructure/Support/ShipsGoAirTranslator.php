<?php

declare(strict_types=1);

namespace Silaris\Modules\CarrierConnect\Infrastructure\Support;

use DateTimeImmutable;
use DateTimeZone;
use Silaris\Modules\Air\Domain\Contract\AirLegUpdate;
use Silaris\Modules\Air\Domain\Contract\AirTrackingEventDto;
use Silaris\Modules\Air\Domain\Contract\AirTrackingResult;
use Throwable;

/**
 * Expédition aérienne ShipsGo → relevé de suivi normalisé.
 *
 * Toute la forme de la réponse ShipsGo air est enfermée ici : c'est le seul
 * fichier à ajuster le jour où l'on confronte le connecteur à une vraie réponse
 * de l'API (le mapping est écrit d'après le motif v2 documenté, pas encore
 * validé sur un compte aérien actif).
 */
final readonly class ShipsGoAirTranslator
{
    /** Codes de mouvement Cargo-iMP → état normalisé du dossier. */
    private const EVENT_STATUS = [
        'BKD' => 'booked', 'FOH' => 'booked', 'RCS' => 'booked',
        'DEP' => 'en_route',
        'ARR' => 'landed', 'RCF' => 'landed', 'NFD' => 'landed',
        'DLV' => 'delivered',
    ];

    /** Libellés de statut d'expédition ShipsGo → état normalisé. */
    private const SHIPMENT_STATUS = [
        'booked' => 'booked',
        'en-route' => 'en_route', 'en route' => 'en_route', 'in transit' => 'en_route',
        'landed' => 'landed', 'arrived' => 'landed',
        'delivered' => 'delivered',
        'undelivered' => 'unknown',
    ];

    /** @param array<string, mixed> $shipment */
    public function translate(array $shipment): AirTrackingResult
    {
        $events = $this->eventsOf($shipment);

        return new AirTrackingResult(
            status: $this->statusOf($shipment, $events),
            legs: $this->legsOf($shipment),
            events: $events,
            lastLocationIata: $this->lastLocationOf($shipment, $events),
            eta: self::parseDate($this->dig($shipment, ['route', 'destination', 'expected_date'])
                ?? $this->dig($shipment, ['route', 'destination', 'date'])),
            shipsgoRef: isset($shipment['id']) ? (string) $shipment['id'] : null,
        );
    }

    /**
     * @param  array<string, mixed>  $shipment
     * @return list<AirTrackingEventDto>
     */
    private function eventsOf(array $shipment): array
    {
        $events = [];
        foreach ((array) ($shipment['movements'] ?? []) as $movement) {
            $movement = (array) $movement;
            $occurredAt = self::parseDate($movement['timestamp'] ?? $movement['date'] ?? null);
            $rawEvent = self::str($movement['event'] ?? $movement['code'] ?? null);
            if ($occurredAt === null) {
                continue;
            }

            $iata = self::iata(self::str($this->dig($movement, ['location', 'iata']) ?? ($movement['airport'] ?? null)));

            $events[] = new AirTrackingEventDto(
                status: $this->eventStatus($rawEvent),
                rawEvent: $rawEvent,
                locationIata: $iata,
                flightNumber: self::str($movement['flight_number'] ?? $movement['flight'] ?? null),
                occurredAt: $occurredAt,
                rawPayload: array_filter([
                    'event' => $rawEvent,
                    'location' => $this->dig($movement, ['location', 'name']),
                    'flight_number' => $movement['flight_number'] ?? $movement['flight'] ?? null,
                    'status' => $movement['status'] ?? null,
                ], static fn ($v) => $v !== null && $v !== ''),
            );
        }

        return $events;
    }

    /**
     * Heures réelles par segment : on regroupe les mouvements par vol, le départ
     * du vol donne l'heure de départ réelle, son arrivée l'heure d'arrivée.
     *
     * @param  array<string, mixed>  $shipment
     * @return list<AirLegUpdate>
     */
    private function legsOf(array $shipment): array
    {
        /** @var array<string, array{origin: ?string, destination: ?string, dep: ?DateTimeImmutable, arr: ?DateTimeImmutable}> $byFlight */
        $byFlight = [];
        foreach ((array) ($shipment['movements'] ?? []) as $movement) {
            $movement = (array) $movement;
            $flight = self::str($movement['flight_number'] ?? $movement['flight'] ?? null);
            if ($flight === null) {
                continue;
            }
            $event = strtoupper((string) self::str($movement['event'] ?? $movement['code'] ?? ''));
            $at = self::parseDate($movement['timestamp'] ?? $movement['date'] ?? null);
            $iata = self::iata(self::str($this->dig($movement, ['location', 'iata']) ?? ($movement['airport'] ?? null)));

            $byFlight[$flight] ??= ['origin' => null, 'destination' => null, 'dep' => null, 'arr' => null];
            if ($event === 'DEP') {
                $byFlight[$flight]['dep'] = $at;
                $byFlight[$flight]['origin'] = $iata ?? $byFlight[$flight]['origin'];
            } elseif (in_array($event, ['ARR', 'RCF'], true)) {
                $byFlight[$flight]['arr'] = $at;
                $byFlight[$flight]['destination'] = $iata ?? $byFlight[$flight]['destination'];
            }
        }

        $legs = [];
        foreach ($byFlight as $flight => $leg) {
            $legs[] = new AirLegUpdate(
                flightNumber: $flight,
                originIata: $leg['origin'],
                destinationIata: $leg['destination'],
                actualDepartureAt: $leg['dep'],
                actualArrivalAt: $leg['arr'],
            );
        }

        return $legs;
    }

    /**
     * @param  array<string, mixed>  $shipment
     * @param  list<AirTrackingEventDto>  $events
     */
    private function statusOf(array $shipment, array $events): string
    {
        $raw = strtolower(trim((string) self::str($shipment['status'] ?? null)));
        if ($raw !== '' && isset(self::SHIPMENT_STATUS[$raw])) {
            return self::SHIPMENT_STATUS[$raw];
        }

        // À défaut d'état d'expédition, le dernier mouvement fait foi.
        if ($events !== []) {
            return $events[array_key_last($events)]->status;
        }

        return 'unknown';
    }

    /**
     * @param  array<string, mixed>  $shipment
     * @param  list<AirTrackingEventDto>  $events
     */
    private function lastLocationOf(array $shipment, array $events): ?string
    {
        $iata = self::iata(self::str($this->dig($shipment, ['last_location', 'iata'])));
        if ($iata !== null) {
            return $iata;
        }

        for ($i = count($events) - 1; $i >= 0; $i--) {
            if ($events[$i]->locationIata !== null) {
                return $events[$i]->locationIata;
            }
        }

        return null;
    }

    private function eventStatus(?string $rawEvent): string
    {
        $code = strtoupper((string) $rawEvent);

        return self::EVENT_STATUS[$code] ?? 'unknown';
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  list<string>  $path
     */
    private function dig(array $node, array $path): mixed
    {
        foreach ($path as $key) {
            if (! is_array($node) || ! array_key_exists($key, $node)) {
                return null;
            }
            $node = $node[$key];
        }

        return $node;
    }

    private static function str(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value === '' ? null : $value;
        }

        return is_int($value) || is_float($value) ? (string) $value : null;
    }

    private static function iata(?string $code): ?string
    {
        $code = strtoupper((string) $code);

        return preg_match('/^[A-Z]{3}$/', $code) === 1 ? $code : null;
    }

    private static function parseDate(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Throwable) {
            return null;
        }
    }
}
