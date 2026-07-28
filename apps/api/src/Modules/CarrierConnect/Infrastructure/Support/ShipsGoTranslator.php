<?php

declare(strict_types=1);

namespace Silaris\Modules\CarrierConnect\Infrastructure\Support;

use DateTimeImmutable;
use DateTimeZone;
use Silaris\Modules\Tracking\Domain\Contract\TrackingEventDto;
use Silaris\Modules\Tracking\Domain\Contract\TrackingResult;
use Throwable;

/**
 * Expédition ShipsGo → résultat de suivi normalisé.
 *
 * La même expédition arrive par deux chemins : l'interrogation du connecteur et
 * la notification poussée. Une seule traduction pour les deux, sinon les deux
 * chemins finiraient par diverger sur des détails que personne ne remarque
 * avant qu'un dossier affiche autre chose selon la façon dont il a été mis à
 * jour.
 */
final readonly class ShipsGoTranslator
{
    /** Pseudo-SCAC de l'agrégateur dans les correspondances de statut. */
    public const SCAC = 'SGOO';

    public function __construct(private StatusNormalizer $normalizer) {}

    /** @param array<string, mixed> $shipment */
    public function translate(array $shipment): TrackingResult
    {
        $events = [];
        $containerNumbers = [];

        foreach ((array) ($shipment['containers'] ?? []) as $container) {
            $container = (array) $container;
            $number = strtoupper((string) ($container['number'] ?? ''));
            if ($number !== '' && $number !== 'NOT_ASSIGNED') {
                $containerNumbers[] = $number;
            }

            foreach ((array) ($container['movements'] ?? []) as $movement) {
                $event = $this->eventOf((array) $movement, $number);
                if ($event !== null) {
                    $events[] = $event;
                }
            }
        }

        $route = (array) ($shipment['route'] ?? []);

        return new TrackingResult(
            events: $events,
            eta: self::routeDate($route, 'port_of_discharge', ['expected_date', 'date']),
            etd: self::routeDate($route, 'port_of_loading', ['expected_date', 'date']),
            ata: self::routeDate($route, 'port_of_discharge', ['actual_date']),
            atd: self::routeDate($route, 'port_of_loading', ['actual_date']),
            containerNumbers: $containerNumbers,
            snapshot: $this->snapshotOf($shipment, $route),
        );
    }

    /** @param array<string, mixed> $movement */
    private function eventOf(array $movement, string $containerNumber): ?TrackingEventDto
    {
        $occurredAt = self::parseDate($movement['timestamp'] ?? null);
        $rawStatus = (string) ($movement['event'] ?? '');
        if ($occurredAt === null || $rawStatus === '') {
            return null;
        }

        $location = (array) ($movement['location'] ?? []);
        $vessel = (array) ($movement['vessel'] ?? []);

        return new TrackingEventDto(
            dcsaEventCode: $this->normalizer->normalize(self::SCAC, $rawStatus),
            rawStatus: $rawStatus,
            locationLocode: self::locodeOf($location),
            occurredAt: $occurredAt,
            vesselImo: isset($vessel['imo']) ? (string) $vessel['imo'] : null,
            rawPayload: array_filter([
                'container' => $containerNumber ?: null,
                'location' => $location['name'] ?? null,
                'country' => $location['country'] ?? null,
                'vessel' => $vessel['name'] ?? null,
                'voyage' => $movement['voyage'] ?? null,
                'status' => $movement['status'] ?? null,
            ], static fn ($value) => $value !== null && $value !== ''),
        );
    }

    /**
     * Photo lisible du voyage, affichée sous le conteneur du dossier.
     *
     * @param  array<string, mixed>  $shipment
     * @param  array<string, mixed>  $route
     * @return array<string, mixed>
     */
    private function snapshotOf(array $shipment, array $route): array
    {
        $container = (array) (((array) ($shipment['containers'] ?? []))[0] ?? []);
        $movements = (array) ($container['movements'] ?? []);
        $last = $movements === [] ? [] : (array) end($movements);

        $size = trim((string) ($container['size'] ?? '').' '.(string) ($container['type'] ?? ''));

        return array_filter([
            'container_status' => $container['status'] ?? $shipment['status'] ?? null,
            'container_type' => $size !== '' ? $size : null,
            'shipping_line_name' => ((array) ($shipment['carrier'] ?? []))['name'] ?? null,
            'last_location' => ((array) ($last['location'] ?? []))['name'] ?? null,
            'current_vessel_name' => ((array) ($last['vessel'] ?? []))['name'] ?? null,
            'current_voyage_number' => $last['voyage'] ?? null,
            'loading_port' => self::legName($route, 'port_of_loading'),
            'discharging_port' => self::legName($route, 'port_of_discharge'),
            'eta_final_destination' => self::routeDate($route, 'port_of_discharge', ['expected_date', 'date'])?->format(DATE_ATOM),
        ], static fn ($value) => $value !== null && $value !== '');
    }

    /** @param array<string, mixed> $route */
    private static function legName(array $route, string $leg): ?string
    {
        return ((array) (((array) ($route[$leg] ?? []))['location'] ?? []))['name'] ?? null;
    }

    /** @param array<string, mixed> $location */
    private static function locodeOf(array $location): ?string
    {
        $code = strtoupper((string) ($location['code'] ?? ''));

        return preg_match('/^[A-Z]{5}$/', $code) === 1 ? $code : null;
    }

    /**
     * @param  array<string, mixed>  $route
     * @param  list<string>  $keys
     */
    private static function routeDate(array $route, string $leg, array $keys): ?DateTimeImmutable
    {
        $node = (array) ($route[$leg] ?? []);
        foreach ($keys as $key) {
            $date = self::parseDate($node[$key] ?? null);
            if ($date !== null) {
                return $date;
            }
        }

        return null;
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
