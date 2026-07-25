<?php

declare(strict_types=1);

namespace Silaris\Modules\Shared\Application\Bus;

/**
 * Bus de commandes — chaque commande est traitée par son Handler
 * (convention : FooCommand → FooHandler, même namespace), dans une transaction.
 */
interface CommandBus
{
    public function dispatch(object $command): mixed;
}
