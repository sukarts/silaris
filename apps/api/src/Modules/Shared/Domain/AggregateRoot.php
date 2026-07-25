<?php

declare(strict_types=1);

namespace Silaris\Modules\Shared\Domain;

/**
 * Racine d'agrégat — enregistre les événements de domaine émis pendant
 * une opération métier ; l'Application layer les collecte après persistance
 * (pattern collect-then-dispatch, jamais de dispatch dans le domaine).
 */
abstract class AggregateRoot
{
    /** @var list<DomainEvent> */
    private array $recordedEvents = [];

    protected function record(DomainEvent $event): void
    {
        $this->recordedEvents[] = $event;
    }

    /** @return list<DomainEvent> */
    public function releaseEvents(): array
    {
        $events = $this->recordedEvents;
        $this->recordedEvents = [];

        return $events;
    }
}
