<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

require_once __DIR__.'/Fixtures.php';

uses(TestCase::class)->in('Feature');

/** Dossier minimal rattaché à un client — helper partagé entre suites. */
function seedShipmentFor(array $ids, string $clientId, string $reference): string
{
    $shipmentId = (string) Str::uuid7();
    DB::table('shipments')->insert([
        'id' => $shipmentId, 'tenant_id' => $ids['tenant'], 'reference' => $reference,
        'client_id' => $clientId, 'branch_id' => $ids['branch'], 'company_id' => $ids['company'],
        'agent_id' => $ids['user_transit_agent'], 'direction' => 'import', 'mode' => 'sea_fcl',
        'status' => 'transit', 'workflow_definition_id' => $ids['workflow'], 'incoterm_code' => 'CIF',
        'origin_locode' => 'CNSHA', 'destination_locode' => 'CIABJ',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return $shipmentId;
}

/** Facture validée d'une ligne — helper partagé entre suites. */
function seedInvoiceWithLine(array $ids): string
{
    $invoiceId = (string) Str::uuid7();
    DB::table('invoices')->insert([
        'id' => $invoiceId, 'tenant_id' => $ids['tenant'], 'company_id' => $ids['company'],
        'type' => 'invoice', 'number' => 'F-2026-0001', 'party_id' => $ids['client'],
        'status' => 'validated', 'currency_code' => 'XOF',
        'total_excl_tax' => 100000, 'total_tax' => 18000, 'total_incl_tax' => 118000,
        'issue_date' => now()->toDateString(), 'due_date' => now()->addDays(30)->toDateString(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('invoice_lines')->insert([
        'id' => (string) Str::uuid7(), 'invoice_id' => $invoiceId, 'position' => 1,
        'service_code' => 'FRT', 'description' => 'Fret maritime test', 'quantity' => 1,
        'unit' => 'flat', 'unit_price' => 100000, 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $invoiceId;
}

/** Conteneur du tenant — helper partagé entre suites. */
function seedContainer(array $ids, string $number = 'MSCU1234566'): string
{
    $id = (string) Str::uuid7();
    DB::table('containers')->insert([
        'id' => $id, 'tenant_id' => $ids['tenant'], 'number' => $number,
        'size_type' => '40HC', 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

/**
 * Cotation acceptée par le client — préalable désormais obligatoire à
 * l'ouverture d'un dossier. Helper partagé entre suites.
 *
 * @param  array<string, string>  $overrides
 */
function seedAcceptedQuote(array $ids, ?string $clientId = null, array $overrides = []): string
{
    $quoteId = (string) Str::uuid7();
    DB::table('quotes')->insert([
        'id' => $quoteId,
        'tenant_id' => $ids['tenant'],
        'company_id' => $ids['company'],
        'number' => 'Q-'.date('Y').'-'.substr($quoteId, 0, 4),
        'party_id' => $clientId ?? $ids['client'],
        'owner_id' => $ids['user_admin'],
        'status' => 'accepted',
        'mode' => 'sea_fcl',
        'direction' => 'import',
        'origin_locode' => 'CNSHA',
        'destination_locode' => 'CIABJ',
        'incoterm_code' => 'CIF',
        'cargo_summary' => '{}',
        'currency_code' => 'XOF',
        'total_amount' => 1_250_000,
        'valid_until' => now()->addDays(30),
        'accepted_at' => now(),
        ...$overrides,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $quoteId;
}

/** Utilisateur porteur d'un rôle système donné — helper partagé entre suites. */
function seedUserWithRole(array $ids, string $roleKey, string $email): string
{
    $userId = (string) Str::uuid7();
    DB::table('users')->insert([
        'id' => $userId, 'tenant_id' => $ids['tenant'], 'email' => $email,
        'password_hash' => Hash::make('Str0ng!Passw0rd'), 'first_name' => ucfirst($roleKey),
        'last_name' => 'Test', 'password_changed_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('user_roles')->insert([
        'user_id' => $userId,
        'role_id' => DB::table('roles')->whereNull('tenant_id')->where('key', $roleKey)->value('id'),
    ]);
    DB::table('user_branches')->insert(['user_id' => $userId, 'branch_id' => $ids['branch']]);

    return $userId;
}

/** Dossier ouvert sur cotation, prêt à franchir sa première étape. */
function shipmentReadyToAdvance(array $ids): string
{
    // Seul un chef de service ouvre un dossier ; l'agent le tient ensuite.
    $shipmentId = test()->withToken(tokenFor($ids['user_service_manager']))->postJson('/api/v1/shipments', [
        'client_id' => $ids['client'], 'branch_id' => $ids['branch'], 'company_id' => $ids['company'],
        'agent_id' => $ids['user_transit_agent'], 'quote_id' => seedAcceptedQuote($ids),
    ])->json('data.id');
    freshAuth();

    // Le document exigé à l'étape suivante, pour n'éprouver que la validation.
    DB::table('documents')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => $ids['tenant'], 'shipment_id' => $shipmentId,
        'type' => 'commercial_invoice', 'status' => 'received', 'title' => 'Facture commerciale',
        'visibility' => 'internal', 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $shipmentId;
}

/** Rattache un utilisateur à un service, et renvoie l'identifiant du service. */
function assignService(array $ids, string $userId, string $code): string
{
    $serviceId = DB::table('services')->where('tenant_id', $ids['tenant'])->where('code', $code)->value('id');
    if ($serviceId === null) {
        $serviceId = (string) Str::uuid7();
        DB::table('services')->insert([
            'id' => $serviceId, 'tenant_id' => $ids['tenant'], 'code' => $code,
            'name' => $code, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    DB::table('users')->where('id', $userId)->update(['service_id' => $serviceId]);

    return (string) $serviceId;
}
