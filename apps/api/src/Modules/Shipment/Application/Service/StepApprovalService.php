<?php

declare(strict_types=1);

namespace Silaris\Modules\Shipment\Application\Service;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Silaris\Modules\Shared\Infrastructure\Tenancy\TenantContext;
use Silaris\Modules\Shipment\Domain\Exception\StepRequestPending;

/**
 * Validation des franchissements d'étape.
 *
 * L'agent transit prépare le dossier ; le responsable exploitation valide le
 * passage à l'étape suivante. Chaque franchissement engage — un booking réserve
 * du fret, un départ déclenche des délais, une clôture ferme la facturation —
 * et mérite donc deux regards.
 *
 * Le responsable, lui, avance sans intermédiaire : lui demander de valider ses
 * propres demandes n'apporterait rien.
 */
final readonly class StepApprovalService
{
    public function __construct(private TenantContext $tenant) {}

    /** Le demandeur peut-il franchir l'étape lui-même ? */
    public function canDecide(): bool
    {
        $user = Auth::user();

        return $user !== null && $user->hasPermission('shipments.approve_step');
    }

    /**
     * Enregistre une demande de passage. Une seule reste ouverte par dossier,
     * sans quoi deux agents proposeraient deux étapes et la validation
     * deviendrait ambiguë.
     */
    public function request(string $shipmentId, string $fromStep, string $toStep): string
    {
        $pending = DB::table('shipment_step_requests')
            ->where('shipment_id', $shipmentId)->where('status', 'pending')
            ->first(['to_step']);

        if ($pending !== null) {
            throw StepRequestPending::already((string) $pending->to_step);
        }

        $id = (string) Str::uuid7();
        DB::table('shipment_step_requests')->insert([
            'id' => $id,
            'tenant_id' => $this->tenant->id(),
            'shipment_id' => $shipmentId,
            'from_step' => $fromStep,
            'to_step' => $toStep,
            'status' => 'pending',
            'requested_by' => Auth::id(),
            'requested_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('shipment_events')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $this->tenant->id(),
            'shipment_id' => $shipmentId,
            'type' => 'system',
            'title' => "Passage à « {$toStep} » proposé — en attente du responsable exploitation",
            'payload' => json_encode(['from' => $fromStep, 'to' => $toStep, 'requested_by' => Auth::id()]),
            'source' => 'system',
            'occurred_at' => now(),
        ]);

        return $id;
    }

    /** Clôt la demande après décision du responsable. */
    public function close(string $requestId, string $decision, ?string $note): void
    {
        DB::table('shipment_step_requests')->where('id', $requestId)->update([
            'status' => $decision,
            'decided_by' => Auth::id(),
            'decided_at' => now(),
            'decision_note' => $note,
            'updated_at' => now(),
        ]);
    }
}
