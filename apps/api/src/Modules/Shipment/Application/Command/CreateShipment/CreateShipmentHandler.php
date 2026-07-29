<?php

declare(strict_types=1);

namespace Silaris\Modules\Shipment\Application\Command\CreateShipment;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
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
        // Deux voies : la cotation acceptée, qui porte alors les conditions, ou
        // l'ouverture dérogatoire, où l'exploitant les déclare et où le dossier
        // attend la décision de la direction.
        $waived = $command->quoteId === null;
        $terms = $waived
            ? $this->declaredTerms($command)
            : $this->acceptedQuote->termsOf($command->quoteId, $command->clientId);

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
            // Le dossier relève du service de l'agent qui l'ouvre : c'est ce
            // service qui désignera le chef compétent pour valider ses étapes.
            'service_id' => DB::table('users')->where('id', $command->agentId)->value('service_id'),
            'incoterm_code' => $terms['incoterm_code'],
            'origin_locode' => $terms['origin_locode'],
            'destination_locode' => $terms['destination_locode'],
            'quote_id' => $command->quoteId,
            // Le dossier démarre avec le chiffre d'affaires convenu, ce qui rend
            // la marge lisible dès l'ouverture.
            'currency_code' => $terms['currency_code'],
            'estimated_revenue' => $terms['total_amount'],
            'notes' => $command->notes,
            ...($waived ? [
                'quote_waiver_status' => 'pending',
                'quote_waiver_reason' => $command->waiverReason,
                'quote_waiver_requested_by' => $command->agentId,
                'quote_waiver_requested_at' => now(),
            ] : []),
        ]);

        if ($waived) {
            $this->traceWaiverRequest($shipment->id, $command);
        }

        $this->events->publishFrom($shipment);

        return $shipment->id;
    }

    /**
     * Conditions déclarées par l'exploitant, faute de cotation. Elles restent
     * les siennes tant que la direction n'a pas tranché.
     *
     * @return array{mode: string, direction: string, incoterm_code: string, origin_locode: string, destination_locode: string, currency_code: string, total_amount: string}
     */
    private function declaredTerms(CreateShipmentCommand $command): array
    {
        return [
            'mode' => $command->mode,
            'direction' => $command->direction,
            'incoterm_code' => $command->incotermCode,
            'origin_locode' => $command->originLocode,
            'destination_locode' => $command->destinationLocode,
            'currency_code' => 'XOF',
            'total_amount' => '0',
        ];
    }

    /** Le motif s'inscrit à la timeline : la demande se lit sur le dossier. */
    private function traceWaiverRequest(string $shipmentId, CreateShipmentCommand $command): void
    {
        DB::table('shipment_events')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $this->tenant->id(),
            'shipment_id' => $shipmentId,
            'type' => 'system',
            'title' => 'Ouverture sans cotation — en attente de validation',
            'payload' => json_encode(['reason' => $command->waiverReason, 'requested_by' => $command->agentId]),
            'source' => 'system',
            'occurred_at' => now(),
        ]);
    }
}
