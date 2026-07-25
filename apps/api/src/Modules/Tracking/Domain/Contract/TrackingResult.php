<?php

declare(strict_types=1);

namespace Silaris\Modules\Tracking\Domain\Contract;

use DateTimeImmutable;

final readonly class TrackingResult
{
    /** @param list<TrackingEventDto> $events */
    public function __construct(
        public array $events,
        public ?DateTimeImmutable $eta = null,
        public ?DateTimeImmutable $etd = null,
        public ?DateTimeImmutable $ata = null,
        public ?DateTimeImmutable $atd = null,
    ) {}
}
