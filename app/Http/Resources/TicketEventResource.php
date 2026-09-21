<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\TicketEvent */
class TicketEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'from' => $this->data['from'] ?? null,
            'to' => $this->data['to'] ?? null,
            // Set when the change was a side effect of something else, e.g. 'customer_reply'.
            'reason' => $this->data['reason'] ?? null,
            'assignee_name' => $this->data['assignee_name'] ?? null,
            'actor' => $this->relationLoaded('actor') && $this->actor
                ? ['id' => $this->actor->id, 'name' => $this->actor->name]
                : null,
            'created_at' => $this->created_at,
        ];
    }
}
