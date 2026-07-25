<?php

declare(strict_types=1);

namespace Silaris\Modules\Shipment\Domain\Service;

/** Port — fournit les étapes d'un workflow configuré (implémentation Infrastructure). */
interface WorkflowDefinitionProvider
{
    /** @return array<string, WorkflowStepDefinition> Indexé par clé d'étape, ordonné par position. */
    public function stepsOf(string $workflowDefinitionId): array;

    /** Workflow par défaut du tenant pour un mode/sens donné. */
    public function resolveDefaultId(string $mode, string $direction): string;
}
