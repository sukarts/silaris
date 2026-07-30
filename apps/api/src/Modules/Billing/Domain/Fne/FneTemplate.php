<?php

declare(strict_types=1);

namespace Silaris\Modules\Billing\Domain\Fne;

/**
 * Gabarit FNE d'une facture — la DGI en distingue quatre.
 *
 * Le gabarit décide des champs obligatoires : le NCC du client en B2B, la
 * devise étrangère et son taux en B2F. Le choisir de travers fait rejeter la
 * facture par la DGI. La règle suit la nature de la facture :
 *
 *  - B2F si la facture est libellée en devise étrangère (fret d'un donneur
 *    d'ordre à l'international) — elle exige devise et taux de change ;
 *  - B2B si le client porte un NCC — une entreprise identifiée à la DGI ;
 *  - B2C sinon — un particulier.
 *
 * Le B2G (administration) ne se devine pas d'un tiers : il reste un choix
 * explicite, non déduit ici.
 */
enum FneTemplate: string
{
    case B2B = 'B2B';
    case B2C = 'B2C';
    case B2F = 'B2F';
    case B2G = 'B2G';

    /** Devise nationale : hors d'elle, la facture est internationale (B2F). */
    private const HOME_CURRENCY = 'XOF';

    public static function decide(string $currencyCode, ?string $clientNcc): self
    {
        if (strtoupper($currencyCode) !== self::HOME_CURRENCY) {
            return self::B2F;
        }

        return $clientNcc !== null && trim($clientNcc) !== '' ? self::B2B : self::B2C;
    }

    /** Le NCC du client est exigé par la DGI pour ce seul gabarit. */
    public function requiresClientNcc(): bool
    {
        return $this === self::B2B;
    }

    /** La devise étrangère et son taux ne se renseignent qu'à l'international. */
    public function requiresForeignCurrency(): bool
    {
        return $this === self::B2F;
    }
}
