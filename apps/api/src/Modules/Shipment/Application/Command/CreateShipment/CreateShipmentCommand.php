<?php

declare(strict_types=1);

namespace Silaris\Modules\Shipment\Application\Command\CreateShipment;

final readonly class CreateShipmentCommand
{
    public function __construct(
        public string $clientId,
        public string $branchId,
        public string $companyId,
        public string $agentId,
        public string $direction,
        public string $mode,
        public string $incotermCode,
        public string $originLocode,
        public string $destinationLocode,
        public string $priority = 'normal',
        public ?string $supervisorId = null,
        public ?string $etd = null,
        public ?string $eta = null,
        public ?string $quoteId = null,
        /** Motif invoqué quand aucune cotation n'appuie l'ouverture. */
        public ?string $waiverReason = null,
        public ?string $workflowDefinitionId = null,
        public ?string $notes = null,
    ) {}
}
