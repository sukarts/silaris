<?php

declare(strict_types=1);

namespace Silaris\Modules\Pricing\Domain\Service;

/** Spécification de la marchandise à coter. */
final readonly class CargoSpec
{
    /** @param array<string, int> $containers ex. ['40HC' => 2] */
    public function __construct(
        public array $containers = [],
        public float $grossWeightKg = 0,
        public float $volumeM3 = 0,
        public float $declaredValue = 0,
        public string $declaredValueCurrency = 'USD',
    ) {}

    public function totalContainers(): int
    {
        return array_sum($this->containers);
    }

    /** Poids taxable aérien (kg) : max(brut, volume × 166.667). */
    public function airChargeableKg(): float
    {
        return max($this->grossWeightKg, $this->volumeM3 * 166.667);
    }

    /** Unités payantes LCL (w/m) : max(tonnes, m³). */
    public function seaWmUnits(): float
    {
        return max($this->grossWeightKg / 1000, $this->volumeM3);
    }
}
