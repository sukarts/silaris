<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Silaris\Modules\Pricing\Domain\Service\CustomsDutyCalculator;

uses(RefreshDatabase::class);

function seedTariff(string $code = '8703230000', float $duty = 20, float $vat = 18): void
{
    DB::table('customs_tariffs')->updateOrInsert(['hs_code' => $code], [
        'description' => 'Véhicules de tourisme, cylindrée 1500 à 3000 cm³',
        'duty_rate' => $duty, 'vat_rate' => $vat,
        'all_in_rate' => null, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

it('applique la TVA sur la valeur majorée des droits, pas sur la valeur nue', function (): void {
    // 10 000 000 F CAF, droit 20 %, TVA 18 %.
    $result = CustomsDutyCalculator::compute(10_000_000, 20, 18);

    expect($result['lines']['DD'])->toBe(2_000_000.0)
        ->and($result['lines']['RSTA'])->toBe(100_000.0)
        ->and($result['lines']['PCS'])->toBe(80_000.0)
        ->and($result['lines']['PCC'])->toBe(50_000.0)
        ->and($result['lines']['PUA'])->toBe(20_000.0);

    // Base = 10 000 000 + 2 000 000 + 250 000 = 12 250 000 ; TVA = 2 205 000.
    expect($result['taxable_base'])->toBe(12_250_000.0)
        ->and($result['lines']['TVA'])->toBe(2_205_000.0);
});

it('retrouve le taux global du tarif officiel', function (): void {
    // Le fichier du guichet unique annonce 44,28 % pour un droit de 20 %.
    $result = CustomsDutyCalculator::compute(1_000_000, 20, 18);
    $withoutSydam = $result['total'] - $result['lines']['TS_SYDAM'];

    expect(round($withoutSydam / 1_000_000 * 100, 2))->toBe(44.55);
});

it('chiffre les droits depuis une position tarifaire', function (): void {
    $ids = seedCore();
    seedTariff();

    $response = $this->withToken(tokenFor($ids['user_sales_manager']))
        ->postJson('/api/v1/customs-tariffs/compute', [
            'hs_code' => '8703.23.00.00', 'customs_value' => 10_000_000,
        ])->assertOk()->json();

    // Le JSON rend 20 pour 20.0 : on compare les valeurs, pas leur type.
    expect((float) $response['duty_rate'])->toBe(20.0)
        ->and((float) $response['lines']['DD'])->toBe(2_000_000.0)
        ->and((float) $response['lines']['TVA'])->toBe(2_205_000.0);
});

it('accepte une position tronquée en retombant sur la première complète', function (): void {
    $ids = seedCore();
    seedTariff();

    // L'exploitant ne connaît souvent que le chapitre et la sous-position.
    $this->withToken(tokenFor($ids['user_sales_manager']))
        ->postJson('/api/v1/customs-tariffs/compute', ['hs_code' => '870323', 'customs_value' => 1_000_000])
        ->assertOk()->assertJsonPath('hs_code', '8703230000');
});

it('refuse une position absente du tarif', function (): void {
    $ids = seedCore();

    $this->withToken(tokenFor($ids['user_sales_manager']))
        ->postJson('/api/v1/customs-tariffs/compute', ['hs_code' => '9999999999', 'customs_value' => 1_000])
        ->assertStatus(422)->assertJsonPath('message', fn (string $m) => str_contains($m, 'introuvable'));
});

it('cherche une position par son libellé comme par ses chiffres', function (): void {
    $ids = seedCore();
    seedTariff();
    $token = tokenFor($ids['user_sales_manager']);

    expect($this->withToken($token)->getJson('/api/v1/customs-tariffs?search=8703')->json('data'))->toHaveCount(1);
    expect($this->withToken($token)->getJson('/api/v1/customs-tariffs?search=tourisme')->json('data'))->toHaveCount(1);
});

it('n\'exige aucun droit ivoirien sur une marchandise en transit', function (): void {
    $ids = seedCore();
    seedTariff();

    // Conteneur en transit vers Bamako : la Côte d'Ivoire n'est que le passage.
    $response = $this->withToken(tokenFor($ids['user_sales_manager']))
        ->postJson('/api/v1/customs-tariffs/compute', [
            'hs_code' => '8703230000', 'customs_value' => 10_000_000, 'customs_regime' => 'IM8',
        ])->assertOk()->json();

    expect((float) $response['lines']['DD'])->toBe(0.0)
        ->and((float) $response['lines']['TVA'])->toBe(0.0)
        // Les redevances de passage restent dues : la marchandise emprunte
        // bien le port et le corridor.
        ->and((float) $response['lines']['RSTA'])->toBe(100_000.0)
        ->and($response['regime_name'])->toBe('Transit');
});

it('exige tout sous mise à la consommation', function (): void {
    $ids = seedCore();
    seedTariff();

    $response = $this->withToken(tokenFor($ids['user_sales_manager']))
        ->postJson('/api/v1/customs-tariffs/compute', [
            'hs_code' => '8703230000', 'customs_value' => 10_000_000, 'customs_regime' => 'IM4',
        ])->assertOk()->json();

    expect((float) $response['lines']['DD'])->toBe(2_000_000.0)
        ->and((float) $response['lines']['TVA'])->toBe(2_205_000.0);
});

it('annule jusqu\'aux redevances sous exonération', function (): void {
    $ids = seedCore();
    seedTariff();

    $response = $this->withToken(tokenFor($ids['user_sales_manager']))
        ->postJson('/api/v1/customs-tariffs/compute', [
            'hs_code' => '8703230000', 'customs_value' => 10_000_000, 'customs_regime' => 'EXO',
        ])->assertOk()->json();

    // Seule la redevance informatique subsiste : la déclaration existe.
    expect((float) $response['total'])->toBe(5_000.0);
});

it('signale qu\'un régime suspensif ne fait que différer les droits', function (): void {
    $ids = seedCore();

    $regimes = $this->withToken(tokenFor($ids['user_sales_manager']))
        ->getJson('/api/v1/customs-regimes')->assertOk()->json('data');

    $suspensive = array_values(array_filter($regimes, fn ($r) => (bool) $r['is_suspensive']));
    expect(array_column($suspensive, 'code'))->toContain('IM8', 'IM5', 'IM7')
        ->and($suspensive[0]['note'])->not->toBeEmpty();
});
