<?php

declare(strict_types=1);

namespace Silaris\Modules\Shipment\Domain\Repository;

use Silaris\Modules\Shipment\Domain\Model\Shipment;

/**
 * Port de persistance de l'agrégat Shipment — implémenté en Infrastructure (Eloquent).
 */
interface ShipmentRepository
{
    public function get(string $id): Shipment;

    public function findByReference(string $reference): ?Shipment;

    public function save(Shipment $shipment): void;

    public function nextReference(string $branchCode, int $year): string;
}
