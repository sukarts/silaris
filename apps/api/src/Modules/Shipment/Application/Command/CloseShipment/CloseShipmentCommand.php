<?php

declare(strict_types=1);

namespace Silaris\Modules\Shipment\Application\Command\CloseShipment;

final readonly class CloseShipmentCommand
{
    public function __construct(
        public string $shipmentId,
        public string $closedBy,
    ) {}
}
