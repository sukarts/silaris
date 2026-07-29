<?php

declare(strict_types=1);

namespace Silaris\Modules\Pricing\Domain\Service;

/**
 * Droits et taxes à l'import, sur la valeur CAF.
 *
 * Le droit de douane et la TVA dépendent de la position tarifaire ; les
 * redevances communautaires, elles, sont uniformes. La TVA porte sur la valeur
 * CAF augmentée du droit et des redevances — elle ne se calcule donc qu'en
 * dernier, ce qu'une addition de pourcentages ferait manquer.
 *
 * Les taux uniformes ci-dessous se retrouvent dans le tarif officiel : leur
 * somme, 2,27 %, est celle que le fichier du guichet unique laisse apparaître
 * entre le taux global et la somme du droit et de la TVA, quelle que soit la
 * bande tarifaire.
 */
final class CustomsDutyCalculator
{
    /** Redevances assises sur la valeur CAF, indépendantes de la marchandise. */
    private const COMMUNITY_LEVIES = [
        'RSTA' => 1.0,   // Redevance statistique
        'PCS' => 0.8,    // Prélèvement communautaire de solidarité (UEMOA)
        'PCC' => 0.5,    // Prélèvement communautaire (CEDEAO)
        'PUA' => 0.2,    // Prélèvement Union africaine
    ];

    /** Redevance informatique du système Sydam, part fixe par déclaration. */
    private const SYDAM_FLAT = 5000.0;

    /**
     * Régime appliqué au calcul : il dit lesquels des droits sont exigibles.
     *
     * @param  array{duty_applies: bool, vat_applies: bool, levies_apply: bool}  $regime
     * @return array{lines: array<string, float>, taxable_base: float, total: float}
     */
    public static function compute(float $customsValue, float $dutyRate, float $vatRate, ?array $regime = null): array
    {
        // Sans régime précisé, la mise à la consommation : tout est dû.
        $dutyApplies = $regime['duty_applies'] ?? true;
        $vatApplies = $regime['vat_applies'] ?? true;
        $leviesApply = $regime['levies_apply'] ?? true;

        $duty = $dutyApplies ? round($customsValue * $dutyRate / 100, 2) : 0.0;

        $levies = [];
        foreach (self::COMMUNITY_LEVIES as $code => $rate) {
            $levies[$code] = $leviesApply ? round($customsValue * $rate / 100, 2) : 0.0;
        }

        // La TVA frappe la valeur en douane majorée du droit et des redevances.
        $taxableBase = $customsValue + $duty + array_sum($levies);
        $vat = $vatApplies ? round($taxableBase * $vatRate / 100, 2) : 0.0;

        $lines = [
            'DD' => $duty,
            'RSTA' => $levies['RSTA'],
            'PCS' => $levies['PCS'],
            'PUA' => $levies['PUA'],
            'PCC' => $levies['PCC'],
            // Le RPI ne frappe que certaines marchandises : laissé à la saisie.
            'RPI' => 0.0,
            'TVA' => $vat,
            'TS_SYDAM' => self::SYDAM_FLAT,
        ];

        return [
            'lines' => $lines,
            'taxable_base' => round($taxableBase, 2),
            'total' => round(array_sum($lines), 2),
        ];
    }
}
