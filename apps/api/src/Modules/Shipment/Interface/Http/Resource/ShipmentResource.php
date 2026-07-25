<?php

declare(strict_types=1);

namespace Silaris\Modules\Shipment\Interface\Http\Resource;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Détail d'un dossier (ShipmentModel + relations chargées). */
class ShipmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'direction' => $this->direction->value,
            'mode' => $this->mode->value,
            'status' => $this->status,
            'priority' => $this->priority->value,
            'incoterm_code' => $this->incoterm_code,
            'origin_locode' => $this->origin_locode,
            'destination_locode' => $this->destination_locode,
            'schedule' => [
                'etd' => $this->etd?->toIso8601String(),
                'eta' => $this->eta?->toIso8601String(),
                'atd' => $this->atd?->toIso8601String(),
                'ata' => $this->ata?->toIso8601String(),
                'eta_initial' => $this->eta_initial?->toIso8601String(),
            ],
            'client' => $this->whenLoaded('client', fn () => [
                'id' => $this->client->id, 'code' => $this->client->code, 'name' => $this->client->name,
            ]),
            'agent' => $this->whenLoaded('agent', fn () => [
                'id' => $this->agent->id, 'name' => $this->agent->fullName(),
            ]),
            'branch' => $this->whenLoaded('branch', fn () => [
                'id' => $this->branch->id, 'code' => $this->branch->code, 'name' => $this->branch->name,
            ]),
            'segments' => $this->whenLoaded('segments', fn () => $this->segments->map(fn ($segment) => [
                'id' => $segment->id,
                'position' => $segment->position,
                'mode' => $segment->mode,
                'origin_locode' => $segment->origin_locode,
                'destination_locode' => $segment->destination_locode,
                'etd' => $segment->etd?->toIso8601String(),
                'eta' => $segment->eta?->toIso8601String(),
                'atd' => $segment->atd?->toIso8601String(),
                'ata' => $segment->ata?->toIso8601String(),
            ])),
            'cargo_items' => $this->whenLoaded('cargoItems', fn () => $this->cargoItems->map(fn ($item) => [
                'id' => $item->id,
                'description' => $item->description,
                'packages_count' => $item->packages_count,
                'gross_weight_kg' => $item->gross_weight_kg,
                'volume_m3' => $item->volume_m3,
                'hs_code' => $item->hs_code,
            ])),
            'estimated_cost' => $this->estimated_cost,
            'estimated_revenue' => $this->estimated_revenue,
            'currency_code' => $this->currency_code,
            'quote_id' => $this->quote_id,
            'notes' => $this->notes,
            'closed_at' => $this->closed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
