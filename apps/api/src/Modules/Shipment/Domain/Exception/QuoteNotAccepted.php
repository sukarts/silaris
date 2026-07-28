<?php

declare(strict_types=1);

namespace Silaris\Modules\Shipment\Domain\Exception;

use Silaris\Modules\Shared\Domain\Exception\DomainException;

/** Ouverture d'un dossier sans accord préalable du client sur la cotation. */
final class QuoteNotAccepted extends DomainException
{
    public static function missing(): self
    {
        return new self('Un dossier ne s\'ouvre que sur une cotation acceptée par le client.');
    }

    public static function notAcceptedYet(string $number, string $status): self
    {
        $label = match ($status) {
            'draft' => 'est encore au brouillon',
            'sent' => 'attend la réponse du client',
            'rejected' => 'a été refusée par le client',
            'expired' => 'a expiré',
            default => "n'est pas acceptée",
        };

        return new self("La cotation {$number} {$label}.");
    }

    public static function otherClient(string $number): self
    {
        return new self("La cotation {$number} appartient à un autre client.");
    }

    public static function alreadyUsed(string $number, string $reference): self
    {
        return new self("La cotation {$number} a déjà ouvert le dossier {$reference}.");
    }

    public function errorCode(): string
    {
        return 'shipment.quote_not_accepted';
    }
}
