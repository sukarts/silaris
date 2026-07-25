<?php

declare(strict_types=1);

namespace Silaris\Modules\Shipment\Application\Query\GetShipmentTimeline;

use Illuminate\Support\Collection;
use Silaris\Modules\Shipment\Infrastructure\Persistence\Model\ShipmentEventModel;

final readonly class GetShipmentTimelineHandler
{
    public function handle(GetShipmentTimelineQuery $query): Collection
    {
        return ShipmentEventModel::query()
            ->where('shipment_id', $query->shipmentId)
            ->when($query->clientVisibleOnly, fn ($q) => $q->whereIn('type', ['status_change', 'tracking']))
            ->orderByDesc('occurred_at')
            ->get(['id', 'type', 'title', 'payload', 'source', 'actor_id', 'occurred_at']);
    }
}
