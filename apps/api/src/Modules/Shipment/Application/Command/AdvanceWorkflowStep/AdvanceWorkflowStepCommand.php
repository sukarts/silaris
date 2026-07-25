<?php

declare(strict_types=1);

namespace Silaris\Modules\Shipment\Application\Command\AdvanceWorkflowStep;

final readonly class AdvanceWorkflowStepCommand
{
    public function __construct(
        public string $shipmentId,
        public string $nextStep,
        public bool $automatic = false,
    ) {}
}
