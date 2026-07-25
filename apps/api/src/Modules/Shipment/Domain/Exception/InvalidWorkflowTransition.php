<?php

declare(strict_types=1);

namespace Silaris\Modules\Shipment\Domain\Exception;

use Silaris\Modules\Shared\Domain\Exception\DomainException;

final class InvalidWorkflowTransition extends DomainException
{
    public static function between(string $from, string $to): self
    {
        return new self("Transition workflow interdite : {$from} → {$to}");
    }

    public function errorCode(): string
    {
        return 'shipment.invalid_workflow_transition';
    }
}
