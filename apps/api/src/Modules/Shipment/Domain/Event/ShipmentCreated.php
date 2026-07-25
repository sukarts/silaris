<?php

declare(strict_types=1);

namespace Silaris\Modules\Shipment\Domain\Event;

use DateTimeImmutable;
use Silaris\Modules\Shared\Domain\DomainEvent;

final readonly class ShipmentCreated implements DomainEvent
{
    public function __construct(
        public string $shipmentId,
        public string $reference,
        public string $clientId,
        public DateTimeImmutable $at,
    ) {}

    public function eventType(): string
    {
        return 'shipment.created';
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
        return ['reference' => $this->reference, 'client_id' => $this->clientId];
    }
}
