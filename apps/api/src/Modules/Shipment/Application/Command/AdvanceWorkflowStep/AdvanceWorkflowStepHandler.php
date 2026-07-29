<?php

declare(strict_types=1);

namespace Silaris\Modules\Shipment\Application\Command\AdvanceWorkflowStep;

use Illuminate\Support\Facades\DB;
use Silaris\Modules\Documents\Infrastructure\Persistence\Model\DocumentModel;
use Silaris\Modules\Shared\Domain\Exception\DomainException;
use Silaris\Modules\Shared\Infrastructure\Events\DomainEventPublisher;
use Silaris\Modules\Shipment\Domain\Exception\QuoteWaiverPending;
use Silaris\Modules\Shipment\Domain\Service\WorkflowEngine;
use Silaris\Modules\Shipment\Infrastructure\Persistence\EloquentShipmentRepository;

final class MissingRequiredDocuments extends DomainException
{
    /** @param list<string> $types */
    public static function ofTypes(array $types): self
    {
        return new self('Documents requis manquants : '.implode(', ', $types));
    }

    public function errorCode(): string
    {
        return 'shipment.missing_required_documents';
    }
}

final readonly class AdvanceWorkflowStepHandler
{
    public function __construct(
        private EloquentShipmentRepository $repository,
        private WorkflowEngine $workflow,
        private DomainEventPublisher $events,
    ) {}

    public function handle(AdvanceWorkflowStepCommand $command): void
    {
        $shipment = $this->repository->get($command->shipmentId);

        // Un dossier ouvert sans cotation attend la direction. Sans ce blocage,
        // « en attente » ne serait qu'une étiquette et le dossier suivrait son
        // cours comme si l'accord avait été donné.
        $waiver = DB::table('shipments')->where('id', $command->shipmentId)->value('quote_waiver_status');
        if ($waiver === 'pending' || $waiver === 'rejected') {
            throw QuoteWaiverPending::make($waiver);
        }

        // Condition d'entrée : documents requis présents et non "missing".
        $required = $this->workflow->requiredDocuments($shipment->workflowDefinitionId, $command->nextStep);
        if ($required !== []) {
            $present = DocumentModel::where('shipment_id', $shipment->id)
                ->whereIn('type', $required)
                ->where('status', '<>', 'missing')
                ->pluck('type')->all();
            $missing = array_values(array_diff($required, $present));
            if ($missing !== []) {
                throw MissingRequiredDocuments::ofTypes($missing);
            }
        }

        $shipment->advanceTo(
            $command->nextStep,
            $this->workflow->allowedTransitions($shipment->workflowDefinitionId, $shipment->status()),
            $command->automatic,
        );

        $this->repository->save($shipment);
        $this->events->publishFrom($shipment);
    }
}
