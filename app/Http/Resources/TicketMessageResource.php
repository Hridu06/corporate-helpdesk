<?php

namespace App\Http\Resources;

use App\Models\TicketMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TicketMessage */
class TicketMessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'body' => $this->body,
            'author' => $this->relationLoaded('author') && $this->author
                ? [
                    'id' => $this->author->id,
                    'name' => $this->author->name,
                    'role' => $this->author->relationLoaded('roles') ? $this->author->roles->first()?->name : null,
                ]
                : null,
            // Already limited to the viewer's messages, so a note's files never reach a customer.
            'attachments' => TicketAttachmentResource::collection($this->whenLoaded('attachments')),
            'created_at' => $this->created_at,
        ];
    }
}
