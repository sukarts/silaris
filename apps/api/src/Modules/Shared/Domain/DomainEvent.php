<?php

declare(strict_types=1);

namespace Silaris\Modules\Shared\Domain;

use DateTimeImmutable;

/**
 * Événement de domaine — fait métier passé, immuable.
 */
interface DomainEvent
{
    public function eventType(): string;

    public function aggregateId(): string;

    public function occurredAt(): DateTimeImmutable;

    /** @return array<string, mixed> Sérialisation pour l'outbox. */
    public function payload(): array;
}
