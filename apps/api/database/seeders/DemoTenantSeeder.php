<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Tenant de démonstration — TransAfrica Logistics (Abidjan).
 * Exécution : php artisan db:seed --class=DemoTenantSeeder
 * JAMAIS en production. Prérequis : DatabaseSeeder (référentiels).
 *
 * Comptes créés (mot de passe : "password") :
 *   admin@demo.silaris.app, directeur@…, exploitation@…, agent@…, commercial@…, comptable@…, chauffeur@…
 *   Portail client : contact@sicoa-demo.ci
 */
class DemoTenantSeeder extends Seeder
{
    private string $tenantId;

    private array $ids = [];

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command->error('DemoTenantSeeder interdit en production.');

            return;
        }

        DB::transaction(function (): void {
            $this->seedTenantAndOrg();
            $this->seedUsers();
            $this->seedWorkflow();
            $this->seedParties();
            $this->seedTariffAndQuote();
            $this->seedShipments();
            $this->seedBilling();
        });

        $this->command->info('Tenant démo "demo" créé — admin@demo.silaris.app / password');
    }

    private function uuid(): string
    {
        return (string) Str::uuid7();
    }

    /** Calcule le chiffre de contrôle ISO 6346 pour un préfixe de 10 caractères (4 lettres + 6 chiffres). */
    private function containerNumber(string $prefix10): string
    {
        $letterValues = [];
        $value = 10;
        foreach (range('A', 'Z') as $letter) {
            while (in_array($value % 11, [0], true) && $value % 11 === 0) {
                $value++;
            }
            $letterValues[$letter] = $value;
            $value++;
            if ($value % 11 === 0) {
                $value++;
            }
        }
        // Table officielle : A=10 puis +1 en sautant les multiples de 11.
        $letterValues = ['A' => 10, 'B' => 12, 'C' => 13, 'D' => 14, 'E' => 15, 'F' => 16, 'G' => 17,
            'H' => 18, 'I' => 19, 'J' => 20, 'K' => 21, 'L' => 23, 'M' => 24, 'N' => 25, 'O' => 26,
            'P' => 27, 'Q' => 28, 'R' => 29, 'S' => 30, 'T' => 31, 'U' => 32, 'V' => 34, 'W' => 35,
            'X' => 36, 'Y' => 37, 'Z' => 38];

        $sum = 0;
        foreach (str_split($prefix10) as $i => $char) {
            $val = ctype_alpha($char) ? $letterValues[$char] : (int) $char;
            $sum += $val * (2 ** $i);
        }

        return $prefix10.(($sum % 11) % 10);
    }

    private function seedTenantAndOrg(): void
    {
        $now = now();
        $this->tenantId = $this->uuid();

        DB::table('tenants')->insert([
            'id' => $this->tenantId, 'name' => 'TransAfrica Logistics', 'slug' => 'demo',
            'plan' => 'standard', 'locale_default' => 'fr',
            'settings' => json_encode(['tracking_refresh_minutes' => 120, 'delay_threshold_hours' => 24]),
            'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);

        $this->ids['company'] = $this->uuid();
        DB::table('companies')->insert([
            'id' => $this->ids['company'], 'tenant_id' => $this->tenantId,
            'legal_name' => 'TransAfrica Logistics SA', 'code' => 'TAL',
            'tax_id' => 'CI-ABJ-2020-B-12345', 'currency_code' => 'XOF',
            'address' => json_encode(['line1' => 'Zone portuaire, Vridi', 'city' => 'Abidjan', 'country' => 'CI']),
            'invoice_settings' => json_encode(['number_format' => 'F-{YEAR}-{SEQ:4}', 'footer' => 'TransAfrica Logistics SA — RCCM CI-ABJ-2020-B-12345']),
            'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);

        foreach ([['ABJ', 'Agence Abidjan', 'Africa/Abidjan'], ['SPY', 'Agence San-Pédro', 'Africa/Abidjan']] as [$code, $name, $tz]) {
            $id = $this->uuid();
            $this->ids["branch_{$code}"] = $id;
            DB::table('branches')->insert([
                'id' => $id, 'tenant_id' => $this->tenantId, 'company_id' => $this->ids['company'],
                'name' => $name, 'code' => $code, 'timezone' => $tz,
                'address' => json_encode(['city' => $code === 'ABJ' ? 'Abidjan' : 'San-Pédro', 'country' => 'CI']),
                'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    private function seedUsers(): void
    {
        $now = now();
        $roles = DB::table('roles')->whereNull('tenant_id')->pluck('id', 'key');

        $users = [
            ['admin', 'Aminata', 'Kouassi', 'admin'],
            ['directeur', 'Bertin', "N'Guessan", 'director'],
            ['exploitation', 'Brice', 'N\'Dri', 'ops_manager'],
            ['agent', 'Awa', 'Koné', 'transit_agent'],
            ['commercial', 'Moussa', 'Diallo', 'sales'],
            ['comptable', 'Chantal', 'Yao', 'accountant'],
            ['chauffeur', 'Seydou', 'Touré', 'driver'],
        ];

        foreach ($users as [$prefix, $first, $last, $roleKey]) {
            $id = $this->uuid();
            $this->ids["user_{$prefix}"] = $id;
            DB::table('users')->insert([
                'id' => $id, 'tenant_id' => $this->tenantId,
                'email' => "{$prefix}@demo.silaris.app",
                'password_hash' => Hash::make('password'),
                'first_name' => $first, 'last_name' => $last,
                'locale' => 'fr', 'is_active' => true,
                'password_changed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('user_roles')->insert(['user_id' => $id, 'role_id' => $roles[$roleKey]]);
            DB::table('user_branches')->insert(['user_id' => $id, 'branch_id' => $this->ids['branch_ABJ']]);
        }
    }

    private function seedWorkflow(): void
    {
        $now = now();
        $this->ids['workflow'] = $this->uuid();

        DB::table('workflow_definitions')->insert([
            'id' => $this->ids['workflow'], 'tenant_id' => $this->tenantId,
            'name' => 'Workflow standard', 'transport_mode' => 'any', 'direction' => 'any',
            'is_default' => true, 'is_active' => true, 'version' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        $steps = [
            ['creation', 'Création', ['booking']],
            ['booking', 'Booking', ['departure'], ['required_documents' => ['commercial_invoice', 'packing_list']]],
            ['departure', 'Départ', ['transit']],
            ['transit', 'Transit', ['arrival']],
            ['arrival', 'Arrivée', ['customs']],
            ['customs', 'Dédouanement', ['delivery'], ['required_documents' => ['customs']]],
            ['delivery', 'Livraison', ['closure']],
            ['closure', 'Clôture', [], ['requires' => ['delivery_confirmed', 'invoice_issued']]],
        ];

        foreach ($steps as $i => $step) {
            DB::table('workflow_steps')->insert([
                'id' => $this->uuid(), 'workflow_definition_id' => $this->ids['workflow'],
                'key' => $step[0], 'label' => $step[1], 'position' => $i + 1,
                'transitions' => json_encode($step[2]),
                'conditions' => json_encode($step[3] ?? []),
                'actions' => json_encode([]),
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    private function seedParties(): void
    {
        $now = now();
        $parties = [
            // key, type, supplier_kind, code, name, currency, credit
            // Les codes suivent la nomenclature que l'application génère : un
            // jeu de démonstration hors norme apprend la mauvaise habitude.
            ['sicoa', 'client', null, 'CLI-0001', 'SICOA SARL', 'XOF', 50_000_000],
            ['bernabe', 'client', null, 'CLI-0002', 'Groupe Bernabé CI', 'XOF', 80_000_000],
            ['ivoire_negoce', 'client', null, 'CLI-0003', 'Ivoire Négoce', 'XOF', 30_000_000],
            ['palmci', 'client', null, 'CLI-0004', 'Palmci SA', 'EUR', null],
            ['prospect_cfao', 'prospect', null, 'PRO-0001', 'CFAO Motors CI', 'XOF', null],
            ['msc_ci', 'supplier', 'ocean_carrier', 'FOU-0001', 'MSC Côte d\'Ivoire', 'USD', null],
            ['ek_cargo', 'supplier', 'airline', 'FOU-0002', 'Emirates SkyCargo (GSA CI)', 'USD', null],
            ['douane_ci', 'supplier', 'customs_agent', 'FOU-0003', 'Transit Douane Plus', 'XOF', null],
            ['trucker_ci', 'supplier', 'trucker', 'FOU-0004', 'Ivoire Trans Route', 'XOF', null],
        ];

        foreach ($parties as [$key, $type, $kind, $code, $name, $cur, $credit]) {
            $id = $this->uuid();
            $this->ids["party_{$key}"] = $id;
            DB::table('parties')->insert([
                'id' => $id, 'tenant_id' => $this->tenantId, 'type' => $type, 'supplier_kind' => $kind,
                'code' => $code, 'name' => $name, 'currency_code' => $cur,
                'payment_terms_days' => $type === 'client' ? 30 : null,
                'credit_limit' => $credit,
                'notification_prefs' => json_encode($type === 'client' ? ['departure' => ['email'], 'arrival' => ['email', 'whatsapp'], 'delay' => ['email', 'whatsapp'], 'invoice_available' => ['email']] : []),
                'owner_id' => $this->ids['user_commercial'],
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        // Les codes ci-dessus sont posés en dur ; la séquence, elle, part de
        // zéro. Sans ce rattrapage, la première fiche créée dans l'application
        // réclamerait CLI-0001 — déjà pris — et se heurterait à l'unicité.
        foreach (array_count_values(array_column($parties, 1)) as $type => $count) {
            DB::table('sequences')->updateOrInsert(
                ['tenant_id' => $this->tenantId, 'scope' => "party:{$type}"],
                ['last_value' => $count],
            );
        }

        DB::table('party_contacts')->insert([
            ['id' => $this->uuid(), 'tenant_id' => $this->tenantId, 'party_id' => $this->ids['party_sicoa'],
                'name' => 'Serge Coulibaly', 'email' => 'contact@sicoa-demo.ci', 'phone' => '+2250707000001',
                'role' => 'Directeur logistique', 'is_primary' => true, 'created_at' => $now, 'updated_at' => $now],
            ['id' => $this->uuid(), 'tenant_id' => $this->tenantId, 'party_id' => $this->ids['party_bernabe'],
                'name' => 'Mariam Traoré', 'email' => 'm.traore@bernabe-demo.ci', 'phone' => '+2250707000002',
                'role' => 'Acheteuse', 'is_primary' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);

        DB::table('portal_accounts')->insert([
            'id' => $this->uuid(), 'tenant_id' => $this->tenantId, 'party_id' => $this->ids['party_sicoa'],
            'email' => 'contact@sicoa-demo.ci', 'password_hash' => Hash::make('password'),
            'name' => 'Serge Coulibaly',
            'notification_prefs' => json_encode(['arrival' => ['email', 'whatsapp'], 'delay' => ['email', 'whatsapp']]),
            'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);

        DB::table('opportunities')->insert([
            'id' => $this->uuid(), 'tenant_id' => $this->tenantId, 'party_id' => $this->ids['party_prospect_cfao'],
            'title' => 'Import véhicules — 40 conteneurs/an', 'stage' => 'proposal',
            'estimated_value' => 120_000_000, 'currency_code' => 'XOF', 'probability' => 60,
            'owner_id' => $this->ids['user_commercial'], 'expected_close_date' => now()->addMonth(),
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    private function seedTariffAndQuote(): void
    {
        $now = now();
        $tariffId = $this->uuid();
        DB::table('tariffs')->insert([
            'id' => $tariffId, 'tenant_id' => $this->tenantId,
            'name' => 'Vente FCL Asie → Abidjan 2026', 'mode' => 'sea_fcl', 'side' => 'sell',
            'origin_locode' => 'CNSHA', 'destination_locode' => 'CIABJ',
            'valid_from' => '2026-01-01', 'valid_to' => '2026-12-31', 'is_active' => true,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('tariff_lines')->insert([
            ['id' => $this->uuid(), 'tariff_id' => $tariffId, 'service_code' => 'freight', 'description' => 'Fret maritime 40HC', 'unit' => 'container', 'container_size_type' => '40HC', 'unit_price' => 2450, 'currency_code' => 'USD', 'minimum' => null, 'weight_from_kg' => null, 'weight_to_kg' => null, 'created_at' => $now, 'updated_at' => $now],
            ['id' => $this->uuid(), 'tariff_id' => $tariffId, 'service_code' => 'handling', 'description' => 'Manutention portuaire (THC)', 'unit' => 'container', 'container_size_type' => '40HC', 'unit_price' => 285_000, 'currency_code' => 'XOF', 'minimum' => null, 'weight_from_kg' => null, 'weight_to_kg' => null, 'created_at' => $now, 'updated_at' => $now],
            ['id' => $this->uuid(), 'tariff_id' => $tariffId, 'service_code' => 'customs', 'description' => 'Prestations dédouanement', 'unit' => 'flat', 'container_size_type' => null, 'unit_price' => 350_000, 'currency_code' => 'XOF', 'minimum' => null, 'weight_from_kg' => null, 'weight_to_kg' => null, 'created_at' => $now, 'updated_at' => $now],
            ['id' => $this->uuid(), 'tariff_id' => $tariffId, 'service_code' => 'transport', 'description' => 'Livraison Abidjan intra-muros', 'unit' => 'container', 'container_size_type' => null, 'unit_price' => 150_000, 'currency_code' => 'XOF', 'minimum' => null, 'weight_from_kg' => null, 'weight_to_kg' => null, 'created_at' => $now, 'updated_at' => $now],
        ]);

        $this->ids['quote'] = $this->uuid();
        DB::table('quotes')->insert([
            'id' => $this->ids['quote'], 'tenant_id' => $this->tenantId, 'company_id' => $this->ids['company'],
            'number' => 'Q-2026-0412', 'revision' => 1,
            'party_id' => $this->ids['party_sicoa'], 'owner_id' => $this->ids['user_commercial'],
            'status' => 'accepted', 'mode' => 'sea_fcl', 'direction' => 'import',
            'origin_locode' => 'CNSHA', 'destination_locode' => 'CIABJ', 'incoterm_code' => 'CIF',
            'cargo_summary' => json_encode(['containers' => '2 x 40HC', 'weight_kg' => 44000, 'volume_m3' => 54]),
            'currency_code' => 'XOF', 'total_amount' => 4_570_000, 'total_buy_amount' => 3_650_000,
            'valid_until' => '2026-07-20', 'sent_at' => '2026-06-18 10:00:00+00', 'accepted_at' => '2026-06-20 09:00:00+00',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('quote_lines')->insert([
            ['id' => $this->uuid(), 'quote_id' => $this->ids['quote'], 'position' => 1, 'service_code' => 'freight', 'description' => 'Fret maritime 2 x 40HC Shanghai → Abidjan', 'quantity' => 2, 'unit' => 'container', 'unit_price' => 1_470_000, 'currency_code' => 'XOF', 'buy_price' => 1_180_000, 'created_at' => $now, 'updated_at' => $now],
            ['id' => $this->uuid(), 'quote_id' => $this->ids['quote'], 'position' => 2, 'service_code' => 'handling', 'description' => 'THC Abidjan 2 x 40HC', 'quantity' => 2, 'unit' => 'container', 'unit_price' => 285_000, 'currency_code' => 'XOF', 'buy_price' => 230_000, 'created_at' => $now, 'updated_at' => $now],
            ['id' => $this->uuid(), 'quote_id' => $this->ids['quote'], 'position' => 3, 'service_code' => 'customs', 'description' => 'Dédouanement import', 'quantity' => 1, 'unit' => 'flat', 'unit_price' => 350_000, 'currency_code' => 'XOF', 'buy_price' => 280_000, 'created_at' => $now, 'updated_at' => $now],
            ['id' => $this->uuid(), 'quote_id' => $this->ids['quote'], 'position' => 4, 'service_code' => 'transport', 'description' => 'Livraison finale 2 conteneurs', 'quantity' => 2, 'unit' => 'container', 'unit_price' => 150_000, 'currency_code' => 'XOF', 'buy_price' => 125_000, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    private function seedShipments(): void
    {
        $now = now();
        $carrierMsc = DB::table('carriers')->where('scac', 'MSCU')->value('id');
        $airlineEk = DB::table('airlines')->where('awb_prefix', '176')->value('id');

        // ——— Dossier 1 : Import FCL Shanghai → Abidjan, en transit, issu du devis ———
        $s1 = $this->uuid();
        $this->ids['shipment_fcl'] = $s1;
        DB::table('shipments')->insert([
            'id' => $s1, 'tenant_id' => $this->tenantId, 'reference' => 'TAL-2026-00128',
            'client_id' => $this->ids['party_sicoa'], 'branch_id' => $this->ids['branch_ABJ'],
            'company_id' => $this->ids['company'], 'agent_id' => $this->ids['user_agent'],
            'supervisor_id' => $this->ids['user_exploitation'],
            'direction' => 'import', 'mode' => 'sea_fcl', 'status' => 'transit',
            'workflow_definition_id' => $this->ids['workflow'], 'incoterm_code' => 'CIF',
            'origin_locode' => 'CNSHA', 'destination_locode' => 'CIABJ', 'priority' => 'high',
            'etd' => '2026-07-05 08:00:00+00', 'atd' => '2026-07-05 11:47:00+00',
            'eta' => '2026-08-02 06:00:00+00', 'eta_initial' => '2026-07-31 06:00:00+00',
            'estimated_cost' => 3_650_000, 'estimated_revenue' => 4_570_000, 'currency_code' => 'XOF',
            'quote_id' => $this->ids['quote'],
            'created_at' => '2026-06-20 09:30:00+00', 'updated_at' => $now,
        ]);

        // Booking + navire + voyage + escales
        $vessel = $this->uuid();
        DB::table('vessels')->insert(['id' => $vessel, 'name' => 'MSC AURELIA', 'imo_number' => '9857169', 'mmsi' => '636019825', 'flag_country' => 'PA', 'created_at' => $now, 'updated_at' => $now]);
        $voyage = $this->uuid();
        DB::table('voyages')->insert(['id' => $voyage, 'vessel_id' => $vessel, 'voyage_number' => '226W', 'carrier_id' => $carrierMsc, 'created_at' => $now, 'updated_at' => $now]);
        foreach ([['CNSHA', 1, '2026-07-04', '2026-07-05'], ['SGSIN', 2, '2026-07-13', '2026-07-14'], ['LKCMB', 3, '2026-07-20', '2026-07-21'], ['CIABJ', 4, '2026-08-02', '2026-08-03']] as [$port, $pos, $eta, $etd]) {
            DB::table('port_calls')->insert(['id' => $this->uuid(), 'voyage_id' => $voyage, 'port_locode' => $port, 'position' => $pos, 'eta' => "{$eta} 06:00:00+00", 'etd' => "{$etd} 18:00:00+00", 'created_at' => $now, 'updated_at' => $now]);
        }
        $booking = $this->uuid();
        DB::table('bookings')->insert([
            'id' => $booking, 'tenant_id' => $this->tenantId, 'shipment_id' => $s1, 'carrier_id' => $carrierMsc,
            'voyage_id' => $voyage, 'booking_number' => 'EBKG08841272', 'status' => 'confirmed',
            'vgm_cutoff' => '2026-07-01 12:00:00+00', 'doc_cutoff' => '2026-07-02 12:00:00+00',
            'port_cutoff' => '2026-07-03 18:00:00+00', 'confirmed_at' => '2026-06-24 10:15:00+00',
            'created_at' => $now, 'updated_at' => $now,
        ]);

        // Conteneurs (numéros ISO 6346 valides, générés avec check digit)
        foreach ([['MSKU884201', 'SL-774021', 28_540], ['MSCU240118', 'SL-774022', 27_980]] as [$prefix, $seal, $vgm]) {
            $containerId = $this->uuid();
            DB::table('containers')->insert([
                'id' => $containerId, 'tenant_id' => $this->tenantId,
                'number' => $this->containerNumber($prefix), 'size_type' => '40HC',
                'tare_kg' => 3900, 'max_payload_kg' => 28_600, 'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('container_assignments')->insert([
                'id' => $this->uuid(), 'tenant_id' => $this->tenantId, 'container_id' => $containerId,
                'shipment_id' => $s1, 'booking_id' => $booking, 'seal_number' => $seal,
                'vgm_kg' => $vgm, 'vgm_verified_at' => '2026-06-30 15:00:00+00',
                'free_time_days' => 14, 'gate_in_at' => '2026-07-01 08:14:00+00', 'loaded_at' => '2026-07-04 19:20:00+00',
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        // MBL + HBL
        $mbl = $this->uuid();
        DB::table('bills_of_lading')->insert([
            'id' => $mbl, 'tenant_id' => $this->tenantId, 'shipment_id' => $s1, 'type' => 'master',
            'number' => 'MEDUJ2260417', 'release_type' => 'seaway', 'status' => 'issued',
            'shipper' => json_encode(['name' => 'Foshan Ceramics Export Co.', 'city' => 'Foshan', 'country' => 'CN']),
            'consignee' => json_encode(['name' => 'TransAfrica Logistics SA', 'city' => 'Abidjan', 'country' => 'CI']),
            'notify_party' => json_encode(['name' => 'TransAfrica Logistics SA']),
            'goods_description' => 'Carreaux céramique — 2 x 40HC', 'gross_weight_kg' => 44_000, 'volume_m3' => 54,
            'packages_count' => 1760, 'issued_at' => '2026-07-05 06:00:00+00',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('bills_of_lading')->insert([
            'id' => $this->uuid(), 'tenant_id' => $this->tenantId, 'shipment_id' => $s1, 'parent_id' => $mbl,
            'type' => 'house', 'number' => 'TAL26ABJ0128', 'release_type' => 'telex', 'status' => 'issued',
            'shipper' => json_encode(['name' => 'Foshan Ceramics Export Co.', 'city' => 'Foshan', 'country' => 'CN']),
            'consignee' => json_encode(['name' => 'SICOA SARL', 'city' => 'Abidjan', 'country' => 'CI']),
            'notify_party' => json_encode(['name' => 'SICOA SARL', 'phone' => '+2250707000001']),
            'goods_description' => 'Carreaux céramique', 'gross_weight_kg' => 44_000, 'volume_m3' => 54,
            'packages_count' => 1760, 'issued_at' => '2026-07-05 08:02:00+00', 'issued_by' => $this->ids['user_agent'],
            'created_at' => $now, 'updated_at' => $now,
        ]);

        // Tracking subscription + événements DCSA
        $sub = $this->uuid();
        DB::table('tracking_subscriptions')->insert([
            'id' => $sub, 'tenant_id' => $this->tenantId, 'subject_type' => 'container',
            'subject_number' => $this->containerNumber('MSKU884201'), 'shipment_id' => $s1,
            'carrier_scac' => 'MSCU', 'status' => 'active', 'last_polled_at' => $now->copy()->subMinutes(40),
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $events = [
            ['GTIN', 'Export received at CY', 'CNSHA', '2026-07-01 08:14:00+00'],
            ['LOAD', 'Loaded on vessel', 'CNSHA', '2026-07-04 19:20:00+00'],
            ['DEPA', 'Vessel departure', 'CNSHA', '2026-07-05 11:47:00+00'],
            ['DEPA', 'Vessel departure', 'SGSIN', '2026-07-14 22:05:00+00'],
            ['TRSH', 'Transshipment loaded', 'LKCMB', '2026-07-21 14:32:00+00'],
        ];
        foreach ($events as [$code, $raw, $loc, $at]) {
            DB::table('tracking_events')->insert([
                'id' => $this->uuid(), 'tenant_id' => $this->tenantId, 'subscription_id' => $sub,
                'shipment_id' => $s1, 'dcsa_event_code' => $code, 'raw_status' => $raw,
                'location_locode' => $loc, 'vessel_imo' => '9857169', 'occurred_at' => $at,
                'event_hash' => hash('sha256', $sub.$code.$loc.$at),
                'raw_payload' => json_encode(['demo' => true]),
            ]);
        }

        // Timeline dossier
        $timeline = [
            ['status_change', 'Dossier créé depuis devis Q-2026-0412', 'internal', '2026-06-20 09:30:00+00', $this->ids['user_agent']],
            ['status_change', 'Booking confirmé — MSC (EBKG08841272)', 'internal', '2026-06-24 10:15:00+00', $this->ids['user_agent']],
            ['document', 'HBL TAL26ABJ0128 émis (telex release)', 'internal', '2026-07-05 08:02:00+00', $this->ids['user_agent']],
            ['tracking', 'Départ Shanghai (ATD) — étape Transit', 'carrier_api', '2026-07-05 11:47:00+00', null],
            ['tracking', 'ETA recalculée : 31/07 → 02/08 (+2 j) — client notifié', 'carrier_api', '2026-07-18 06:10:00+00', null],
            ['tracking', 'Transbordement effectué — Colombo', 'carrier_api', '2026-07-21 14:32:00+00', null],
        ];
        foreach ($timeline as [$type, $title, $source, $at, $actor]) {
            DB::table('shipment_events')->insert([
                'id' => $this->uuid(), 'tenant_id' => $this->tenantId, 'shipment_id' => $s1,
                'type' => $type, 'title' => $title, 'payload' => json_encode([]),
                'source' => $source, 'actor_id' => $actor, 'occurred_at' => $at,
            ]);
        }

        // Documents (checklist complète)
        foreach ([
            ['commercial_invoice', 'Facture commerciale Foshan Ceramics', 'validated', 'client'],
            ['packing_list', 'Packing list 1760 colis', 'validated', 'client'],
            ['hbl', 'HBL TAL26ABJ0128', 'validated', 'client'],
            ['certificate_origin', "Certificat d'origine CCPIT", 'validated', 'client'],
            ['insurance', 'Police assurance cargo AXA', 'validated', 'client'],
            ['customs', 'Déclaration douane import', 'received', 'internal'],
        ] as [$type, $title, $status, $visibility]) {
            DB::table('documents')->insert([
                'id' => $this->uuid(), 'tenant_id' => $this->tenantId, 'shipment_id' => $s1,
                'type' => $type, 'title' => $title, 'visibility' => $visibility, 'status' => $status,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        // Tâche ouverte
        DB::table('shipment_tasks')->insert([
            'id' => $this->uuid(), 'tenant_id' => $this->tenantId, 'shipment_id' => $s1,
            'title' => 'Préparer le dossier de dédouanement avant arrivée',
            'assignee_id' => $this->ids['user_agent'], 'due_at' => '2026-07-30 17:00:00+00',
            'status' => 'open', 'created_at' => $now, 'updated_at' => $now,
        ]);

        // ——— Dossier 2 : Export FCL Abidjan → Le Havre, en booking ———
        $s2 = $this->uuid();
        DB::table('shipments')->insert([
            'id' => $s2, 'tenant_id' => $this->tenantId, 'reference' => 'TAL-2026-00124',
            'client_id' => $this->ids['party_sicoa'], 'branch_id' => $this->ids['branch_ABJ'],
            'company_id' => $this->ids['company'], 'agent_id' => $this->ids['user_commercial'],
            'direction' => 'export', 'mode' => 'sea_fcl', 'status' => 'booking',
            'workflow_definition_id' => $this->ids['workflow'], 'incoterm_code' => 'FOB',
            'origin_locode' => 'CIABJ', 'destination_locode' => 'FRLEH', 'priority' => 'normal',
            'etd' => '2026-08-08 18:00:00+00', 'eta' => '2026-08-22 06:00:00+00', 'eta_initial' => '2026-08-22 06:00:00+00',
            'currency_code' => 'XOF',
            'created_at' => '2026-07-15 11:00:00+00', 'updated_at' => $now,
        ]);
        DB::table('cargo_items')->insert([
            'id' => $this->uuid(), 'tenant_id' => $this->tenantId, 'shipment_id' => $s2,
            'goods_type_id' => DB::table('goods_types')->where('code', 'COCOA')->value('id'),
            'description' => 'Cacao brut en sacs — 1 x 20GP', 'packages_count' => 320, 'package_type' => 'sac',
            'gross_weight_kg' => 20_800, 'volume_m3' => 26, 'hs_code' => '1801.00',
            'created_at' => $now, 'updated_at' => $now,
        ]);

        // ——— Dossier 3 : Import aérien CDG → ABJ, en dédouanement ———
        $s3 = $this->uuid();
        DB::table('shipments')->insert([
            'id' => $s3, 'tenant_id' => $this->tenantId, 'reference' => 'TAL-2026-00109',
            'client_id' => $this->ids['party_bernabe'], 'branch_id' => $this->ids['branch_ABJ'],
            'company_id' => $this->ids['company'], 'agent_id' => $this->ids['user_agent'],
            'direction' => 'import', 'mode' => 'air', 'status' => 'customs',
            'workflow_definition_id' => $this->ids['workflow'], 'incoterm_code' => 'CPT',
            'origin_locode' => 'FRCDG', 'destination_locode' => 'CIABJ', 'priority' => 'critical',
            'etd' => '2026-07-22 22:00:00+00', 'atd' => '2026-07-22 22:35:00+00',
            'eta' => '2026-07-23 05:30:00+00', 'ata' => '2026-07-23 05:12:00+00', 'eta_initial' => '2026-07-23 05:30:00+00',
            'currency_code' => 'XOF',
            'created_at' => '2026-07-18 14:00:00+00', 'updated_at' => $now,
        ]);
        // MAWB valide mod 7 : série 1234567 % 7 = 5
        $mawb = $this->uuid();
        DB::table('air_waybills')->insert([
            'id' => $mawb, 'tenant_id' => $this->tenantId, 'shipment_id' => $s3, 'type' => 'master',
            'number' => '17612345675', 'airline_id' => $airlineEk,
            'gross_weight_kg' => 340, 'volume_m3' => 1.8, 'packages_count' => 12, 'status' => 'issued',
            'shipper' => json_encode(['name' => 'Pièces Auto Europe SAS', 'city' => 'Paris', 'country' => 'FR']),
            'consignee' => json_encode(['name' => 'TransAfrica Logistics SA', 'city' => 'Abidjan', 'country' => 'CI']),
            'goods_description' => 'Pièces détachées automobiles', 'issued_at' => '2026-07-21 16:00:00+00',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('air_waybills')->insert([
            'id' => $this->uuid(), 'tenant_id' => $this->tenantId, 'shipment_id' => $s3, 'parent_id' => $mawb,
            'type' => 'house', 'number' => 'TAL26A00109', 'airline_id' => $airlineEk,
            'gross_weight_kg' => 340, 'volume_m3' => 1.8, 'packages_count' => 12, 'status' => 'issued',
            'shipper' => json_encode(['name' => 'Pièces Auto Europe SAS']),
            'consignee' => json_encode(['name' => 'Groupe Bernabé CI', 'city' => 'Abidjan']),
            'goods_description' => 'Pièces détachées automobiles', 'issued_at' => '2026-07-21 16:30:00+00',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('flight_legs')->insert([
            ['id' => $this->uuid(), 'awb_id' => $mawb, 'position' => 1, 'flight_number' => 'EK076', 'origin_iata' => 'CDG', 'destination_iata' => 'DXB', 'departure_at' => '2026-07-22 22:35:00+00', 'arrival_at' => '2026-07-23 07:10:00+04', 'created_at' => $now, 'updated_at' => $now],
            ['id' => $this->uuid(), 'awb_id' => $mawb, 'position' => 2, 'flight_number' => 'EK787', 'origin_iata' => 'DXB', 'destination_iata' => 'ABJ', 'departure_at' => '2026-07-23 09:40:00+04', 'arrival_at' => '2026-07-23 05:12:00+00', 'created_at' => $now, 'updated_at' => $now],
        ]);

        // Mission routière de livraison (dossier 3) + chauffeur
        $truck = $this->uuid();
        DB::table('trucks')->insert(['id' => $truck, 'tenant_id' => $this->tenantId, 'plate_number' => 'CI-1234-AB', 'type' => 'fourgon', 'capacity_kg' => 3500, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        $driver = $this->uuid();
        DB::table('drivers')->insert(['id' => $driver, 'tenant_id' => $this->tenantId, 'user_id' => $this->ids['user_chauffeur'], 'name' => 'Seydou Touré', 'phone' => '+2250707000009', 'license_number' => 'CI-PL-889900', 'license_categories' => 'B,C', 'license_expiry' => '2028-03-15', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('missions')->insert([
            'id' => $this->uuid(), 'tenant_id' => $this->tenantId, 'reference' => 'MIS-2026-087',
            'shipment_id' => $s3, 'truck_id' => $truck, 'driver_id' => $driver,
            'type' => 'delivery', 'status' => 'planned',
            'window_start' => '2026-07-26 08:00:00+00', 'window_end' => '2026-07-26 12:00:00+00',
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    private function seedBilling(): void
    {
        $now = now();
        $taxId = $this->uuid();
        DB::table('tax_rates')->insert([
            'id' => $taxId, 'tenant_id' => $this->tenantId, 'name' => 'TVA CI 18 %',
            'rate_percent' => 18, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);

        // Facture validée (dossier FCL) — insérée directement à l'état validé.
        $inv = $this->uuid();
        DB::table('invoices')->insert([
            'id' => $inv, 'tenant_id' => $this->tenantId, 'company_id' => $this->ids['company'],
            'type' => 'invoice', 'number' => 'F-2026-0315',
            'party_id' => $this->ids['party_sicoa'], 'shipment_id' => $this->ids['shipment_fcl'],
            'quote_id' => $this->ids['quote'],
            'status' => 'validated', 'payment_status' => 'unpaid', 'currency_code' => 'XOF',
            'total_excl_tax' => 4_570_000, 'total_tax' => 822_600, 'total_incl_tax' => 5_392_600,
            'issue_date' => '2026-07-22', 'due_date' => '2026-08-21',
            'validated_at' => '2026-07-22 09:00:00+00', 'validated_by' => $this->ids['user_comptable'],
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('invoice_lines')->insert([
            ['id' => $this->uuid(), 'invoice_id' => $inv, 'position' => 1, 'service_code' => 'freight', 'description' => 'Fret maritime 2 x 40HC Shanghai → Abidjan', 'quantity' => 2, 'unit' => 'container', 'unit_price' => 1_470_000, 'tax_rate_id' => $taxId, 'created_at' => $now, 'updated_at' => $now],
            ['id' => $this->uuid(), 'invoice_id' => $inv, 'position' => 2, 'service_code' => 'handling', 'description' => 'THC Abidjan 2 x 40HC', 'quantity' => 2, 'unit' => 'container', 'unit_price' => 285_000, 'tax_rate_id' => $taxId, 'created_at' => $now, 'updated_at' => $now],
            ['id' => $this->uuid(), 'invoice_id' => $inv, 'position' => 3, 'service_code' => 'customs', 'description' => 'Dédouanement import', 'quantity' => 1, 'unit' => 'flat', 'unit_price' => 350_000, 'tax_rate_id' => $taxId, 'created_at' => $now, 'updated_at' => $now],
            ['id' => $this->uuid(), 'invoice_id' => $inv, 'position' => 4, 'service_code' => 'transport', 'description' => 'Livraison finale 2 conteneurs', 'quantity' => 2, 'unit' => 'container', 'unit_price' => 150_000, 'tax_rate_id' => $taxId, 'created_at' => $now, 'updated_at' => $now],
        ]);

        // Séquences alignées sur les numéros utilisés
        foreach ([
            ['shipment:ABJ:2026', 128],
            ['quote:2026', 412],
            ['invoice:'.$this->ids['company'].':invoice', 315],
        ] as [$scope, $value]) {
            DB::table('sequences')->insert([
                'tenant_id' => $this->tenantId, 'scope' => $scope, 'last_value' => $value,
            ]);
        }
    }
}
