<?php

declare(strict_types=1);

namespace Silaris\Modules\Shipment\Interface\Http\Resource;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Ligne de la vue v_shipments_list. */
class ShipmentListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'direction' => $this->direction,
            'mode' => $this->mode,
            'status' => $this->status,
            'priority' => $this->priority,
            'client' => ['id' => $this->client_id, 'name' => $this->client_name],
            'agent' => ['id' => $this->agent_id, 'name' => $this->agent_name],
            'branch_code' => $this->branch_code,
            'origin_locode' => $this->origin_locode,
            'destination_locode' => $this->destination_locode,
            'etd' => $this->etd,
            'eta' => $this->eta,
            'atd' => $this->atd,
            'ata' => $this->ata,
            'is_delayed' => (bool) $this->is_delayed,
            'missing_documents' => (int) $this->missing_documents,
            'active_containers' => (int) $this->active_containers,
            'open_tasks' => (int) $this->open_tasks,
            'closed_at' => $this->closed_at,
        ];
    }
}
