<?php

declare(strict_types=1);

namespace Silaris\Modules\Road\Interface\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Silaris\Modules\Road\Application\Service\PositionIngestionService;
use Silaris\Modules\Shared\Infrastructure\Tenancy\TenantContext;

/**
 * Réception des positions émises par les balises véhicule.
 *
 * Authentifié par clé de balise (en-tête X-Device-Key) et non par session :
 * un traceur GPS n'a pas d'utilisateur. La clé porte le tenant — le contexte
 * est donc établi à partir d'elle, jamais d'une donnée fournie par l'appelant.
 */
class TelematicsController
{
    /** Un lot correspond au tampon d'une balise sortie de zone couverte. */
    private const MAX_POINTS = 200;

    public function __construct(
        private readonly PositionIngestionService $ingestion,
        private readonly TenantContext $tenant,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $device = $this->authenticateDevice($request);
        if ($device === null) {
            return problem(401, 'Balise non reconnue', 'https://silaris.app/errors/device-unauthorized');
        }

        $data = $request->validate([
            'positions' => ['required', 'array', 'min:1', 'max:'.self::MAX_POINTS],
            'positions.*.latitude' => ['required', 'numeric', 'between:-90,90'],
            'positions.*.longitude' => ['required', 'numeric', 'between:-180,180'],
            'positions.*.recorded_at' => ['required', 'date', 'before_or_equal:'.now()->addMinutes(5)->toIso8601String()],
            'positions.*.speed_kmh' => ['sometimes', 'nullable', 'numeric', 'between:0,300'],
            'positions.*.heading' => ['sometimes', 'nullable', 'integer', 'between:0,359'],
        ]);

        $this->tenant->set($device->tenant_id);
        $result = $this->ingestion->ingest($device, $data['positions']);

        return response()->json($result, 202);
    }

    /** Résolution par préfixe puis vérification du hachage — jamais de comparaison en clair. */
    private function authenticateDevice(Request $request): ?object
    {
        $key = (string) $request->header('X-Device-Key', '');
        if (strlen($key) < 24) {
            return null;
        }

        $candidates = DB::connection(config('database.system_connection'))
            ->table('tracking_devices')
            ->where('key_prefix', substr($key, 0, 12))
            ->where('is_active', true)
            ->get();

        foreach ($candidates as $device) {
            if (Hash::check($key, $device->api_key_hash)) {
                return $device;
            }
        }

        return null;
    }

    /** GET /v1/missions/{id}/positions — trace de la mission pour l'exploitation. */
    public function missionTrack(Request $request, string $missionId): JsonResponse
    {
        $mission = DB::table('missions')->where('id', $missionId)->firstOrFail();

        $validated = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:1000'],
        ]);

        $positions = DB::table('device_positions')
            ->where('mission_id', $missionId)
            ->orderByDesc('recorded_at')
            ->limit((int) ($validated['limit'] ?? 200))
            ->get(['latitude', 'longitude', 'speed_kmh', 'heading', 'recorded_at']);

        $stops = DB::table('mission_stops')
            ->where('mission_id', $missionId)
            ->orderBy('position')
            ->get(['label', 'latitude', 'longitude', 'planned_at', 'arrived_at']);

        $last = $positions->first();

        return response()->json([
            'mission' => ['id' => $mission->id, 'reference' => $mission->reference, 'status' => $mission->status],
            'last_position' => $last,
            'distance_to_next_stop_m' => $this->distanceToNextStop($last, $stops),
            'positions' => $positions->reverse()->values(),
            'stops' => $stops,
        ]);
    }

    /** Distance restante jusqu'au prochain arrêt non atteint ; null si inconnue. */
    private function distanceToNextStop(?object $last, mixed $stops): ?int
    {
        if ($last === null) {
            return null;
        }

        $next = collect($stops)->first(fn ($s) => $s->arrived_at === null && $s->latitude !== null);
        if ($next === null) {
            return null;
        }

        return (int) round(PositionIngestionService::distanceMeters(
            (float) $last->latitude, (float) $last->longitude,
            (float) $next->latitude, (float) $next->longitude,
        ));
    }

    /** POST /v1/road/devices — enrôle une balise, renvoie sa clé UNE seule fois. */
    public function storeDevice(Request $request): JsonResponse
    {
        $data = $request->validate([
            'identifier' => ['required', 'string', 'max:64'],
            'label' => ['required', 'string', 'max:255'],
            'kind' => ['sometimes', Rule::in(['beacon', 'phone', 'gateway'])],
            'truck_id' => ['nullable', 'uuid', 'exists:trucks,id'],
        ]);

        $key = 'dev_'.bin2hex(random_bytes(24));
        $id = (string) Str::uuid7();

        DB::table('tracking_devices')->insert([
            'id' => $id,
            'tenant_id' => $this->tenant->id(),
            'identifier' => $data['identifier'],
            'label' => $data['label'],
            'kind' => $data['kind'] ?? 'beacon',
            'api_key_hash' => Hash::make($key),
            'key_prefix' => substr($key, 0, 12),
            'truck_id' => $data['truck_id'] ?? null,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'id' => $id,
            'identifier' => $data['identifier'],
            // Affichée une seule fois : seul le haché est conservé.
            'api_key' => $key,
            'ingest_url' => rtrim((string) config('app.url'), '/').'/api/v1/telematics/positions',
        ], 201);
    }

    /** GET /v1/road/devices — parc de balises et dernier contact. */
    public function devices(): JsonResponse
    {
        return response()->json([
            'data' => DB::table('tracking_devices as d')
                ->leftJoin('trucks as t', 't.id', '=', 'd.truck_id')
                ->orderBy('d.label')
                ->get([
                    'd.id', 'd.identifier', 'd.label', 'd.kind', 'd.key_prefix',
                    'd.truck_id', 'd.is_active', 'd.last_seen_at', 't.plate_number',
                ]),
        ]);
    }

    /** PATCH /v1/road/devices/{id} — affectation véhicule, activation. */
    public function updateDevice(Request $request, string $deviceId): JsonResponse
    {
        $data = $request->validate([
            'label' => ['sometimes', 'string', 'max:255'],
            'truck_id' => ['sometimes', 'nullable', 'uuid', 'exists:trucks,id'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $updated = DB::table('tracking_devices')->where('id', $deviceId)
            ->update([...$data, 'updated_at' => now()]);
        abort_if($updated === 0, 404);

        return response()->json(DB::table('tracking_devices')->where('id', $deviceId)->first());
    }
}
