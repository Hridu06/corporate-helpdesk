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

    /** Which transitions are allowed is decided by TicketWorkflow; this only gates the endpoint. */
    public function changeStatus(User $actor, Ticket $ticket): bool
    {
        return $this->view($actor, $ticket)
            && ($actor->can('ticket.update_status') || ($ticket->customer_id === $actor->id && $actor->can('ticket.close')));
    }

    public function changePriority(User $actor, Ticket $ticket): bool
    {
        return $this->view($actor, $ticket) && $actor->can('ticket.update_priority');
    }

    public function assign(User $actor, Ticket $ticket): bool
    {
        return $this->view($actor, $ticket) && $actor->can('ticket.assign');
    }

    /** Whether the ticket is closed is decided by the controller (422); this only gates the endpoint. */
    public function reply(User $actor, Ticket $ticket): bool
    {
        return $this->view($actor, $ticket) && $actor->can('ticket.reply');
    }

    public function addNote(User $actor, Ticket $ticket): bool
    {
        return $this->view($actor, $ticket) && $actor->can('ticket.internal_note');
    }
}
