<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Http\Resources\TicketEventResource;
use App\Http\Resources\TicketResource;
use App\Models\Ticket;
use App\Models\User;
use App\Support\TicketWorkflow;

trait BuildsTicketDetail
{
    /**
     * The ticket with what the current user may do to it, and its history (oldest
     * first). Messages are deliberately not part of this payload: they are read only
     * through the visibility-filtered messages endpoint.
     */
    private function detail(User $actor, Ticket $ticket): array
    {
        $ticket->load(['department:id,name', 'customer:id,name,email', 'assignee:id,name']);

        $events = $ticket->events()->with('actor:id,name')->latest('id')->limit(100)->get()->reverse()->values();

        return [
            'ticket' => new TicketResource($ticket),
            'abilities' => TicketWorkflow::abilities($actor, $ticket),
            'events' => TicketEventResource::collection($events),
        ];
    }
}
