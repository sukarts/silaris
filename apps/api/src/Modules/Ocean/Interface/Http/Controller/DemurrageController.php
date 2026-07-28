<?php

declare(strict_types=1);

namespace Silaris\Modules\Ocean\Interface\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Silaris\Modules\Ocean\Application\Service\FreeTimeTracker;

/**
 * Surveillance des franchises — les conteneurs dont l'immobilisation va
 * commencer à coûter, ou coûte déjà.
 *
 * L'écran répond à une seule question, celle qui se pose tous les matins :
 * quelles boîtes dois-je sortir ou restituer aujourd'hui pour ne pas payer ?
 */
class DemurrageController
{
    public function __construct(private readonly FreeTimeTracker $freeTime) {}

    /** GET /v1/demurrage — conteneurs en cours, triés par urgence. */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'within_days' => ['sometimes', 'integer', 'min:0', 'max:60'],
            'client_id' => ['sometimes', 'uuid'],
        ]);
        $horizon = (int) ($validated['within_days'] ?? 7);

        $rows = DB::table('container_assignments AS ca')
            ->join('containers AS c', 'c.id', '=', 'ca.container_id')
            ->join('shipments AS s', 's.id', '=', 'ca.shipment_id')
            ->leftJoin('parties AS p', 'p.id', '=', 's.client_id')
            ->whereNotNull('ca.free_time_ends_at')
            // Le compteur s'arrête à la restitution du vide (import) ou à
            // l'entrée du plein (export) : au-delà, plus rien ne court.
            ->where(fn ($query) => $query
                ->where(fn ($import) => $import->where('s.direction', '<>', 'export')
                    ->whereNull('ca.'.FreeTimeTracker::stopColumn('import')))
                ->orWhere(fn ($export) => $export->where('s.direction', 'export')
                    ->whereNull('ca.'.FreeTimeTracker::stopColumn('export'))))
            ->when($validated['client_id'] ?? null, fn ($query, $client) => $query->where('s.client_id', $client))
            ->orderBy('ca.free_time_ends_at')
            ->limit(200)
            ->get([
                'ca.id', 'ca.free_time_days', 'ca.free_time_ends_at', 'ca.discharged_at', 'ca.gate_out_at',
                'c.number AS container_number', 'c.size_type',
                's.id AS shipment_id', 's.reference', 's.direction',
                'p.name AS client_name',
            ]);

        $today = Carbon::today();
        $data = $rows
            ->map(function (object $row) use ($today): array {
                $deadline = Carbon::parse($row->free_time_ends_at)->startOfDay();
                $remaining = (int) $today->diffInDays($deadline, false);

                return [
                    'assignment_id' => $row->id,
                    'container_number' => $row->container_number,
                    'size_type' => $row->size_type,
                    'shipment_id' => $row->shipment_id,
                    'reference' => $row->reference,
                    'direction' => $row->direction,
                    'client_name' => $row->client_name,
                    'free_time_days' => $row->free_time_days,
                    'free_time_ends_at' => $deadline->toDateString(),
                    'days_remaining' => $remaining,
                    'severity' => self::severityOf($remaining),
                ];
            })
            ->filter(fn (array $row): bool => $row['days_remaining'] <= $horizon)
            ->values();

        return response()->json([
            'data' => $data,
            'summary' => [
                'overdue' => $data->where('severity', 'overdue')->count(),
                'critical' => $data->where('severity', 'critical')->count(),
                'warning' => $data->where('severity', 'warning')->count(),
            ],
        ]);
    }

    /**
     * PATCH /v1/demurrage/free-time — franchise négociée du dossier.
     * Elle se porte sur le connaissement à l'import, sur le booking à l'export.
     */
    public function updateFreeTime(Request $request): JsonResponse
    {
        $data = $request->validate([
            'shipment_id' => ['required', 'uuid', 'exists:shipments,id'],
            'free_time_days' => ['required', 'integer', 'min:0', 'max:180'],
        ]);

        $direction = DB::table('shipments')->where('id', $data['shipment_id'])->value('direction');
        $isExport = $direction === 'export';

        $updated = $isExport
            ? DB::table('bookings')->where('shipment_id', $data['shipment_id'])
                ->update(['free_time_days' => $data['free_time_days'], 'updated_at' => now()])
            : DB::table('bills_of_lading')->where('shipment_id', $data['shipment_id'])->where('type', 'master')
                ->update(['free_time_days' => $data['free_time_days'], 'updated_at' => now()]);

        if ($updated === 0) {
            return response()->json([
                'message' => $isExport
                    ? "Aucun booking sur ce dossier : la franchise export s'y rattache."
                    : "Aucun connaissement maître sur ce dossier : la franchise import s'y rattache.",
            ], 422);
        }

        return response()->json(['containers_refreshed' => $this->freeTime->refreshShipment($data['shipment_id'])]);
    }

    /** Trois jours de marge : le temps d'organiser un enlèvement. */
    private static function severityOf(int $daysRemaining): string
    {
        return match (true) {
            $daysRemaining < 0 => 'overdue',
            $daysRemaining <= 3 => 'critical',
            default => 'warning',
        };
    }
}
