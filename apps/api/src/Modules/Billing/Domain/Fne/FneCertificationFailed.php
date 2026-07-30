<?php

declare(strict_types=1);

namespace Silaris\Modules\Billing\Domain\Fne;

use Silaris\Modules\Shared\Domain\Exception\DomainException;

/**
 * La certification FNE n'a pas abouti.
 *
 * Trois causes distinctes, qu'il faut savoir séparer : la société n'est pas
 * configurée pour la FNE, la facture ne s'y prête pas encore (brouillon, NCC
 * manquant), ou la DGI a refusé. Le message doit dire laquelle, sans quoi
 * l'exploitant ne sait pas s'il doit corriger la fiche, la facture, ou attendre.
 */
final class FneCertificationFailed extends DomainException
{
    private function __construct(string $message, private readonly string $silarisCode)
    {
        parent::__construct($message);
    }

    public static function notConfigured(): self
    {
        return new self(
            "La société n'est pas configurée pour la Facture Normalisée Électronique : renseignez le NCC, le point de vente, l'établissement et la clé d'API de la DGI.",
            'fne.not_configured',
        );
    }

    public static function invoiceNotValidated(): self
    {
        return new self(
            'Seule une facture validée, portant son numéro légal, peut être certifiée par la DGI.',
            'fne.invoice_not_validated',
        );
    }

    public static function alreadyCertified(string $reference): self
    {
        return new self("Cette facture est déjà certifiée sous le numéro fiscal {$reference}.", 'fne.already_certified');
    }

    public static function clientNccRequired(): self
    {
        return new self(
            'Le NCC du client est obligatoire pour une facture B2B : renseignez-le sur sa fiche avant de certifier.',
            'fne.client_ncc_required',
        );
    }

    public static function foreignRateRequired(): self
    {
        return new self(
            'Une facture en devise étrangère (B2F) exige le taux de change vers le franc CFA.',
            'fne.foreign_rate_required',
        );
    }

    /** La DGI a répondu, mais a refusé — son motif est repris tel quel. */
    public static function rejected(string $reason): self
    {
        return new self("La DGI a refusé la certification : {$reason}", 'fne.rejected');
    }

    /** La plateforme n'a pas répondu — réseau, indisponibilité, délai dépassé. */
    public static function unreachable(string $detail): self
    {
        return new self("La plateforme FNE de la DGI est injoignable : {$detail}", 'fne.unreachable');
    }

    public function errorCode(): string
    {
        return $this->silarisCode;
    }
}
