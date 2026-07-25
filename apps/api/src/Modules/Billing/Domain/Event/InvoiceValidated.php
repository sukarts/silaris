<?php

declare(strict_types=1);

namespace Silaris\Modules\Billing\Domain\Event;

use DateTimeImmutable;
use Silaris\Modules\Shared\Domain\DomainEvent;

final readonly class InvoiceValidated implements DomainEvent
{
    public function __construct(
        public string $invoiceId,
        public string $number,
        public string $total,
        public string $currency,
        public string $clientId,
        public ?string $shipmentId,
        public DateTimeImmutable $at,
    ) {}

    public function eventType(): string
    {
        return 'invoice.validated';
    }

    public function aggregateId(): string
    {
        return $this->invoiceId;
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->at;
    }

    public function payload(): array
    {
        return [
            'number' => $this->number,
            'total' => $this->total,
            'currency' => $this->currency,
            'client_id' => $this->clientId,
            'shipment_id' => $this->shipmentId,
        ];
    }
}
