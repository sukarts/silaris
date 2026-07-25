<?php

declare(strict_types=1);

namespace Silaris\Modules\Shipment\Domain\Event;

use DateTimeImmutable;
use Silaris\Modules\Shared\Domain\DomainEvent;

final readonly class WorkflowStepAdvanced implements DomainEvent
{
    public function __construct(
        public string $shipmentId,
        public string $fromStep,
        public string $toStep,
        public bool $automatic,
        public DateTimeImmutable $at,
    ) {}

    public function eventType(): string
    {
        return 'shipment.step_advanced';
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
        return ['from' => $this->fromStep, 'to' => $this->toStep, 'automatic' => $this->automatic];
    }
}
