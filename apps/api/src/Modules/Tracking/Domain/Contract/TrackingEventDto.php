<?php

declare(strict_types=1);

namespace Silaris\Modules\Tracking\Domain\Contract;

use DateTimeImmutable;

/** Événement de tracking normalisé DCSA — sortie unique de tous les connecteurs. */
final readonly class TrackingEventDto
{
    public function __construct(
        public string $dcsaEventCode,
        public string $rawStatus,
        public ?string $locationLocode,
        public DateTimeImmutable $occurredAt,
        public ?string $vesselImo = null,
        public array $rawPayload = [],
    ) {}
}
