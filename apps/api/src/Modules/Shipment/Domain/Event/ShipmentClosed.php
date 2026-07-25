<?php

declare(strict_types=1);

namespace Silaris\Modules\Shipment\Domain\Event;

use DateTimeImmutable;
use Silaris\Modules\Shared\Domain\DomainEvent;

final readonly class ShipmentClosed implements DomainEvent
{
    public function __construct(
        public string $shipmentId,
        public string $closedBy,
        public DateTimeImmutable $at,
    ) {}

    public function eventType(): string
    {
        return 'shipment.closed';
    }

    public function aggregateId(): string
    {
        return $this->shipmentId;
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->at;
    }

    public function payload(): array
    {
        return ['closed_by' => $this->closedBy];
    }
}
