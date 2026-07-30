<?php

declare(strict_types=1);

namespace Silaris\Modules\Billing\Domain\Fne;

/**
 * Code de taxe FNE d'une ligne.
 *
 * La DGI ne reçoit pas un taux mais un code : TVA à 18 %, TVAB à 9 %. Une ligne
 * sans TVA — un débours douane, un poste refacturé à l'euro près — ne porte
 * aucun code : elle sort hors champ de la taxe, elle n'est pas taxée à zéro.
 *
 * Les deux exonérations à 0 % de la DGI (TVAC conventionnelle, TVAD légale) ne
 * se déduisent pas d'un taux : elles supposent un motif d'exonération que la
 * ligne ne porte pas. On ne les invente donc pas ici.
 */
final class FneTaxCode
{
    /**
     * Codes de taxe applicables à une ligne, d'après son taux de TVA.
     *
     * @return list<string> Vide si la ligne n'est pas soumise à TVA.
     */
    public static function forRate(?float $ratePercent): array
    {
        return match (true) {
            $ratePercent === null => [],
            abs($ratePercent - 18.0) < 0.001 => ['TVA'],
            abs($ratePercent - 9.0) < 0.001 => ['TVAB'],
            default => [],
        };
    }
}
