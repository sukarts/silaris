<?php

declare(strict_types=1);

namespace Silaris\Modules\Shared\Infrastructure\Bus;

use Silaris\Modules\Shared\Application\Bus\QueryBus;

final class SimpleQueryBus implements QueryBus
{
    use ResolvesHandlers;

    public function ask(object $query): mixed
    {
        return $this->resolveHandler($query, 'Handler', 'Query')->handle($query);
    }
}
