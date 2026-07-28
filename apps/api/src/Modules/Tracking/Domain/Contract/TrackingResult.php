<?php

declare(strict_types=1);

namespace Silaris\Modules\Tracking\Domain\Contract;

use DateTimeImmutable;

final readonly class TrackingResult
{
    /**
     * @param  list<TrackingEventDto>  $events
     * @param  list<string>  $containerNumbers  Conteneurs rattachés au connaissement.
     *                                          À l'import, le transitaire ne connaît
     *                                          souvent que le BL : c'est la compagnie
     *                                          qui lui apprend les conteneurs.
     */
    public function __construct(
        public array $events,
        public ?DateTimeImmutable $eta = null,
        public ?DateTimeImmutable $etd = null,
        public ?DateTimeImmutable $ata = null,
        public ?DateTimeImmutable $atd = null,
        public array $containerNumbers = [],
    ) {}
}
