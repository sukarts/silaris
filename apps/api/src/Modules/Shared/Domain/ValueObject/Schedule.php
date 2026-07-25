<?php

declare(strict_types=1);

namespace Silaris\Modules\Shipment\Domain\ValueObject;

use DateTimeImmutable;

/**
 * Planning d'un dossier : ETD/ETA estimés, ATD/ATA réels, ETA initiale (dérive).
 */
final readonly class Schedule
{
    public function __construct(
        public ?DateTimeImmutable $etd = null,
        public ?DateTimeImmutable $eta = null,
        public ?DateTimeImmutable $atd = null,
        public ?DateTimeImmutable $ata = null,
        public ?DateTimeImmutable $etaInitial = null,
    ) {}

    public function withNewEta(DateTimeImmutable $eta): self
    {
        return new self($this->etd, $eta, $this->atd, $this->ata, $this->etaInitial ?? $this->eta);
    }

    public function withActualDeparture(DateTimeImmutable $atd): self
    {
        return new self($this->etd, $this->eta, $atd, $this->ata, $this->etaInitial);
    }

    public function withActualArrival(DateTimeImmutable $ata): self
    {
        return new self($this->etd, $this->eta, $this->atd, $ata, $this->etaInitial);
    }

    /** Retard en heures par rapport à l'ETA initiale — 0 si pas de dérive. */
    public function delayHours(): int
    {
        if ($this->etaInitial === null || $this->eta === null || $this->eta <= $this->etaInitial) {
            return 0;
        }

        return intdiv($this->eta->getTimestamp() - $this->etaInitial->getTimestamp(), 3600);
    }
}
