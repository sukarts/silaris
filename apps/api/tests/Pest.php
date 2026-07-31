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

/**
 * Aéroports et compagnie nécessaires aux LTA et à leur suivi.
 * Les FK flight_legs → airports et air_waybills → airlines l'imposent.
 */
function seedAirRefs(): string
{
    DB::table('countries')->insertOrIgnore([
        ['code2' => 'CI', 'code3' => 'CIV', 'name_fr' => "Côte d'Ivoire", 'name_en' => 'Ivory Coast', 'created_at' => now(), 'updated_at' => now()],
        ['code2' => 'FR', 'code3' => 'FRA', 'name_fr' => 'France', 'name_en' => 'France', 'created_at' => now(), 'updated_at' => now()],
    ]);
    DB::table('airports')->insertOrIgnore([
        ['iata' => 'ABJ', 'name' => 'Abidjan Félix-Houphouët-Boigny', 'country_code' => 'CI', 'created_at' => now(), 'updated_at' => now()],
        ['iata' => 'CDG', 'name' => 'Paris Charles-de-Gaulle', 'country_code' => 'FR', 'created_at' => now(), 'updated_at' => now()],
    ]);
    $airlineId = (string) Str::uuid7();
    DB::table('airlines')->insert(['id' => $airlineId, 'awb_prefix' => '057', 'iata' => 'AF', 'name' => 'Air France Cargo', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

    return $airlineId;
}

/** LTA master valide (numéro mod 7) avec un segment de vol AF718 CDG→ABJ. */
function seedAwb(array $ids, string $airlineId): string
{
    $shipmentId = seedShipmentFor($ids, $ids['client'], 'IMP-AIR-0001');
    $awbId = (string) Str::uuid7();
    DB::table('air_waybills')->insert([
        'id' => $awbId, 'tenant_id' => $ids['tenant'], 'shipment_id' => $shipmentId,
        'type' => 'master', 'number' => '05712345675', 'airline_id' => $airlineId,
        'gross_weight_kg' => 320.5, 'volume_m3' => 4.2, 'packages_count' => 12,
        'status' => 'draft', 'shipper' => json_encode(['name' => 'Expéditeur SARL', 'city' => 'Paris', 'country' => 'France']),
        'consignee' => json_encode(['name' => 'Destinataire CI', 'city' => 'Abidjan', 'country' => "Côte d'Ivoire"]),
        'goods_description' => 'Pièces détachées automobiles',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('flight_legs')->insert([
        'id' => (string) Str::uuid7(), 'awb_id' => $awbId, 'position' => 1,
        'flight_number' => 'AF718', 'origin_iata' => 'CDG', 'destination_iata' => 'ABJ',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return $awbId;
}
