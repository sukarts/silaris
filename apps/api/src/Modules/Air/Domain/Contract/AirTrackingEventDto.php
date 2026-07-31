<?php

declare(strict_types=1);

namespace Silaris\Modules\Air\Domain\Contract;

use DateTimeImmutable;

/** Mouvement de vol normalisé — une ligne de l'historique d'acheminement. */
final readonly class AirTrackingEventDto
{
    /**
     * @param  string  $status  État normalisé au moment du mouvement.
     * @param  string|null  $rawEvent  Code brut du fournisseur (RCS, DEP, ARR, RCF, DLV…).
     * @param  array<string, mixed>  $rawPayload
     */
    public function __construct(
        public string $status,
        public ?string $rawEvent,
        public ?string $locationIata,
        public ?string $flightNumber,
        public DateTimeImmutable $occurredAt,
        public array $rawPayload = [],
    ) {}
}
