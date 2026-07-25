<?php

declare(strict_types=1);

namespace Silaris\Modules\Shared\Application\Bus;

/**
 * Bus de requêtes — lecture seule, hors transaction, read models directs.
 */
interface QueryBus
{
    public function ask(object $query): mixed;
}
