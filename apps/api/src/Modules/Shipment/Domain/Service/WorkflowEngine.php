<?php

declare(strict_types=1);

namespace Silaris\Modules\Shipment\Domain\Service;

use RuntimeException;

/**
 * Moteur de workflow — interprète la configuration du tenant.
 * L'agrégat Shipment valide les transitions ; le moteur les fournit.
 */
final readonly class WorkflowEngine
{
    public function __construct(private WorkflowDefinitionProvider $provider) {}

    public function initialStep(string $definitionId): string
    {
        $steps = $this->provider->stepsOf($definitionId);
        if ($steps === []) {
            throw new RuntimeException("Workflow {$definitionId} sans étapes");
        }

        return array_key_first($steps);
    }

    /** @return list<string> */
    public function allowedTransitions(string $definitionId, string $currentStep): array
    {
        return $this->provider->stepsOf($definitionId)[$currentStep]->transitions ?? [];
    }

    /** @return list<string> Documents requis pour ENTRER dans une étape. */
    public function requiredDocuments(string $definitionId, string $step): array
    {
        return $this->provider->stepsOf($definitionId)[$step]->conditions['required_documents'] ?? [];
    }

    /**
     * Conditions de clôture non satisfaites.
     *
     * @param  array<string, bool>  $facts  Faits établis par l'Application layer
     *                                      (ex. ['delivery_confirmed' => true, 'invoice_issued' => false]).
     * @return list<string>
     */
    public function unmetCloseConditions(string $definitionId, array $facts): array
    {
        $steps = $this->provider->stepsOf($definitionId);
        $closure = $steps[array_key_last($steps)] ?? null;
        $required = $closure->conditions['requires'] ?? [];

        return array_values(array_filter($required, fn (string $fact) => ($facts[$fact] ?? false) !== true));
    }
}
