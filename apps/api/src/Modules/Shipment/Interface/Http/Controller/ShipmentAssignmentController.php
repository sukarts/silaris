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
 * Affectation d'un dossier à un agent transit.
 *
 * Le chef de service répartit la charge de son équipe : il ouvre les dossiers
 * et désigne qui les tient. L'agent, lui, ne se saisit pas d'un dossier de sa
 * propre initiative — la répartition est une décision d'organisation.
 *
 * Un chef ne dispose que de son service, comme pour la validation des étapes.
 */
class ShipmentAssignmentController
{
    public function __construct(private readonly TenantContext $tenant) {}

    /** GET /v1/shipments/assignable-agents — l'équipe du chef. */
    public function agents(): JsonResponse
    {
        $service = self::ownService();

        $agents = DB::table('users')
            ->where('is_active', true)
            ->when($service !== null, fn ($query) => $query->where('service_id', $service))
            ->orderBy('last_name')
            ->limit(200)
            ->get(['id', 'first_name', 'last_name', 'email', 'service_id']);

        return response()->json(['data' => $agents]);
    }

    /** POST /v1/shipments/{id}/assign — confie le dossier à un agent. */
    public function assign(Request $request, string $shipmentId): JsonResponse
    {
        $data = $request->validate([
            'agent_id' => ['required', 'uuid', 'exists:users,id'],
        ]);

        $shipment = DB::table('shipments')->where('id', $shipmentId)
            ->first(['id', 'reference', 'service_id', 'agent_id']);

        if ($shipment === null) {
            return response()->json(['message' => 'Dossier introuvable.'], 404);
        }

        $service = self::ownService();
        if ($service !== null && $shipment->service_id !== $service) {
            return response()->json(['message' => "Ce dossier relève d'un autre service."], 403);
        }

        $agent = DB::table('users')->where('id', $data['agent_id'])
            ->first(['id', 'first_name', 'last_name', 'service_id', 'is_active']);

        if ($agent->is_active === false) {
            return response()->json(['message' => 'Cet utilisateur est désactivé.'], 422);
        }

        // Confier un dossier à quelqu'un d'un autre service brouillerait la
        // ligne de validation : son chef n'en répondrait pas.
        if ($shipment->service_id !== null && $agent->service_id !== $shipment->service_id) {
            return response()->json([
                'message' => "Cet agent n'appartient pas au service du dossier.",
            ], 422);
        }

        DB::table('shipments')->where('id', $shipmentId)
            ->update(['agent_id' => $agent->id, 'updated_at' => now()]);

        DB::table('shipment_events')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $this->tenant->id(),
            'shipment_id' => $shipmentId,
            'type' => 'system',
            'title' => "Dossier confié à {$agent->first_name} {$agent->last_name}",
            'payload' => json_encode(['agent_id' => $agent->id, 'assigned_by' => Auth::id()]),
            'source' => 'system',
            'occurred_at' => now(),
        ]);

        return response()->json(['agent_id' => $agent->id]);
    }

    /** Service du chef, ou null quand la portée est globale. */
    private static function ownService(): ?string
    {
        $user = Auth::user();
        if ($user === null) {
            return null;
        }

        return $user->hasPermission('shipments.approve_step_all') ? null : $user->service_id;
    }
}
