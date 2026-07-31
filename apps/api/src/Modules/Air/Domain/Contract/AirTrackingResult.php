<?php

declare(strict_types=1);

namespace Silaris\Modules\Air\Domain\Contract;

use DateTimeImmutable;

/**
 * Relevé de suivi aérien normalisé — sortie unique de tout fournisseur.
 */
final readonly class AirTrackingResult
{
    /**
     * @param  string  $status  État normalisé : booked|en_route|landed|delivered|unknown.
     * @param  list<AirLegUpdate>  $legs  Segments avec leurs heures réelles.
     * @param  list<AirTrackingEventDto>  $events  Historique des mouvements de vol.
     */
    public function __construct(
        public string $status,
        public array $legs = [],
        public array $events = [],
        public ?string $lastLocationIata = null,
        public ?DateTimeImmutable $eta = null,
        public ?string $shipsgoRef = null,
    ) {}
}
