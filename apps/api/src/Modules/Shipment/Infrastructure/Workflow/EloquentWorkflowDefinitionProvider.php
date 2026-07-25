<?php

declare(strict_types=1);

namespace Silaris\Modules\Shipment\Infrastructure\Workflow;

use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Silaris\Modules\Shared\Infrastructure\Tenancy\TenantContext;
use Silaris\Modules\Shipment\Domain\Service\WorkflowDefinitionProvider;
use Silaris\Modules\Shipment\Domain\Service\WorkflowStepDefinition;
use Silaris\Modules\Shipment\Infrastructure\Persistence\Model\WorkflowDefinitionModel;

final readonly class EloquentWorkflowDefinitionProvider implements WorkflowDefinitionProvider
{
    public function __construct(private TenantContext $tenant) {}

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
            throw new RuntimeException("Aucun workflow actif pour {$mode}/{$direction} (tenant {$this->tenant->id()})");
        }

        return $id;
    }
}
