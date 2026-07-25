<?php

declare(strict_types=1);

namespace Silaris\Modules\Shared\Domain\ValueObject;

use InvalidArgumentException;

/** Volume en mètres cubes — litres en interne. */
final readonly class VolumeM3
{
    private function __construct(public int $liters) {}

    public static function fromM3(string|int|float $m3): self
    {
        $liters = (int) round(((float) $m3) * 1000);
        if ($liters < 0) {
            throw new InvalidArgumentException('Volume négatif interdit');
        }

        return new self($liters);
    }

    public function toM3(): float
    {
        return $this->liters / 1000;
    }

    /** Poids taxable aérien IATA : volume / 6000 cm³ par kg (≈ 166,667 kg/m³). */
    public function airChargeableWeight(): WeightKg
    {
        return WeightKg::fromKg($this->liters / 6);
    }
}
