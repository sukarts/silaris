<?php

declare(strict_types=1);

namespace Silaris\Modules\Pricing\Domain\Service;

final readonly class CalculatedLine
{
    public function __construct(
        public string $serviceCode,
        public string $description,
        public string $unit,
        public float $quantity,
        public float $unitPrice,
        public float $total,
        public string $currency,
        public bool $minimumApplied,
        public ?float $buyTotal = null,
    ) {}
}
