<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Deuxième tenant, peuplé en brut (sans contexte) : un dossier en attente de
 * dérogation, une demande d'étape, un conteneur sous franchise, une balise et
 * une cotation acceptée. Tout ce qu'un tenant A ne doit jamais voir.
 */
function seedOtherTenant(): array
{
    $b = ['tenant' => (string) Str::uuid7()];
    DB::table('tenants')->insert(['id' => $b['tenant'], 'name' => 'Autre Co', 'slug' => 'b-'.Str::random(6), 'created_at' => now(), 'updated_at' => now()]);
    $b['company'] = (string) Str::uuid7();
    DB::table('companies')->insert(['id' => $b['company'], 'tenant_id' => $b['tenant'], 'legal_name' => 'Autre SA', 'code' => 'OTH', 'currency_code' => 'XOF', 'invoice_settings' => '{}', 'created_at' => now(), 'updated_at' => now()]);
    $b['branch'] = (string) Str::uuid7();
    DB::table('branches')->insert(['id' => $b['branch'], 'tenant_id' => $b['tenant'], 'company_id' => $b['company'], 'name' => 'Agence B', 'code' => 'OTB', 'created_at' => now(), 'updated_at' => now()]);
    $b['user'] = (string) Str::uuid7();
    DB::table('users')->insert(['id' => $b['user'], 'tenant_id' => $b['tenant'], 'email' => 'intrus@autre.local', 'password_hash' => Hash::make('x'), 'first_name' => 'Intrus', 'last_name' => 'B', 'password_changed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    $b['client'] = (string) Str::uuid7();
    DB::table('parties')->insert(['id' => $b['client'], 'tenant_id' => $b['tenant'], 'type' => 'client', 'code' => 'CLB', 'name' => 'Client B', 'notification_prefs' => '{}', 'tags' => '[]', 'created_at' => now(), 'updated_at' => now()]);
    $b['workflow'] = (string) Str::uuid7();
    DB::table('workflow_definitions')->insert(['id' => $b['workflow'], 'tenant_id' => $b['tenant'], 'name' => 'Std B', 'transport_mode' => 'any', 'direction' => 'any', 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]);

    $b['shipment'] = (string) Str::uuid7();
    DB::table('shipments')->insert([
        'id' => $b['shipment'], 'tenant_id' => $b['tenant'], 'reference' => 'OTB-2026-00001',
        'client_id' => $b['client'], 'branch_id' => $b['branch'], 'company_id' => $b['company'],
        'agent_id' => $b['user'], 'workflow_definition_id' => $b['workflow'],
        'direction' => 'import', 'mode' => 'sea_fcl', 'status' => 'transit',
        'incoterm_code' => 'CIF', 'origin_locode' => 'CNSHA', 'destination_locode' => 'CIABJ',
        'quote_waiver_status' => 'pending', 'quote_waiver_reason' => 'Dossier historique du tenant B',
        'quote_waiver_requested_by' => $b['user'], 'quote_waiver_requested_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $b['step_request'] = (string) Str::uuid7();
    DB::table('shipment_step_requests')->insert([
        'id' => $b['step_request'], 'tenant_id' => $b['tenant'], 'shipment_id' => $b['shipment'],
        'from_step' => 'creation', 'to_step' => 'booking', 'status' => 'pending',
        'requested_by' => $b['user'], 'requested_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    $container = (string) Str::uuid7();
    DB::table('containers')->insert(['id' => $container, 'tenant_id' => $b['tenant'], 'number' => 'MSCU1234566', 'size_type' => '40HC', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('container_assignments')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => $b['tenant'], 'container_id' => $container, 'shipment_id' => $b['shipment'],
        'demurrage_days' => 7, 'demurrage_ends_at' => now()->addDays(2), 'created_at' => now(), 'updated_at' => now(),
    ]);

    $b['device'] = (string) Str::uuid7();
    DB::table('tracking_devices')->insert([
        'id' => $b['device'], 'tenant_id' => $b['tenant'], 'identifier' => 'IMEI-B', 'label' => 'Balise B',
        'kind' => 'beacon', 'api_key_hash' => Hash::make('dev_b'), 'key_prefix' => 'dev_bbbbbbbb', 'is_active' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $b['quote'] = (string) Str::uuid7();
    DB::table('quotes')->insert([
        'id' => $b['quote'], 'tenant_id' => $b['tenant'], 'company_id' => $b['company'], 'number' => 'QB-0001',
        'party_id' => $b['client'], 'owner_id' => $b['user'], 'status' => 'accepted', 'mode' => 'sea_fcl',
        'direction' => 'import', 'origin_locode' => 'CNSHA', 'destination_locode' => 'CIABJ', 'incoterm_code' => 'CIF',
        'cargo_summary' => '{}', 'currency_code' => 'XOF', 'total_amount' => 900_000, 'total_buy_amount' => 600_000,
        'valid_until' => now()->addDays(30), 'accepted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $b;
}

it('ne liste jamais les données d\'un autre tenant', function (): void {
    $ids = seedCore();
    $b = seedOtherTenant();
    $admin = tokenFor($ids['user_admin']);

    $this->withToken($admin)->getJson('/api/v1/demurrage?within_days=60')->assertOk()->assertJsonCount(0, 'data');
    $this->withToken($admin)->getJson('/api/v1/shipments/waivers')->assertOk()->assertJsonCount(0, 'data');
    $this->withToken($admin)->getJson('/api/v1/shipments/step-requests')->assertOk()->assertJsonCount(0, 'data');
    $this->withToken($admin)->getJson('/api/v1/road/devices')->assertOk()->assertJsonCount(0, 'data');

    $agents = $this->withToken($admin)->getJson('/api/v1/shipments/assignable-agents')->assertOk()->json('data');
    expect(collect($agents)->pluck('id'))->not->toContain($b['user']);
});

it('traite l\'identifiant d\'un autre tenant comme inexistant', function (): void {
    $ids = seedCore();
    $b = seedOtherTenant();
    $admin = tokenFor($ids['user_admin']);

    $this->withToken($admin)->postJson("/api/v1/shipments/{$b['shipment']}/waiver/decide", ['decision' => 'approved'])->assertNotFound();
    $this->withToken($admin)->postJson("/api/v1/shipments/{$b['shipment']}/assign", ['agent_id' => $ids['user_transit_agent']])->assertNotFound();
    $this->withToken($admin)->postJson("/api/v1/shipments/step-requests/{$b['step_request']}/decide", ['decision' => 'rejected', 'note' => 'x'])->assertNotFound();
    $this->withToken($admin)->patchJson("/api/v1/road/devices/{$b['device']}", ['is_active' => false])->assertNotFound();
    $this->withToken($admin)->postJson("/api/v1/invoices/from-quote/{$b['quote']}")->assertNotFound();
    $this->withToken($admin)->postJson("/api/v1/shipments/{$b['shipment']}/tracking/subscribe", ['subject_type' => 'bl', 'number' => 'MSCUBL1'])->assertNotFound();

    // Rien n'a bougé chez B.
    expect(DB::table('shipments')->where('id', $b['shipment'])->value('quote_waiver_status'))->toBe('pending')
        ->and(DB::table('tracking_devices')->where('id', $b['device'])->value('is_active'))->toBeTrue();
});

it('refuse d\'ouvrir un dossier sur le client et la cotation d\'un autre tenant', function (): void {
    $ids = seedCore();
    $b = seedOtherTenant();

    $this->withToken(tokenFor($ids['user_service_manager']))->postJson('/api/v1/shipments', [
        'client_id' => $b['client'], 'branch_id' => $ids['branch'], 'company_id' => $ids['company'],
        'agent_id' => $ids['user_transit_agent'], 'quote_id' => $b['quote'],
    ])->assertUnprocessable()->assertJsonValidationErrors(['client_id', 'quote_id']);
});
