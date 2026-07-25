<?php

declare(strict_types=1);

namespace Silaris\Modules\Shared\Domain\ValueObject;

use InvalidArgumentException;

/** Poids en kilogrammes — grammes en interne, jamais de float. */
final readonly class WeightKg
{
    private function __construct(public int $grams) {}

    public static function fromKg(string|int|float $kg): self
    {
        $grams = (int) round(((float) $kg) * 1000);
        if ($grams < 0) {
            throw new InvalidArgumentException('Poids négatif interdit');
        }

        return new self($grams);
    }

    public function toKg(): float
    {
        return $this->grams / 1000;
    }

    public function greaterThan(self $other): bool
    {
        return $this->grams > $other->grams;
    }
}
