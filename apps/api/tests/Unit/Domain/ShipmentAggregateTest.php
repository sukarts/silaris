<?php

declare(strict_types=1);

use Silaris\Modules\Shipment\Domain\Event\DelayDetected;
use Silaris\Modules\Shipment\Domain\Event\ShipmentClosed;
use Silaris\Modules\Shipment\Domain\Event\ShipmentCreated;
use Silaris\Modules\Shipment\Domain\Event\WorkflowStepAdvanced;
use Silaris\Modules\Shipment\Domain\Exception\InvalidWorkflowTransition;
use Silaris\Modules\Shipment\Domain\Exception\ShipmentCannotBeClosed;
use Silaris\Modules\Shipment\Domain\Model\Enum\Direction;
use Silaris\Modules\Shipment\Domain\Model\Enum\Priority;
use Silaris\Modules\Shipment\Domain\Model\Enum\TransportMode;
use Silaris\Modules\Shipment\Domain\Model\Shipment;
use Silaris\Modules\Shipment\Domain\ValueObject\Schedule;

function makeShipment(?Schedule $schedule = null): Shipment
{
    return Shipment::create(
        'id-1', 'tenant-1', 'TAL-2026-99999', 'client-1', 'wf-1',
        Direction::Import, TransportMode::SeaFcl, 'creation',
        $schedule ?? new Schedule(eta: new DateTimeImmutable('2026-08-10')),
    );
}

test('la création émet ShipmentCreated', function (): void {
    $events = makeShipment()->releaseEvents();
    expect($events)->toHaveCount(1)->and($events[0])->toBeInstanceOf(ShipmentCreated::class);
});

test('une transition autorisée avance le statut et émet un événement', function (): void {
    $shipment = makeShipment();
    $shipment->releaseEvents();

    $shipment->advanceTo('booking', ['booking']);

    expect($shipment->status())->toBe('booking')
        ->and($shipment->releaseEvents()[0])->toBeInstanceOf(WorkflowStepAdvanced::class);
});

test('une transition hors liste est rejetée', function (): void {
    makeShipment()->advanceTo('delivery', ['booking']);
})->throws(InvalidWorkflowTransition::class);

test('un dossier clôturé refuse toute transition', function (): void {
    $shipment = makeShipment();
    $shipment->close('user-1', []);
    $shipment->advanceTo('booking', ['booking']);
})->throws(InvalidWorkflowTransition::class);

test('le retard est détecté au-delà du seuil et pas en deçà', function (): void {
    $shipment = makeShipment();
    $shipment->releaseEvents();

    // +12 h : sous le seuil de 24 h → aucun événement
    $shipment->updateEta(new DateTimeImmutable('2026-08-10 12:00'), 24);
    expect($shipment->releaseEvents())->toHaveCount(0);

    // +3 j : au-delà → DelayDetected avec la dérive TOTALE vs ETA initiale
    $shipment->updateEta(new DateTimeImmutable('2026-08-13'), 24);
    $events = $shipment->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(DelayDetected::class)
        ->and($events[0]->delayHours)->toBe(72);
});

test('la clôture échoue si des conditions restent insatisfaites', function (): void {
    makeShipment()->close('user-1', ['invoice_issued']);
})->throws(ShipmentCannotBeClosed::class);

test('la clôture réussie émet ShipmentClosed et est idempotente', function (): void {
    $shipment = makeShipment();
    $shipment->releaseEvents();

    $shipment->close('user-1', []);
    expect($shipment->isClosed())->toBeTrue()
        ->and($shipment->releaseEvents()[0])->toBeInstanceOf(ShipmentClosed::class);

    $shipment->close('user-1', []); // seconde clôture : silencieuse, aucun nouvel événement
    expect($shipment->releaseEvents())->toHaveCount(0);
});

test('la reconstitution depuis la persistance n émet aucun événement', function (): void {
    $shipment = Shipment::reconstitute(
        'id-1', 'tenant-1', 'REF', 'client-1', 'wf-1',
        Direction::Export, TransportMode::Air, 'transit',
        Priority::High,
        new Schedule, null,
    );
    expect($shipment->releaseEvents())->toHaveCount(0);
});
