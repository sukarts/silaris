<?php

declare(strict_types=1);

namespace Silaris\Modules\Pricing\Domain\Service;

final readonly class TariffLineDto
{
    public function __construct(
        public string $serviceCode,
        public string $description,
        public string $unit,
        public ?string $containerSizeType,
        public float $unitPrice,
        public string $currency,
        public ?float $minimum,
        public ?float $weightFromKg,
        public ?float $weightToKg,
        public string $side,
    ) {}
}
