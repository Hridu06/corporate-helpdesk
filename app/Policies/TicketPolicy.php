<?php

namespace App\Policies;

use App\Models\Ticket;
use App\Models\User;

/**
 * A ticket is visible to whoever may see them all (`ticket.view_all`), to the
 * customer who opened it, and to the agent it is assigned to. Nobody else,
 * regardless of department.
 */
class TicketPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('ticket.view');
    }

    public function view(User $actor, Ticket $ticket): bool
    {
        return $actor->can('ticket.view')
            && ($actor->can('ticket.view_all') || $ticket->customer_id === $actor->id || $ticket->assigned_to === $actor->id);
    }

    public function create(User $actor): bool
    {
        return $actor->can('ticket.create');
    }
}
