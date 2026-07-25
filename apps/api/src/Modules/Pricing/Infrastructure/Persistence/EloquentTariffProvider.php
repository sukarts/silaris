<?php

declare(strict_types=1);

namespace Silaris\Modules\Pricing\Infrastructure\Persistence;

use DateTimeImmutable;
use Silaris\Modules\Pricing\Domain\Service\TariffLineDto;
use Silaris\Modules\Pricing\Domain\Service\TariffProvider;
use Silaris\Modules\Pricing\Infrastructure\Persistence\Model\TariffModel;

final class EloquentTariffProvider implements TariffProvider
{
    public function linesFor(
        string $mode,
        string $originLocode,
        string $destinationLocode,
        string $side,
        DateTimeImmutable $date,
        ?string $partyId = null,
    ): array {
        $tariffs = TariffModel::with('lines')
            ->where('is_active', true)
            ->where('side', $side)
            ->where(fn ($q) => $q->where('mode', $mode)->orWhere('mode', 'any'))
            ->where(fn ($q) => $q->whereNull('origin_locode')->orWhere('origin_locode', $originLocode))
            ->where(fn ($q) => $q->whereNull('destination_locode')->orWhere('destination_locode', $destinationLocode))
            ->whereDate('valid_from', '<=', $date->format('Y-m-d'))
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhereDate('valid_to', '>=', $date->format('Y-m-d')))
            // Grille dédiée client prioritaire sur grille générale.
            ->where(fn ($q) => $q->whereNull('party_id')->when($partyId, fn ($qq) => $qq->orWhere('party_id', $partyId)))
            ->orderByRaw('(party_id IS NOT NULL) DESC')
            ->get();

        $lines = [];
        $seenServices = [];
        foreach ($tariffs as $tariff) {
            foreach ($tariff->lines as $line) {
                $key = "{$line->service_code}:{$line->unit}:{$line->container_size_type}";
                if (isset($seenServices[$key])) {
                    continue; // la grille client (triée en premier) l'emporte
                }
                $seenServices[$key] = true;
                $lines[] = new TariffLineDto(
                    serviceCode: $line->service_code,
                    description: $line->description,
                    unit: $line->unit,
                    containerSizeType: $line->container_size_type,
                    unitPrice: (float) $line->unit_price,
                    currency: $line->currency_code,
                    minimum: $line->minimum === null ? null : (float) $line->minimum,
                    weightFromKg: $line->weight_from_kg === null ? null : (float) $line->weight_from_kg,
                    weightToKg: $line->weight_to_kg === null ? null : (float) $line->weight_to_kg,
                    side: $tariff->side,
                );
            }
        }

        return $lines;
    }
}
