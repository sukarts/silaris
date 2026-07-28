<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/** Commercial rattaché à l'agence — rôle absent de la fixture partagée. */
function seedSalesUser(array $ids): string
{
    $userId = (string) Str::uuid7();
    DB::table('users')->insert([
        'id' => $userId, 'tenant_id' => $ids['tenant'], 'email' => 'sales@test.local',
        'password_hash' => Hash::make('Str0ng!Passw0rd'), 'first_name' => 'Awa', 'last_name' => 'Commerciale',
        'password_changed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('user_roles')->insert([
        'user_id' => $userId,
        'role_id' => DB::table('roles')->whereNull('tenant_id')->where('key', 'sales')->value('id'),
    ]);
    DB::table('user_branches')->insert(['user_id' => $userId, 'branch_id' => $ids['branch']]);

    return $userId;
}

/** Devis minimal : une ligne de fret, saisie à la main. */
function quotePayload(array $ids, string $salesId, array $overrides = []): array
{
    return [
        'company_id' => $ids['company'], 'party_id' => $ids['client'], 'owner_id' => $salesId,
        'mode' => 'sea_fcl', 'direction' => 'import',
        'origin_locode' => 'CNSHA', 'destination_locode' => 'CIABJ',
        'incoterm_code' => 'CIF', 'currency_code' => 'XOF',
        'valid_until' => now()->addDays(30)->toDateString(),
        'lines' => [[
            'service_code' => 'FREIGHT', 'description' => 'Fret maritime', 'quantity' => 2,
            'unit' => 'container', 'unit_price' => 1450000, 'currency_code' => 'XOF', 'buy_price' => 1200000,
        ]],
        ...$overrides,
    ];
}

it('donne au commercial son périmètre sans droit d\'administration', function (): void {
    $ids = seedCore();
    $salesId = seedSalesUser($ids);
    $token = tokenFor($salesId);

    $this->withToken($token)->getJson('/api/v1/admin/companies')->assertForbidden();
    $branches = $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk()->json('branches');

    expect($branches[0]['company_id'])->toBe($ids['company'])
        ->and($branches[0]['company_name'])->toBe('Test SA');
});

it('laisse le commercial émettre une cotation', function (): void {
    $ids = seedCore();
    $salesId = seedSalesUser($ids);

    $quote = $this->withToken(tokenFor($salesId))
        ->postJson('/api/v1/quotes', quotePayload($ids, $salesId))
        ->assertCreated()->json();

    $quote = $quote['data'] ?? $quote;
    expect($quote['number'])->toStartWith('Q-')
        ->and((float) $quote['total_amount'])->toBe(2900000.0)
        ->and((float) $quote['total_buy_amount'])->toBe(2400000.0);
});

it('accepte une cotation dont les lignes ne viennent d\'aucune grille', function (): void {
    $ids = seedCore();
    $salesId = seedSalesUser($ids);

    // Un tenant neuf n'a aucun tarif : la cotation doit rester possible, sinon
    // la fonctionnalité serait inutilisable le premier jour.
    expect($this->withToken(tokenFor($salesId))
        ->postJson('/api/v1/quotes/calculate', [
            'mode' => 'sea_fcl', 'origin_locode' => 'CNSHA', 'destination_locode' => 'CIABJ',
            'containers' => ['40HC' => 2], 'gross_weight_kg' => 1000, 'volume_m3' => 10, 'declared_value' => 0,
        ])->assertOk()->json('lines'))->toBe([]);

    $this->withToken(tokenFor($salesId))
        ->postJson('/api/v1/quotes', quotePayload($ids, $salesId))->assertCreated();
});

it('refuse une cotation sans ligne', function (): void {
    $ids = seedCore();
    $salesId = seedSalesUser($ids);

    $this->withToken(tokenFor($salesId))
        ->postJson('/api/v1/quotes', quotePayload($ids, $salesId, ['lines' => []]))
        ->assertStatus(422);
});
