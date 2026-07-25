<?php

declare(strict_types=1);

namespace Silaris\Modules\Shipment\Infrastructure\Persistence;

use Silaris\Modules\Shipment\Domain\Model\Shipment;
use Silaris\Modules\Shipment\Domain\Repository\ShipmentRepository;
use Silaris\Modules\Shipment\Infrastructure\Persistence\Model\ShipmentModel;

final readonly class EloquentShipmentRepository implements ShipmentRepository
{
    public function __construct(private ShipmentMapper $mapper) {}

    public function get(string $id): Shipment
    {
        return $this->mapper->toDomain(ShipmentModel::findOrFail($id));
    }

    public function findByReference(string $reference): ?Shipment
    {
        $model = ShipmentModel::where('reference', $reference)->first();

        return $model === null ? null : $this->mapper->toDomain($model);
    }

    /**
     * Persiste l'agrégat. À la création, $extraAttributes porte les colonnes
     * hors domaine (agence, société, agent, incoterm, trajet…).
     *
     * @param  array<string, mixed>  $extraAttributes
     */
    public function save(Shipment $shipment, array $extraAttributes = []): void
    {
        $model = ShipmentModel::find($shipment->id) ?? new ShipmentModel(['id' => $shipment->id]);

        if (! $model->exists) {
            $model->fill([
                'tenant_id' => $shipment->tenantId,
                'reference' => $shipment->reference,
                'client_id' => $shipment->clientId,
                'workflow_definition_id' => $shipment->workflowDefinitionId,
                'direction' => $shipment->direction->value,
                'mode' => $shipment->mode->value,
                ...$extraAttributes,
            ]);
        }

        $model->fill($this->mapper->domainAttributes($shipment));
        $model->save();
    }

    public function nextReference(string $branchCode, int $year): string
    {
        throw new \RuntimeException('Utiliser ReferenceGenerator (Application port).');
    }
}
