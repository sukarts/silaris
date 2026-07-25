<?php

declare(strict_types=1);

namespace Silaris\Modules\Shared\Infrastructure\Events;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Silaris\Modules\Shared\Domain\AggregateRoot;
use Silaris\Modules\Shared\Domain\DomainEvent;
use Silaris\Modules\Shared\Infrastructure\Tenancy\TenantContext;

/**
 * Publication des événements de domaine :
 *  1. écriture dans l'outbox (MÊME transaction que la donnée métier) ;
 *  2. dispatch applicatif (listeners) après commit uniquement.
 * Garantie : jamais d'événement sans donnée, jamais de donnée sans événement.
 */
final readonly class DomainEventPublisher
{
    public function __construct(
        private Dispatcher $dispatcher,
        private TenantContext $tenant,
    ) {}

    public function publishFrom(AggregateRoot $aggregate): void
    {
        foreach ($aggregate->releaseEvents() as $event) {
            $this->publish($event);
        }
    }

    public function publish(DomainEvent $event): void
    {
        DB::table('outbox_events')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $this->tenant->id(),
            'aggregate_type' => explode('.', $event->eventType())[0],
            'aggregate_id' => $event->aggregateId(),
            'event_type' => $event->eventType(),
            'payload' => json_encode($event->payload()),
            'occurred_at' => $event->occurredAt()->format('Y-m-d H:i:s.uP'),
        ]);

        DB::afterCommit(fn () => $this->dispatcher->dispatch($event));
    }
}
