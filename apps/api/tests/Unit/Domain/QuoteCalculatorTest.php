<?php

declare(strict_types=1);

use Silaris\Modules\Pricing\Domain\Service\CargoSpec;
use Silaris\Modules\Pricing\Domain\Service\QuoteCalculator;
use Silaris\Modules\Pricing\Domain\Service\TariffLineDto;
use Silaris\Modules\Pricing\Domain\Service\TariffProvider;

function fakeTariffs(array $sell, array $buy = []): TariffProvider
{
    return new class($sell, $buy) implements TariffProvider
    {
        public function __construct(private array $sell, private array $buy) {}

        public function linesFor(string $mode, string $o, string $d, string $side, DateTimeImmutable $date, ?string $partyId = null): array
        {
            return $side === 'sell' ? $this->sell : $this->buy;
        }
    };
}

function line(string $service, string $unit, float $price, ?string $size = null, ?float $min = null): TariffLineDto
{
    return new TariffLineDto($service, $service, $unit, $size, $price, 'USD', $min, null, null, 'sell');
}

test('fret par conteneur avec marge achat/vente', function (): void {
    $calculator = new QuoteCalculator(fakeTariffs(
        sell: [line('freight', 'container', 2450, '40HC')],
        buy: [line('freight', 'container', 1900, '40HC')],
    ));

    $result = $calculator->calculate('sea_fcl', 'CNSHA', 'CIABJ', new CargoSpec(containers: ['40HC' => 2]));

    expect($result)->toHaveCount(1)
        ->and($result[0]->total)->toBe(4900.0)
        ->and($result[0]->buyTotal)->toBe(3800.0);
});

test('poids taxable aérien : max(brut, volumétrique)', function (): void {
    // 100 kg brut mais 1 m³ → 166,667 kg taxables
    $spec = new CargoSpec(grossWeightKg: 100, volumeM3: 1);
    expect(round($spec->airChargeableKg(), 1))->toBe(166.7);

    $calculator = new QuoteCalculator(fakeTariffs([line('freight', 'kg', 3.5)]));
    $result = $calculator->calculate('air', 'FRCDG', 'CIABJ', $spec);
    expect(round($result[0]->total, 2))->toBe(round(166.667 * 3.5, 2));
});

test('w/m maritime LCL : max(tonnes, m³)', function (): void {
    $spec = new CargoSpec(grossWeightKg: 2000, volumeM3: 5); // 2 t vs 5 m³ → 5 unités payantes
    $calculator = new QuoteCalculator(fakeTariffs([line('freight', 'wm', 80)]));
    expect($calculator->calculate('sea_lcl', 'NLRTM', 'CIABJ', $spec)[0]->total)->toBe(400.0);
});

test('minimum de perception appliqué et signalé', function (): void {
    $calculator = new QuoteCalculator(fakeTariffs([line('freight', 'wm', 80, min: 500)]));
    $result = $calculator->calculate('sea_lcl', 'NLRTM', 'CIABJ', new CargoSpec(grossWeightKg: 1000, volumeM3: 1));

    expect($result[0]->total)->toBe(500.0)->and($result[0]->minimumApplied)->toBeTrue();
});

test('assurance en pourcentage de la valeur déclarée', function (): void {
    $calculator = new QuoteCalculator(fakeTariffs([line('insurance', 'percent', 0.4)]));
    $result = $calculator->calculate('sea_fcl', 'CNSHA', 'CIABJ', new CargoSpec(containers: ['40HC' => 1], declaredValue: 50000));
    expect($result[0]->total)->toBe(200.0);
});

test('quantité nulle → ligne absente', function (): void {
    $calculator = new QuoteCalculator(fakeTariffs([line('freight', 'container', 2450, '20GP')]));
    expect($calculator->calculate('sea_fcl', 'CNSHA', 'CIABJ', new CargoSpec(containers: ['40HC' => 2])))->toHaveCount(0);
});
