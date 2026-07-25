<?php

declare(strict_types=1);

namespace Silaris\Modules\Shipment\Interface\Http\Resource;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TimelineEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'title' => $this->title,
            'payload' => $this->payload,
            'source' => $this->source,
            'actor_id' => $this->actor_id,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
        ];
    }
}
