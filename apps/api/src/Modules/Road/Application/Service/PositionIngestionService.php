<?php

declare(strict_types=1);

namespace Silaris\Modules\Road\Application\Service;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ingestion des positions d'une balise véhicule.
 *
 *  1. rattache chaque point à la mission en cours du camion porteur ;
 *  2. déduplique (une balise rejoue son tampon après une zone sans réseau) ;
 *  3. géorepérage : un arrêt approché à moins de GEOFENCE_METERS est marqué
 *     comme atteint, avec trace dans la timeline du dossier.
 *
 * Le contexte tenant est posé par l'appelant à partir de la balise.
 */
final class PositionIngestionService
{
    /** Rayon d'arrivée : au-delà, un simple passage à proximité ferait un faux positif. */
    private const GEOFENCE_METERS = 250;

    private const EARTH_RADIUS_METERS = 6_371_000;

    /**
     * @param  list<array{latitude: float, longitude: float, recorded_at: string, speed_kmh?: float|null, heading?: int|null}>  $points
     * @return array{stored: int, duplicates: int, mission_id: string|null, arrivals: int}
     */
    public function ingest(object $device, array $points): array
    {
        $missionId = $this->currentMissionId($device);
        $stored = 0;
        $duplicates = 0;
        $arrivals = 0;

        foreach ($points as $point) {
            $recordedAt = Carbon::parse($point['recorded_at']);

            $inserted = DB::table('device_positions')->insertOrIgnore([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $device->tenant_id,
                'device_id' => $device->id,
                'mission_id' => $missionId,
                'latitude' => $point['latitude'],
                'longitude' => $point['longitude'],
                'speed_kmh' => $point['speed_kmh'] ?? null,
                'heading' => $point['heading'] ?? null,
                'recorded_at' => $recordedAt,
            ]);

            if ($inserted === 0) {
                $duplicates++;

                continue;
            }
            $stored++;

            if ($missionId !== null) {
                $arrivals += $this->markReachedStops($missionId, (float) $point['latitude'], (float) $point['longitude'], $recordedAt);
            }
        }

        DB::table('tracking_devices')->where('id', $device->id)
            ->update(['last_seen_at' => now(), 'updated_at' => now()]);

        return ['stored' => $stored, 'duplicates' => $duplicates, 'mission_id' => $missionId, 'arrivals' => $arrivals];
    }

    /** Mission en cours du camion porteur ; null si la balise n'est affectée à aucune tournée active. */
    private function currentMissionId(object $device): ?string
    {
        if ($device->truck_id === null) {
            return null;
        }

        return DB::table('missions')
            ->where('tenant_id', $device->tenant_id)
            ->where('truck_id', $device->truck_id)
            ->where('status', 'in_progress')
            ->orderByDesc('started_at')
            ->value('id');
    }

    /** Marque les arrêts atteints ; renvoie le nombre d'arrivées nouvellement détectées. */
    private function markReachedStops(string $missionId, float $latitude, float $longitude, Carbon $at): int
    {
        $stops = DB::table('mission_stops')
            ->where('mission_id', $missionId)
            ->whereNull('arrived_at')
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->get(['id', 'label', 'latitude', 'longitude']);

        $arrivals = 0;
        foreach ($stops as $stop) {
            $distance = self::distanceMeters($latitude, $longitude, (float) $stop->latitude, (float) $stop->longitude);
            if ($distance > self::GEOFENCE_METERS) {
                continue;
            }

            DB::table('mission_stops')->where('id', $stop->id)->update(['arrived_at' => $at, 'updated_at' => now()]);
            $arrivals++;

            $shipmentId = DB::table('missions')->where('id', $missionId)->value('shipment_id');
            if ($shipmentId !== null) {
                DB::table('shipment_events')->insert([
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => DB::table('missions')->where('id', $missionId)->value('tenant_id'),
                    'shipment_id' => $shipmentId,
                    'type' => 'tracking',
                    'title' => "Véhicule arrivé — {$stop->label}",
                    'payload' => json_encode(['source' => 'telematics', 'stop_id' => $stop->id, 'distance_m' => round($distance)]),
                    'occurred_at' => $at,
                ]);
            }
        }

        return $arrivals;
    }

    /** Distance orthodromique (haversine) en mètres. */
    public static function distanceMeters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return self::EARTH_RADIUS_METERS * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
