<?php

declare(strict_types=1);

namespace Silaris\Modules\Billing\Domain\Fne;

/**
 * Code de taxe FNE d'une ligne.
 *
 * La DGI ne reçoit pas un taux mais un code, et elle en attend un sur chaque
 * ligne : TVA à 18 %, TVAB à 9 %, et pour ce qui n'est pas soumis à TVA le code
 * d'exonération légale TVAD (0 %). Une facture de transit en est pleine — droits
 * de douane, débours refacturés à l'identique : hors champ de la TVA par la loi,
 * donc TVAD, jamais l'absence de code, qu'une facture certifiée observée sur la
 * plateforme confirme.
 *
 * L'exonération conventionnelle TVAC, elle, suppose une convention que la ligne
 * ne porte pas : on ne la déduit pas d'un taux.
 */
final class FneTaxCode
{
    /**
     * Code de taxe d'une ligne, d'après son taux de TVA. Toujours un code : une
     * ligne non soumise à TVA relève de l'exonération légale TVAD, pas du vide.
     *
     * @return list<string>
     */
    public static function forRate(?float $ratePercent): array
    {
        return match (true) {
            $ratePercent !== null && abs($ratePercent - 18.0) < 0.001 => ['TVA'],
            $ratePercent !== null && abs($ratePercent - 9.0) < 0.001 => ['TVAB'],
            default => ['TVAD'],
        };
    }
}
