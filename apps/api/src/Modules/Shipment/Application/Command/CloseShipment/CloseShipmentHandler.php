<?php

declare(strict_types=1);

namespace Silaris\Modules\Shipment\Application\Command\CloseShipment;

use Illuminate\Support\Facades\DB;
use Silaris\Modules\Shared\Infrastructure\Events\DomainEventPublisher;
use Silaris\Modules\Shipment\Domain\Service\WorkflowEngine;
use Silaris\Modules\Shipment\Infrastructure\Persistence\EloquentShipmentRepository;

final readonly class CloseShipmentHandler
{
    public function __construct(
        private EloquentShipmentRepository $repository,
        private WorkflowEngine $workflow,
        private DomainEventPublisher $events,
    ) {}

    public function handle(CloseShipmentCommand $command): void
    {
        $shipment = $this->repository->get($command->shipmentId);

        // Faits vérifiables — évalués ici, jugés par le moteur selon la config tenant.
        $facts = [
            'delivery_confirmed' => $shipment->schedule()->ata !== null
                || DB::table('missions')->where('shipment_id', $shipment->id)->where('status', 'delivered')->exists(),
            'invoice_issued' => DB::table('invoices')->where('shipment_id', $shipment->id)->where('status', '<>', 'draft')->exists(),
        ];

        $shipment->close(
            $command->closedBy,
            $this->workflow->unmetCloseConditions($shipment->workflowDefinitionId, $facts),
        );

        $this->repository->save($shipment, ['closed_by' => $command->closedBy]);
        $this->events->publishFrom($shipment);
    }
}
