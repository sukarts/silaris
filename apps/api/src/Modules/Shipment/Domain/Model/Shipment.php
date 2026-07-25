<?php

declare(strict_types=1);

namespace Silaris\Modules\Shipment\Domain\Model;

use DateTimeImmutable;
use Silaris\Modules\Shared\Domain\AggregateRoot;
use Silaris\Modules\Shipment\Domain\Event\DelayDetected;
use Silaris\Modules\Shipment\Domain\Event\ShipmentClosed;
use Silaris\Modules\Shipment\Domain\Event\ShipmentCreated;
use Silaris\Modules\Shipment\Domain\Event\WorkflowStepAdvanced;
use Silaris\Modules\Shipment\Domain\Exception\InvalidWorkflowTransition;
use Silaris\Modules\Shipment\Domain\Exception\ShipmentCannotBeClosed;
use Silaris\Modules\Shipment\Domain\Model\Enum\Direction;
use Silaris\Modules\Shipment\Domain\Model\Enum\Priority;
use Silaris\Modules\Shipment\Domain\Model\Enum\TransportMode;
use Silaris\Modules\Shipment\Domain\ValueObject\Schedule;

/**
 * Agrégat Dossier de transit — porte les invariants métier :
 * transitions de workflow, détection de retard, clôture contrôlée.
 *
 * PHP pur : aucune dépendance framework. La persistance passe par
 * ShipmentRepository (port) et un mapper Eloquent (Infrastructure).
 */
final class Shipment extends AggregateRoot
{
    private function __construct(
        public readonly string $id,
        public readonly string $tenantId,
        public readonly string $reference,
        public readonly string $clientId,
        public readonly string $workflowDefinitionId,
        public readonly Direction $direction,
        public readonly TransportMode $mode,
        private string $status,
        private Priority $priority,
        private Schedule $schedule,
        private ?DateTimeImmutable $closedAt = null,
    ) {}

    public static function create(
        string $id,
        string $tenantId,
        string $reference,
        string $clientId,
        string $workflowDefinitionId,
        Direction $direction,
        TransportMode $mode,
        string $initialStep,
        Schedule $schedule,
        Priority $priority = Priority::Normal,
    ): self {
        $shipment = new self(
            $id, $tenantId, $reference, $clientId, $workflowDefinitionId,
            $direction, $mode, $initialStep, $priority, $schedule,
        );
        $shipment->record(new ShipmentCreated($id, $reference, $clientId, new DateTimeImmutable));

        return $shipment;
    }

    /** Reconstitution depuis la persistance — n'émet aucun événement. */
    public static function reconstitute(
        string $id,
        string $tenantId,
        string $reference,
        string $clientId,
        string $workflowDefinitionId,
        Direction $direction,
        TransportMode $mode,
        string $status,
        Priority $priority,
        Schedule $schedule,
        ?DateTimeImmutable $closedAt,
    ): self {
        return new self(
            $id, $tenantId, $reference, $clientId, $workflowDefinitionId,
            $direction, $mode, $status, $priority, $schedule, $closedAt,
        );
    }

    /**
     * Avance vers l'étape suivante. Les transitions autorisées viennent du
     * workflow configuré du tenant (résolues par le WorkflowEngine, Application layer).
     *
     * @param  list<string>  $allowedTransitions  Étapes atteignables depuis l'étape courante.
     */
    public function advanceTo(string $nextStep, array $allowedTransitions, bool $automatic = false): void
    {
        if ($this->isClosed()) {
            throw InvalidWorkflowTransition::between($this->status, $nextStep);
        }
        if (! in_array($nextStep, $allowedTransitions, true)) {
            throw InvalidWorkflowTransition::between($this->status, $nextStep);
        }

        $from = $this->status;
        $this->status = $nextStep;
        $this->record(new WorkflowStepAdvanced($this->id, $from, $nextStep, $automatic, new DateTimeImmutable));
    }

    /**
     * Applique une nouvelle ETA (tracking). Émet DelayDetected si la dérive
     * dépasse le seuil du tenant.
     */
    public function updateEta(DateTimeImmutable $newEta, int $delayThresholdHours): void
    {
        $this->schedule = $this->schedule->withNewEta($newEta);

        $delay = $this->schedule->delayHours();
        if ($delay >= $delayThresholdHours) {
            $this->record(new DelayDetected($this->id, $delay, $newEta, new DateTimeImmutable));
        }
    }

    public function confirmDeparture(DateTimeImmutable $atd): void
    {
        $this->schedule = $this->schedule->withActualDeparture($atd);
    }

    public function confirmArrival(DateTimeImmutable $ata): void
    {
        $this->schedule = $this->schedule->withActualArrival($ata);
    }

    /**
     * Clôture contrôlée — les conditions viennent du workflow du tenant.
     *
     * @param  list<string>  $unmetConditions  Conditions non satisfaites (vide = clôturable).
     */
    public function close(string $closedBy, array $unmetConditions): void
    {
        if ($this->isClosed()) {
            return;
        }
        if ($unmetConditions !== []) {
            throw ShipmentCannotBeClosed::because($unmetConditions);
        }

        $this->closedAt = new DateTimeImmutable;
        $this->record(new ShipmentClosed($this->id, $closedBy, $this->closedAt));
    }

    public function changePriority(Priority $priority): void
    {
        $this->priority = $priority;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function priority(): Priority
    {
        return $this->priority;
    }

    public function schedule(): Schedule
    {
        return $this->schedule;
    }

    public function closedAt(): ?DateTimeImmutable
    {
        return $this->closedAt;
    }

    public function isClosed(): bool
    {
        return $this->closedAt !== null;
    }
}
