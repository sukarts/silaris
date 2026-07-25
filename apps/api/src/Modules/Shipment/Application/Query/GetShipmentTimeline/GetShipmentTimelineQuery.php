<?php

declare(strict_types=1);

namespace Silaris\Modules\Shipment\Application\Query\GetShipmentTimeline;

final readonly class GetShipmentTimelineQuery
{
    public function __construct(
        public string $shipmentId,
        public bool $clientVisibleOnly = false,
    ) {}
}
