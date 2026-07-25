<?php

declare(strict_types=1);

namespace Silaris\Modules\Tracking\Domain\Contract;

/**
 * Port unique des connecteurs compagnies — chaque compagnie l'implémente.
 * Ajout d'une compagnie = 1 adapter + 1 entrée registry, zéro modification du cœur.
 */
interface CarrierTrackingProvider
{
    public function trackContainer(string $containerNumber): TrackingResult;

    public function trackBillOfLading(string $blNumber): TrackingResult;

    /** @return list<string> capacités : container_tracking, bl_tracking, schedules */
    public function capabilities(): array;
}
