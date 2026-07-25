<?php

declare(strict_types=1);

namespace Silaris\Modules\CarrierConnect\Infrastructure\Connector;

use DateTimeImmutable;
use Silaris\Modules\Tracking\Domain\Contract\CarrierTrackingProvider;
use Silaris\Modules\Tracking\Domain\Contract\TrackingEventDto;
use Silaris\Modules\Tracking\Domain\Contract\TrackingResult;

/**
 * Connecteur de simulation — environnements hors production sans credentials.
 * Génère une traversée déterministe (fonction du numéro suivi) : les événements
 * "passés" à la date courante sont retournés, la traversée avance donc dans le
 * temps comme une vraie. JAMAIS résolu en production.
 */
class FakeCarrierConnector implements CarrierTrackingProvider
{
    /** [code DCSA, statut brut, port, jour relatif] */
    private const VOYAGE_PLAN = [
        ['GTIN', 'Export received at CY', 'CNSHA', 0],
        ['LOAD', 'Loaded on vessel', 'CNSHA', 3],
        ['DEPA', 'Vessel departure', 'CNSHA', 4],
        ['DEPA', 'Vessel departure', 'SGSIN', 13],
        ['TRSH', 'Transshipment loaded', 'LKCMB', 20],
        ['ARRI', 'Vessel arrival', 'CIABJ', 28],
        ['DISC', 'Discharged from vessel', 'CIABJ', 29],
        ['GTOT', 'Import gate out', 'CIABJ', 32],
    ];

    public function trackContainer(string $containerNumber): TrackingResult
    {
        // Ancrage déterministe : début de traversée dérivé du numéro (0..9 jours avant J-25),
        // à heure FIXE — les timestamps sont stables entre deux exécutions (déduplication).
        $anchor = (new DateTimeImmutable('-'.(25 + (crc32($containerNumber) % 10)).' days'))->setTime(8, 0);
        $now = new DateTimeImmutable;

        $events = [];
        $eta = null;
        foreach (self::VOYAGE_PLAN as [$code, $raw, $port, $day]) {
            $at = $anchor->modify("+{$day} days")->setTime(6 + ($day * 3) % 12, (crc32($containerNumber.$day) % 60));
            if ($code === 'ARRI') {
                $eta = $at;
            }
            if ($at <= $now) {
                $events[] = new TrackingEventDto($code, $raw, $port, $at, '9857169', ['simulated' => true]);
            }
        }

        return new TrackingResult(events: $events, eta: $eta);
    }

    public function trackBillOfLading(string $blNumber): TrackingResult
    {
        return $this->trackContainer($blNumber);
    }

    public function capabilities(): array
    {
        return ['container_tracking', 'bl_tracking'];
    }
}
