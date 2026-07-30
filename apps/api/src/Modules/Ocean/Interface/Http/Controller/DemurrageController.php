<?php

declare(strict_types=1);

namespace Silaris\Modules\Ocean\Interface\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Silaris\Modules\Ocean\Application\Service\FreeTimeTracker;

/**
 * Surveillance des franchises — surestaries et détention.
 *
 * L'écran répond à la question de chaque matin : quelles boîtes faut-il sortir
 * du port, ou restituer à vide, aujourd'hui, pour ne pas payer ? Une même boîte
 * peut y figurer deux fois — une surestarie qui court au terminal, puis une
 * détention une fois sortie —, ce sont deux échéances et deux factures.
 */
class DemurrageController
{
    public function __construct(private readonly FreeTimeTracker $freeTime) {}

    /** GET /v1/demurrage — compteurs en cours, triés par urgence. */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'within_days' => ['sometimes', 'integer', 'min:0', 'max:60'],
            'client_id' => ['sometimes', 'uuid'],
            'kind' => ['sometimes', 'in:demurrage,detention'],
        ]);
        $horizon = (int) ($validated['within_days'] ?? 7);
        $today = Carbon::today();

        $rows = collect(FreeTimeTracker::KINDS)
            ->when($validated['kind'] ?? null, fn ($kinds, $only) => $kinds->filter(fn ($k) => $k === $only))
            ->flatMap(fn (string $kind) => $this->rowsForKind($kind, $validated['client_id'] ?? null, $today))
            ->filter(fn (array $row): bool => $row['days_remaining'] <= $horizon)
            ->sortBy('free_time_ends_at')
            ->values();

        return response()->json([
            'data' => $rows,
            'summary' => [
                'overdue' => $rows->where('severity', 'overdue')->count(),
                'critical' => $rows->where('severity', 'critical')->count(),
                'warning' => $rows->where('severity', 'warning')->count(),
                'demurrage' => $rows->where('kind', 'demurrage')->count(),
                'detention' => $rows->where('kind', 'detention')->count(),
            ],
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rowsForKind(string $kind, ?string $clientId, Carbon $today): array
    {
        $endsAt = "ca.{$kind}_ends_at";
        $days = "ca.{$kind}_days";

        return DB::table('container_assignments AS ca')
            ->join('containers AS c', 'c.id', '=', 'ca.container_id')
            ->join('shipments AS s', 's.id', '=', 'ca.shipment_id')
            ->leftJoin('parties AS p', 'p.id', '=', 's.client_id')
            ->whereNotNull($endsAt)
            // Le compteur ne court plus une fois son jalon de fermeture atteint :
            // sortie du port pour la surestarie, restitution du vide pour la détention.
            ->where(fn ($query) => $query
                ->where(fn ($import) => $import->where('s.direction', '<>', 'export')
                    ->whereNull('ca.'.FreeTimeTracker::stopColumn($kind, 'import')))
                ->orWhere(fn ($export) => $export->where('s.direction', 'export')
                    ->whereNull('ca.'.FreeTimeTracker::stopColumn($kind, 'export'))))
            ->when($clientId, fn ($query, $client) => $query->where('s.client_id', $client))
            ->orderBy($endsAt)
            ->limit(200)
            ->get([
                'ca.id', "{$days} AS free_days", "{$endsAt} AS ends_at",
                'c.number AS container_number', 'c.size_type',
                's.id AS shipment_id', 's.reference', 's.direction',
                'p.name AS client_name',
            ])
            ->map(function (object $row) use ($kind, $today): array {
                $deadline = Carbon::parse($row->ends_at)->startOfDay();
                $remaining = (int) $today->diffInDays($deadline, false);

                return [
                    'assignment_id' => $row->id,
                    'kind' => $kind,
                    'container_number' => $row->container_number,
                    'size_type' => $row->size_type,
                    'shipment_id' => $row->shipment_id,
                    'reference' => $row->reference,
                    'direction' => $row->direction,
                    'client_name' => $row->client_name,
                    'free_time_days' => $row->free_days,
                    'free_time_ends_at' => $deadline->toDateString(),
                    'days_remaining' => $remaining,
                    'severity' => self::severityOf($remaining),
                ];
            })
            ->all();
    }

    /**
     * PATCH /v1/demurrage/free-time — franchises négociées du dossier.
     * Elles se portent sur le connaissement à l'import, sur le booking à l'export.
     */
    public function updateFreeTime(Request $request): JsonResponse
    {
        $data = $request->validate([
            'shipment_id' => ['required', 'uuid', 'exists:shipments,id'],
            'demurrage_free_days' => ['nullable', 'integer', 'min:0', 'max:180'],
            'detention_free_days' => ['nullable', 'integer', 'min:0', 'max:180'],
        ]);

        $direction = DB::table('shipments')->where('id', $data['shipment_id'])->value('direction');
        $isExport = $direction === 'export';

        $payload = [
            'demurrage_free_days' => $data['demurrage_free_days'] ?? null,
            'detention_free_days' => $data['detention_free_days'] ?? null,
            'updated_at' => now(),
        ];

        $updated = $isExport
            ? DB::table('bookings')->where('shipment_id', $data['shipment_id'])->update($payload)
            : DB::table('bills_of_lading')->where('shipment_id', $data['shipment_id'])->where('type', 'master')->update($payload);

        if ($updated === 0) {
            return response()->json([
                'message' => $isExport
                    ? "Aucun booking sur ce dossier : la franchise export s'y rattache."
                    : "Aucun connaissement maître sur ce dossier : la franchise import s'y rattache.",
            ], 422);
        }

        return response()->json(['containers_refreshed' => $this->freeTime->refreshShipment($data['shipment_id'])]);
    }

    /** Trois jours de marge : le temps d'organiser un enlèvement ou une restitution. */
    private static function severityOf(int $daysRemaining): string
    {
        return match (true) {
            $daysRemaining < 0 => 'overdue',
            $daysRemaining <= 3 => 'critical',
            default => 'warning',
        };
    }
}
