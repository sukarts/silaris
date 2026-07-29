<?php

declare(strict_types=1);

namespace Silaris\Modules\Shipment\Interface\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Silaris\Modules\Shared\Infrastructure\Tenancy\TenantContext;

/**
 * Décision sur les ouvertures de dossier sans cotation.
 *
 * L'exploitant du terrain constate l'urgence, la direction en répond : elle
 * seule tranche, et chaque décision est nominative et horodatée. Un dossier
 * refusé n'est pas supprimé — la marchandise existe — mais il reste bloqué
 * tant qu'une cotation ne vient pas le régulariser.
 */
class QuoteWaiverController
{
    public function __construct(private readonly TenantContext $tenant) {}

    /** GET /v1/shipments/waivers — file d'attente de la direction. */
    public function index(): JsonResponse
    {
        $rows = DB::table('shipments AS s')
            ->leftJoin('parties AS p', 'p.id', '=', 's.client_id')
            ->leftJoin('users AS u', 'u.id', '=', 's.quote_waiver_requested_by')
            ->where('s.quote_waiver_status', 'pending')
            ->orderBy('s.quote_waiver_requested_at')
            ->limit(100)
            ->get([
                's.id', 's.reference', 's.direction', 's.mode',
                's.origin_locode', 's.destination_locode',
                's.quote_waiver_reason', 's.quote_waiver_requested_at',
                'p.name AS client_name',
                DB::raw("COALESCE(u.first_name || ' ' || u.last_name, '—') AS requested_by"),
            ]);

        return response()->json(['data' => $rows]);
    }

    /** POST /v1/shipments/{id}/waiver/decide — accorder ou refuser. */
    public function decide(Request $request, string $shipmentId): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:approved,rejected'],
            // Un refus s'explique : l'exploitant doit savoir quoi corriger.
            'note' => ['required_if:decision,rejected', 'nullable', 'string', 'max:500'],
        ]);

        $shipment = DB::table('shipments')->where('id', $shipmentId)
            ->first(['id', 'reference', 'quote_waiver_status']);

        if ($shipment === null) {
            return response()->json(['message' => 'Dossier introuvable.'], 404);
        }

        if ($shipment->quote_waiver_status !== 'pending') {
            return response()->json([
                'message' => $shipment->quote_waiver_status === null
                    ? "Le dossier {$shipment->reference} repose sur une cotation : rien à valider."
                    : "La demande du dossier {$shipment->reference} a déjà été traitée.",
            ], 422);
        }

        $decidedBy = Auth::id();
        DB::table('shipments')->where('id', $shipmentId)->update([
            'quote_waiver_status' => $data['decision'],
            'quote_waiver_decided_by' => $decidedBy,
            'quote_waiver_decided_at' => now(),
            'quote_waiver_decision_note' => $data['note'] ?? null,
            'updated_at' => now(),
        ]);

        $approved = $data['decision'] === 'approved';
        DB::table('shipment_events')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $this->tenant->id(),
            'shipment_id' => $shipmentId,
            'type' => 'system',
            'title' => $approved
                ? 'Ouverture sans cotation validée par la direction'
                : 'Ouverture sans cotation refusée par la direction',
            'payload' => json_encode(['decided_by' => $decidedBy, 'note' => $data['note'] ?? null]),
            'source' => 'system',
            'occurred_at' => now(),
        ]);

        return response()->json(['status' => $data['decision']]);
    }
}
