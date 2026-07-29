<?php

declare(strict_types=1);

namespace Silaris\Modules\Shipment\Domain\Exception;

use Silaris\Modules\Shared\Domain\Exception\DomainException;

/** Avancement d'un dossier dont l'ouverture sans cotation n'est pas validée. */
final class QuoteWaiverPending extends DomainException
{
    public static function make(string $status): self
    {
        return new self($status === 'rejected'
            ? "L'ouverture sans cotation a été refusée : rattachez une cotation acceptée pour poursuivre."
            : 'Le dossier attend la validation de la direction pour son ouverture sans cotation.');
    }

    public function errorCode(): string
    {
        return 'shipment.quote_waiver_pending';
    }
}
