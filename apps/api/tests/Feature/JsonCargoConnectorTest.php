<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Silaris\Modules\CarrierConnect\Infrastructure\CarrierRegistry;
use Silaris\Modules\CarrierConnect\Infrastructure\Connector\FakeCarrierConnector;
use Silaris\Modules\CarrierConnect\Infrastructure\Connector\JsonCargoConnector;
use Silaris\Modules\Shared\Infrastructure\Tenancy\TenantContext;
use Silaris\Modules\Tracking\Domain\Contract\CarrierUnavailable;

uses(RefreshDatabase::class);

function jsonCargoContext(): array
{
    $ids = seedCore();
    app(TenantContext::class)->set($ids['tenant']);
    Config::set('services.jsoncargo.api_key', 'test-key');
    Config::set('services.jsoncargo.base_url', 'https://api.jsoncargo.com/api/v1');

    return $ids;
}

it('résout JSONCargo pour un SCAC couvert quand la clé plateforme est présente', function (): void {
    jsonCargoContext();

    $connector = app(CarrierRegistry::class)->resolve('MSCU');

    expect($connector)->toBeInstanceOf(JsonCargoConnector::class);
});

it('retombe sur le simulateur hors production sans clé JSONCargo', function (): void {
    jsonCargoContext();
    Config::set('services.jsoncargo.api_key', null);

    expect(app(CarrierRegistry::class)->resolve('MSCU'))->toBeInstanceOf(FakeCarrierConnector::class);
});

it('traduit un instantané conteneur en résultat de tracking normalisé', function (): void {
    jsonCargoContext();
    DB::table('carrier_status_mappings')->insert([
        'id' => (string) Str::uuid7(),
        'carrier_scac' => 'JCGO', 'raw_status' => 'Discharged from vessel',
        'dcsa_event_code' => 'DISC', 'created_at' => now(), 'updated_at' => now(),
    ]);

    Http::fake([
        'api.jsoncargo.com/api/v1/containers/MEDU9091004*' => Http::response(['data' => [
            'container_id' => 'MEDU9091004',
            'container_status' => 'Discharged from vessel',
            'shipping_line_name' => 'Mediterranean Shipping Company',
            'last_location' => 'Abidjan',
            'last_movement_timestamp' => '2026-07-20 14:30',
            'atd_origin' => '2026-06-15 08:00',
            'eta_final_destination' => '2026-07-28 06:00',
            'current_vessel_name' => 'MSC AURELIA',
        ]]),
    ]);

    $result = app(CarrierRegistry::class)->resolve('MSCU')->trackContainer('MEDU9091004');

    expect($result->events)->toHaveCount(1)
        ->and($result->events[0]->dcsaEventCode)->toBe('DISC')
        ->and($result->events[0]->rawStatus)->toBe('Discharged from vessel')
        ->and($result->events[0]->rawPayload['current_vessel_name'])->toBe('MSC AURELIA')
        ->and($result->eta?->format('Y-m-d'))->toBe('2026-07-28')
        ->and($result->atd?->format('Y-m-d'))->toBe('2026-06-15');

    // Échange journalisé
    expect(DB::table('carrier_exchange_logs')->where('carrier_scac', 'JCGO')->where('success', true)->count())->toBe(1);
});

it('suit un BL en agrégeant ses conteneurs', function (): void {
    jsonCargoContext();

    Http::fake([
        'api.jsoncargo.com/api/v1/containers/bol/MEDUJ1234567*' => Http::response(['data' => [
            'bill_of_lading' => 'MEDUJ1234567',
            'associated_container_numbers' => ['MEDU1111111', 'MEDU2222222'],
        ]]),
        'api.jsoncargo.com/api/v1/containers/MEDU*' => Http::response(['data' => [
            'container_status' => 'Loaded on vessel',
            'last_movement_timestamp' => '2026-07-10 10:00',
            'eta_final_destination' => '2026-08-01 06:00',
        ]]),
    ]);

    $result = app(CarrierRegistry::class)->resolve('MSCU')->trackBillOfLading('MEDUJ1234567');

    expect($result->events)->toHaveCount(2)
        ->and($result->eta?->format('Y-m-d'))->toBe('2026-08-01');
});

it('signale la compagnie indisponible sur erreur HTTP et journalise l\'échec', function (): void {
    jsonCargoContext();

    Http::fake(['api.jsoncargo.com/*' => Http::response(['error' => 'not found'], 404)]);

    expect(fn () => app(CarrierRegistry::class)->resolve('MSCU')->trackContainer('XXXX0000000'))
        ->toThrow(CarrierUnavailable::class);
    expect(DB::table('carrier_exchange_logs')->where('carrier_scac', 'JCGO')->where('success', false)->count())->toBe(1);
});
