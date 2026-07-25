<?php

declare(strict_types=1);

namespace Silaris\Modules\Shipment\Domain\Service;

/** Définition d'une étape du workflow tenant — DTO immuable. */
final readonly class WorkflowStepDefinition
{
    /**
     * @param  list<string>  $transitions
     * @param  array<string, mixed>  $conditions
     */
    public function __construct(
        public string $key,
        public string $label,
        public int $position,
        public array $transitions,
        public array $conditions,
    ) {}
}
