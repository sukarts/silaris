<?php

declare(strict_types=1);

namespace Silaris\Modules\Shipment\Infrastructure\Persistence;

use DateTimeImmutable;
use Silaris\Modules\Shipment\Domain\Model\Shipment;
use Silaris\Modules\Shipment\Domain\ValueObject\Schedule;
use Silaris\Modules\Shipment\Infrastructure\Persistence\Model\ShipmentModel;

/** Conversion agrégat Domain ↔ modèle Eloquent. */
final class ShipmentMapper
{
    public function toDomain(ShipmentModel $model): Shipment
    {
        return Shipment::reconstitute(
            id: $model->id,
            tenantId: $model->tenant_id,
            reference: $model->reference,
            clientId: $model->client_id,
            workflowDefinitionId: $model->workflow_definition_id,
            direction: $model->direction,
            mode: $model->mode,
            status: $model->status,
            priority: $model->priority,
            schedule: new Schedule(
                etd: $model->etd ? DateTimeImmutable::createFromInterface($model->etd) : null,
                eta: $model->eta ? DateTimeImmutable::createFromInterface($model->eta) : null,
                atd: $model->atd ? DateTimeImmutable::createFromInterface($model->atd) : null,
                ata: $model->ata ? DateTimeImmutable::createFromInterface($model->ata) : null,
                etaInitial: $model->eta_initial ? DateTimeImmutable::createFromInterface($model->eta_initial) : null,
            ),
            closedAt: $model->closed_at ? DateTimeImmutable::createFromInterface($model->closed_at) : null,
        );
    }

    /** @return array<string, mixed> Colonnes possédées par le domaine. */
    public function domainAttributes(Shipment $shipment): array
    {
        $schedule = $shipment->schedule();

        return [
            'status' => $shipment->status(),
            'priority' => $shipment->priority()->value,
            'etd' => $schedule->etd,
            'eta' => $schedule->eta,
            'atd' => $schedule->atd,
            'ata' => $schedule->ata,
            'eta_initial' => $schedule->etaInitial,
            'closed_at' => $shipment->closedAt(),
        ];
    }
}
