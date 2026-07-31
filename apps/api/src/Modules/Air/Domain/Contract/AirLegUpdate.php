<?php

declare(strict_types=1);

namespace Silaris\Modules\Air\Domain\Contract;

use DateTimeImmutable;

/**
 * Heures réelles d'un segment de vol, rapprochées d'un segment de la LTA par
 * son numéro de vol, à défaut par le couple origine/destination.
 */
final readonly class AirLegUpdate
{
    public function __construct(
        public ?string $flightNumber,
        public ?string $originIata,
        public ?string $destinationIata,
        public ?DateTimeImmutable $actualDepartureAt = null,
        public ?DateTimeImmutable $actualArrivalAt = null,
    ) {}
}
