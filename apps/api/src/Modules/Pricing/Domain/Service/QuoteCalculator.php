<?php

declare(strict_types=1);

namespace Silaris\Modules\Pricing\Domain\Service;

use DateTimeImmutable;

/**
 * Moteur de cotation — applique les grilles (vente + achat si disponible) à une
 * spécification de marchandise. Règles : w/m maritime, poids taxable aérien,
 * tranches de poids, minimums de perception, pourcentage sur valeur (assurance).
 */
final readonly class QuoteCalculator
{
    public function __construct(private TariffProvider $tariffs) {}

    /** @return list<CalculatedLine> */
    public function calculate(
        string $mode,
        string $originLocode,
        string $destinationLocode,
        CargoSpec $cargo,
        ?DateTimeImmutable $date = null,
        ?string $partyId = null,
    ): array {
        $date ??= new DateTimeImmutable;

        $sellLines = $this->tariffs->linesFor($mode, $originLocode, $destinationLocode, 'sell', $date, $partyId);
        $buyLines = $this->tariffs->linesFor($mode, $originLocode, $destinationLocode, 'buy', $date);

        $result = [];
        foreach ($sellLines as $line) {
            $calculated = $this->apply($line, $mode, $cargo);
            if ($calculated === null) {
                continue;
            }

            // Coût d'achat correspondant (même service + même unité) si présent.
            $buyTotal = null;
            foreach ($buyLines as $buy) {
                if ($buy->serviceCode === $line->serviceCode && $buy->unit === $line->unit
                    && $buy->containerSizeType === $line->containerSizeType) {
                    $buyTotal = $this->apply($buy, $mode, $cargo)?->total;
                    break;
                }
            }

            $result[] = new CalculatedLine(
                serviceCode: $calculated->serviceCode,
                description: $calculated->description,
                unit: $calculated->unit,
                quantity: $calculated->quantity,
                unitPrice: $calculated->unitPrice,
                total: $calculated->total,
                currency: $calculated->currency,
                minimumApplied: $calculated->minimumApplied,
                buyTotal: $buyTotal,
            );
        }

        return $result;
    }

    private function apply(TariffLineDto $line, string $mode, CargoSpec $cargo): ?CalculatedLine
    {
        $quantity = match ($line->unit) {
            'container' => $line->containerSizeType === null
                ? (float) $cargo->totalContainers()
                : (float) ($cargo->containers[$line->containerSizeType] ?? 0),
            'kg' => $mode === 'air' ? $cargo->airChargeableKg() : $cargo->grossWeightKg,
            'm3' => $cargo->volumeM3,
            'wm' => $cargo->seaWmUnits(),
            'flat' => 1.0,
            'percent' => $cargo->declaredValue,
            default => 0.0,
        };

        if ($quantity <= 0) {
            return null;
        }

        // Tranche de poids non applicable → ligne ignorée.
        if ($line->unit === 'kg') {
            $kg = $mode === 'air' ? $cargo->airChargeableKg() : $cargo->grossWeightKg;
            if (($line->weightFromKg !== null && $kg < $line->weightFromKg)
                || ($line->weightToKg !== null && $kg >= $line->weightToKg)) {
                return null;
            }
        }

        $total = $line->unit === 'percent'
            ? round($quantity * $line->unitPrice / 100, 2)
            : round($quantity * $line->unitPrice, 2);

        $minimumApplied = false;
        if ($line->minimum !== null && $total < $line->minimum) {
            $total = $line->minimum;
            $minimumApplied = true;
        }

        return new CalculatedLine(
            serviceCode: $line->serviceCode,
            description: $line->description,
            unit: $line->unit,
            quantity: $quantity,
            unitPrice: $line->unitPrice,
            total: $total,
            currency: $line->currency,
            minimumApplied: $minimumApplied,
        );
    }
}
