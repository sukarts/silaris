<?php

declare(strict_types=1);

namespace Silaris\Modules\Shipment\Domain\Event;

use DateTimeImmutable;
use Silaris\Modules\Shared\Domain\DomainEvent;

final readonly class DelayDetected implements DomainEvent
{
    public function __construct(
        public string $shipmentId,
        public int $delayHours,
        public DateTimeImmutable $newEta,
        public DateTimeImmutable $at,
    ) {}

    public function eventType(): string
    {
        return 'shipment.delay_detected';
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
        return ['delay_hours' => $this->delayHours, 'new_eta' => $this->newEta->format(DATE_ATOM)];
    }
}
