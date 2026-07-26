<?php

declare(strict_types=1);

namespace Silaris\Modules\Road\Application\Service;

use Illuminate\Support\Facades\DB;
use Silaris\Modules\Road\Infrastructure\Persistence\Model\MissionModel;

/**
 * Bon de livraison — document remis au destinataire et joint au dossier.
 *
 * Il atteste d'une remise : quoi, à qui, où, quand, contre quelle signature.
 * Le transporteur affrété, le chauffeur et le véhicule en sont volontairement
 * absents : le même document part chez le client, qui n'a pas à connaître les
 * arrangements de sous-traitance du transitaire.
 */
final class DeliveryNoteBuilder
{
    /**
     * @return array{mission: MissionModel, pod: object, client: object|null, stops: list<object>, lines: list<object>, company_id: string}
     */
    public function build(MissionModel $mission): array
    {
        $mission->loadMissing(['pod', 'shipment', 'stops']);

        $client = $mission->shipment === null ? null : DB::table('parties')
            ->where('id', $mission->shipment->client_id)
            ->first(['name', 'code']);

        return [
            'mission' => $mission,
            'pod' => $mission->pod,
            'client' => $client,
            'stops' => $mission->stops->all(),
            'lines' => $this->lines($mission),
            'company_id' => $this->companyId($mission),
        ];
    }

    /**
     * Marchandise remise : les colis du dossier, à défaut ses conteneurs. Sans
     * dossier rattaché, la mission ne porte que ses étapes.
     *
     * @return list<object>
     */
    private function lines(MissionModel $mission): array
    {
        if ($mission->shipment === null) {
            return [];
        }

        $packages = DB::table('packages')
            ->leftJoin('containers', 'containers.id', '=', 'packages.container_id')
            ->where('packages.shipment_id', $mission->shipment->id)
            ->orderBy('packages.reference')
            ->limit(100)
            ->get([
                'packages.reference',
                'packages.description',
                'packages.weight_kg AS weight',
                'containers.number AS container_number',
            ]);

        if ($packages->isNotEmpty()) {
            return $packages->all();
        }

        return DB::table('container_assignments')
            ->join('containers', 'containers.id', '=', 'container_assignments.container_id')
            ->where('container_assignments.shipment_id', $mission->shipment->id)
            ->limit(100)
            ->get([
                'containers.number AS reference',
                'containers.size_type AS description',
                DB::raw('NULL::numeric AS weight'),
                'containers.number AS container_number',
            ])->all();
    }

    /** Société émettrice : celle du dossier, sinon la seule société du tenant. */
    private function companyId(MissionModel $mission): string
    {
        return $mission->shipment->company_id
            ?? (string) DB::table('companies')->orderBy('created_at')->value('id');
    }

    /** Nom de fichier stable, lisible dans une boîte mail comme dans un dossier. */
    public static function fileName(MissionModel $mission): string
    {
        return 'bon-livraison-'.strtolower($mission->reference).'.pdf';
    }
}
