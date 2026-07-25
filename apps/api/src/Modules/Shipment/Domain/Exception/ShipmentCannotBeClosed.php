<?php

declare(strict_types=1);

namespace Silaris\Modules\Shipment\Domain\Exception;

use Silaris\Modules\Shared\Domain\Exception\DomainException;

final class ShipmentCannotBeClosed extends DomainException
{
    /** @param list<string> $reasons */
    public static function because(array $reasons): self
    {
        return new self('Clôture impossible : '.implode(' ; ', $reasons));
    }

    public function errorCode(): string
    {
        return 'shipment.cannot_close';
    }
}
