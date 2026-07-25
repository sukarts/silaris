<?php

declare(strict_types=1);

namespace Silaris\Modules\Shipment\Infrastructure\Provider;

use Illuminate\Support\ServiceProvider;
use Silaris\Modules\Shipment\Application\Port\ReferenceGenerator;
use Silaris\Modules\Shipment\Domain\Repository\ShipmentRepository;
use Silaris\Modules\Shipment\Domain\Service\WorkflowDefinitionProvider;
use Silaris\Modules\Shipment\Infrastructure\Persistence\EloquentShipmentRepository;
use Silaris\Modules\Shipment\Infrastructure\Persistence\SequenceReferenceGenerator;
use Silaris\Modules\Shipment\Infrastructure\Workflow\EloquentWorkflowDefinitionProvider;

class ShipmentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ShipmentRepository::class, EloquentShipmentRepository::class);
        $this->app->bind(ReferenceGenerator::class, SequenceReferenceGenerator::class);
        $this->app->bind(WorkflowDefinitionProvider::class, EloquentWorkflowDefinitionProvider::class);
    }
}
