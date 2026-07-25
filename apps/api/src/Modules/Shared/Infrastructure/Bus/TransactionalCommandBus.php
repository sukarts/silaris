<?php

declare(strict_types=1);

namespace Silaris\Modules\Shared\Infrastructure\Bus;

use Illuminate\Support\Facades\DB;
use Silaris\Modules\Shared\Application\Bus\CommandBus;

/**
 * Implémentation Laravel — handler résolu par convention, exécution transactionnelle.
 * Les événements de domaine sont publiés par le DomainEventPublisher
 * (outbox dans la même transaction, dispatch applicatif après commit).
 */
final class TransactionalCommandBus implements CommandBus
{
    use ResolvesHandlers;

    public function dispatch(object $command): mixed
    {
        $handler = $this->resolveHandler($command, 'Handler', 'Command');

        return DB::transaction(fn () => $handler->handle($command));
    }
}
