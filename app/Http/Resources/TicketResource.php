<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Ticket */
class TicketResource extends JsonResource
{
    /** `description` is only present when the row was loaded with it (the list omits it). */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'subject' => $this->subject,
            'description' => $this->whenHas('description'),
            'status' => $this->status,
            'priority' => $this->priority,
            'department' => $this->relationLoaded('department') && $this->department
                ? ['id' => $this->department->id, 'name' => $this->department->name]
                : null,
            'customer' => $this->relationLoaded('customer') ? [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
                'email' => $this->customer->email,
            ] : null,
            'assignee' => $this->relationLoaded('assignee') && $this->assignee
                ? ['id' => $this->assignee->id, 'name' => $this->assignee->name]
                : null,
            // Files attached when the ticket was opened (only when loaded, i.e. on the detail views).
            'attachments' => TicketAttachmentResource::collection($this->whenLoaded('openingAttachments')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'resolved_at' => $this->resolved_at,
            'closed_at' => $this->closed_at,
        ];
    }
}
