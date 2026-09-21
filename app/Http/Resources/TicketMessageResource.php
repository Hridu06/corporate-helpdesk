<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\TicketMessage */
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
            'created_at' => $this->created_at,
        ];
    }
}
