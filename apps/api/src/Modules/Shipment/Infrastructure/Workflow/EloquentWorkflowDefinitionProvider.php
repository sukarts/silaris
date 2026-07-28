<?php

declare(strict_types=1);

namespace Silaris\Modules\Shipment\Infrastructure\Workflow;

use Illuminate\Support\Facades\Cache;
use Silaris\Modules\Shared\Domain\Exception\DomainException;
use Silaris\Modules\Shipment\Domain\Service\WorkflowDefinitionProvider;
use Silaris\Modules\Shipment\Domain\Service\WorkflowStepDefinition;
use Silaris\Modules\Shipment\Infrastructure\Persistence\Model\WorkflowDefinitionModel;

/**
 * Un dossier ne peut pas naître sans workflow : plutôt qu'une erreur technique,
 * l'exploitant reçoit la marche à suivre.
 */
final class NoWorkflowAvailable extends DomainException
{
    public static function make(string $mode, string $direction): self
    {
        return new self(
            "Aucun workflow actif pour {$mode} / {$direction}. ".
            'Créez-en un dans Paramètres avant de saisir des dossiers.'
        );
    }

    public function errorCode(): string
    {
        return 'workflow.none_available';
    }
}

final readonly class EloquentWorkflowDefinitionProvider implements WorkflowDefinitionProvider
{
    public function stepsOf(string $workflowDefinitionId): array
    {
        return Cache::remember(
            "workflow_steps:{$workflowDefinitionId}",
            300,
            function () use ($workflowDefinitionId): array {
                $definition = WorkflowDefinitionModel::with('steps')->findOrFail($workflowDefinitionId);
                $steps = [];
                foreach ($definition->steps as $step) {
                    $steps[$step->key] = new WorkflowStepDefinition(
                        key: $step->key,
                        label: $step->label,
                        position: (int) $step->position,
                        transitions: $step->transitions ?? [],
                        conditions: $step->conditions ?? [],
                    );
                }

                return $steps;
            },
        );
    }

    public function resolveDefaultId(string $mode, string $direction): string
    {
        $id = WorkflowDefinitionModel::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->where('transport_mode', $mode)->orWhere('transport_mode', 'any'))
            ->where(fn ($q) => $q->where('direction', $direction)->orWhere('direction', 'any'))
            ->orderByRaw("(transport_mode <> 'any')::int + (direction <> 'any')::int DESC")
            ->orderByDesc('is_default')
            ->value('id');

        if ($id === null) {
            throw NoWorkflowAvailable::make($mode, $direction);
        }

        return $id;
    }
}
