<?php

declare(strict_types=1);

namespace Silaris\Modules\Shipment\Application\Command\CreateShipment;

use DateTimeImmutable;
use Illuminate\Support\Str;
use Silaris\Modules\Shared\Infrastructure\Events\DomainEventPublisher;
use Silaris\Modules\Shared\Infrastructure\Tenancy\TenantContext;
use Silaris\Modules\Shipment\Application\Port\ReferenceGenerator;
use Silaris\Modules\Shipment\Application\Service\AcceptedQuoteGuard;
use Silaris\Modules\Shipment\Domain\Model\Enum\Direction;
use Silaris\Modules\Shipment\Domain\Model\Enum\Priority;
use Silaris\Modules\Shipment\Domain\Model\Enum\TransportMode;
use Silaris\Modules\Shipment\Domain\Model\Shipment;
use Silaris\Modules\Shipment\Domain\Service\WorkflowDefinitionProvider;
use Silaris\Modules\Shipment\Domain\Service\WorkflowEngine;
use Silaris\Modules\Shipment\Domain\ValueObject\Schedule;
use Silaris\Modules\Shipment\Infrastructure\Persistence\EloquentShipmentRepository;

final readonly class CreateShipmentHandler
{
    public function __construct(
        private EloquentShipmentRepository $repository,
        private ReferenceGenerator $references,
        private WorkflowEngine $workflow,
        private WorkflowDefinitionProvider $workflows,
        private DomainEventPublisher $events,
        private TenantContext $tenant,
        private AcceptedQuoteGuard $acceptedQuote,
    ) {}

    /** @return string ID du dossier créé. */
    public function handle(CreateShipmentCommand $command): string
    {
        // Les conditions viennent de la cotation acceptée, pas de la requête :
        // le dossier ne peut pas diverger de ce que le client a validé.
        $terms = $this->acceptedQuote->termsOf($command->quoteId, $command->clientId);

        $workflowId = $command->workflowDefinitionId
            ?? $this->workflows->resolveDefaultId($terms['mode'], $terms['direction']);

        $eta = $command->eta ? new DateTimeImmutable($command->eta) : null;

        $shipment = Shipment::create(
            id: (string) Str::uuid7(),
            tenantId: $this->tenant->id(),
            reference: $this->references->nextShipmentReference($command->branchId, $terms['direction']),
            clientId: $command->clientId,
            workflowDefinitionId: $workflowId,
            direction: Direction::from($terms['direction']),
            mode: TransportMode::from($terms['mode']),
            initialStep: $this->workflow->initialStep($workflowId),
            schedule: new Schedule(
                etd: $command->etd ? new DateTimeImmutable($command->etd) : null,
                eta: $eta,
                etaInitial: $eta,
            ),
            priority: Priority::from($command->priority),
        );

        $this->repository->save($shipment, [
            'branch_id' => $command->branchId,
            'company_id' => $command->companyId,
            'agent_id' => $command->agentId,
            'supervisor_id' => $command->supervisorId,
            'incoterm_code' => $terms['incoterm_code'],
            'origin_locode' => $terms['origin_locode'],
            'destination_locode' => $terms['destination_locode'],
            'quote_id' => $command->quoteId,
            // Le dossier démarre avec le chiffre d'affaires convenu, ce qui rend
            // la marge lisible dès l'ouverture.
            'currency_code' => $terms['currency_code'],
            'estimated_revenue' => $terms['total_amount'],
            'notes' => $command->notes,
        ]);

        $this->events->publishFrom($shipment);

        return $shipment->id;
    }
}
