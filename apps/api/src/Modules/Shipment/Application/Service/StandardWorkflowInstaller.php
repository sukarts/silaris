<?php

declare(strict_types=1);

namespace Silaris\Modules\Shipment\Application\Service;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Workflow standard d'un tenant qui démarre.
 *
 * Un dossier ne peut pas exister sans workflow : la création résout l'étape
 * initiale à partir de lui. Un tenant provisionné sans workflow échoue donc dès
 * son premier dossier, longtemps après la mise en service — d'où l'installation
 * dès le provisionnement, et le rattrapage pour les tenants déjà créés.
 *
 * Les étapes reprennent la chaîne du transit : création, booking, départ,
 * transit, arrivée, dédouanement, livraison, clôture. Le transitaire les
 * adapte ensuite depuis ses paramètres.
 */
final class StandardWorkflowInstaller
{
    /** @var list<array{0: string, 1: string, 2: list<string>, 3?: array<string, mixed>}> */
    private const STEPS = [
        ['creation', 'Création', ['booking']],
        ['booking', 'Booking', ['departure'], ['required_documents' => ['commercial_invoice', 'packing_list']]],
        ['departure', 'Départ', ['transit']],
        ['transit', 'Transit', ['arrival']],
        ['arrival', 'Arrivée', ['customs']],
        ['customs', 'Dédouanement', ['delivery'], ['required_documents' => ['customs']]],
        ['delivery', 'Livraison', ['closure']],
        ['closure', 'Clôture', [], ['requires' => ['delivery_confirmed', 'invoice_issued']]],
    ];

    /**
     * Installe le workflow standard si le tenant n'en a aucun d'actif.
     *
     * @return string|null Identifiant créé, null si le tenant était déjà servi.
     */
    public function installFor(string $tenantId, ?ConnectionInterface $connection = null): ?string
    {
        $db = $connection ?? DB::connection();

        $existing = $db->table('workflow_definitions')
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->exists();

        if ($existing) {
            return null;
        }

        $workflowId = (string) Str::uuid7();
        $now = now();

        $db->table('workflow_definitions')->insert([
            'id' => $workflowId, 'tenant_id' => $tenantId,
            'name' => 'Workflow standard', 'transport_mode' => 'any', 'direction' => 'any',
            'is_default' => true, 'is_active' => true, 'version' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        foreach (self::STEPS as $position => $step) {
            $db->table('workflow_steps')->insert([
                'id' => (string) Str::uuid7(), 'workflow_definition_id' => $workflowId,
                'key' => $step[0], 'label' => $step[1], 'position' => $position + 1,
                'transitions' => json_encode($step[2]),
                'conditions' => json_encode($step[3] ?? []),
                'actions' => json_encode([]),
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        return $workflowId;
    }
}
