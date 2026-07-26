<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Mappings statuts propriétaires → codes DCSA.
 * Base initiale par compagnie — enrichie par les connecteurs (Étape 19) au fil des statuts rencontrés.
 *
 * Codes DCSA utilisés : GTIN (gate in), LOAD (chargé), DEPA (départ navire),
 * TRSH (transbordement), ARRI (arrivée navire), DISC (déchargé), GTOT (gate out),
 * RETU (restitution vide), STUF (empotage), STRP (dépotage), CUSR (mainlevée douane).
 */
class CarrierStatusMappingSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $mappings = [
            'JCGO' => [
                'Empty to shipper' => 'GTOT', 'Gate in' => 'GTIN', 'Gate In' => 'GTIN',
                'Export received at CY' => 'GTIN', 'Received at origin' => 'GTIN',
                'Loaded on vessel' => 'LOAD', 'Loaded' => 'LOAD', 'Load' => 'LOAD',
                'Vessel departure' => 'DEPA', 'Vessel departed' => 'DEPA', 'Departed' => 'DEPA',
                'Transshipment loaded' => 'TRSH', 'Transhipment' => 'TRSH',
                'Vessel arrival' => 'ARRI', 'Vessel arrived' => 'ARRI', 'Arrived' => 'ARRI',
                'Discharged from vessel' => 'DISC', 'Discharged' => 'DISC', 'Discharge' => 'DISC',
                'Gate out' => 'GTOT', 'Gate Out' => 'GTOT', 'Import gate out' => 'GTOT',
                'Empty received at CY' => 'RETU', 'Empty returned' => 'RETU', 'Empty return' => 'RETU',
            ],
            'MSCU' => [
                'Empty to shipper' => 'GTOT', 'Export received at CY' => 'GTIN',
                'Loaded on vessel' => 'LOAD', 'Vessel departure' => 'DEPA',
                'Transshipment loaded' => 'TRSH', 'Vessel arrival' => 'ARRI',
                'Discharged from vessel' => 'DISC', 'Import gate out' => 'GTOT',
                'Empty returned' => 'RETU',
            ],
            'CMDU' => [
                'CONTAINER TO SHIPPER' => 'GTOT', 'RECEIVED AT ORIGIN' => 'GTIN',
                'LOADED ON BOARD' => 'LOAD', 'VESSEL DEPARTED' => 'DEPA',
                'TRANSHIPMENT' => 'TRSH', 'VESSEL ARRIVED' => 'ARRI',
                'DISCHARGED' => 'DISC', 'CONTAINER DELIVERED' => 'GTOT',
                'EMPTY RETURNED' => 'RETU',
            ],
            'MAEU' => [
                'GATE-IN' => 'GTIN', 'LOAD' => 'LOAD', 'DEPARTURE' => 'DEPA',
                'TRANSSHIPMENT' => 'TRSH', 'ARRIVAL' => 'ARRI',
                'DISCHARGE' => 'DISC', 'GATE-OUT' => 'GTOT', 'EMPTY RETURN' => 'RETU',
            ],
            'HLCU' => [
                'Gate in export' => 'GTIN', 'Loaded' => 'LOAD', 'Vessel departed' => 'DEPA',
                'Transhipment' => 'TRSH', 'Vessel arrived' => 'ARRI',
                'Discharged' => 'DISC', 'Gate out import' => 'GTOT', 'Container returned' => 'RETU',
            ],
            'COSU' => [
                'Gate-In at Origin' => 'GTIN', 'Loaded at POL' => 'LOAD', 'Departure from POL' => 'DEPA',
                'Transshipment at Via Port' => 'TRSH', 'Arrival at POD' => 'ARRI',
                'Discharged at POD' => 'DISC', 'Gate-Out from POD' => 'GTOT', 'Empty Container Returned' => 'RETU',
            ],
            'EGLV' => [
                'Gate in' => 'GTIN', 'Loaded (FCL)' => 'LOAD', 'Vessel departure' => 'DEPA',
                'Vessel arrival' => 'ARRI', 'Discharged (FCL)' => 'DISC',
                'Gate out' => 'GTOT', 'Empty container returned' => 'RETU',
            ],
            'ONEY' => [
                'Gate In to Outbound Terminal' => 'GTIN', 'Loaded on Vessel at Port of Loading' => 'LOAD',
                'Departure from Port of Loading' => 'DEPA', 'Transshipment' => 'TRSH',
                'Arrival at Port of Discharging' => 'ARRI', 'Unloaded from Vessel at Port of Discharging' => 'DISC',
                'Gate Out from Inbound Terminal' => 'GTOT', 'Empty Container Returned from Customer' => 'RETU',
            ],
            'OOLU' => [
                'Gate In at origin CY' => 'GTIN', 'Laden on board' => 'LOAD', 'Vessel departed' => 'DEPA',
                'Vessel arrived' => 'ARRI', 'Discharged from vessel' => 'DISC',
                'Gate Out at destination CY' => 'GTOT', 'Empty returned to depot' => 'RETU',
            ],
            'YMLU' => [
                'Gate In' => 'GTIN', 'On Board' => 'LOAD', 'ETD/ATD' => 'DEPA',
                'Transhipment' => 'TRSH', 'ETA/ATA' => 'ARRI', 'Discharged' => 'DISC',
                'Gate Out' => 'GTOT', 'Empty Returned' => 'RETU',
            ],
        ];

        foreach ($mappings as $scac => $statuses) {
            foreach ($statuses as $raw => $dcsa) {
                DB::table('carrier_status_mappings')->updateOrInsert(
                    ['carrier_scac' => $scac, 'raw_status' => $raw],
                    ['id' => DB::table('carrier_status_mappings')->where(['carrier_scac' => $scac, 'raw_status' => $raw])->value('id') ?? (string) Str::uuid7(),
                        'dcsa_event_code' => $dcsa, 'created_at' => $now, 'updated_at' => $now],
                );
            }
        }
    }
}
