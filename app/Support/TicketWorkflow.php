<?php

namespace App\Support;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * The single source of truth for what a user may do to a ticket. The controller
 * enforces it and the API exposes the result as `abilities`, so the frontend
 * never has to duplicate these rules.
 */
final class TicketWorkflow
{
    /** Status changes staff (ticket.update_status) may make, by current status. */
    private const STAFF = [
        'open' => ['in_progress', 'resolved', 'closed'],
        'in_progress' => ['open', 'resolved', 'closed'],
        'resolved' => ['in_progress', 'closed'],
        'closed' => ['in_progress'], // reopen
    ];

    /** The customer who opened a ticket may only close it, or reopen it, once it is resolved. */
    private const CUSTOMER = [
        'resolved' => ['closed', 'open'],
    ];

    /** @return list<string> */
    public static function allowedStatuses(User $actor, Ticket $ticket): array
    {
        if (! Gate::forUser($actor)->allows('view', $ticket)) {
            return [];
        }

        $current = $ticket->status->value;

        if ($actor->can('ticket.update_status')) {
            return self::STAFF[$current] ?? [];
        }

        if ($ticket->customer_id === $actor->id && $actor->can('ticket.close')) {
            return self::CUSTOMER[$current] ?? [];
        }

        return [];
    }

    /** @return array{allowed_statuses: list<string>, can_change_status: bool, can_change_priority: bool, can_assign: bool} */
    public static function abilities(User $actor, Ticket $ticket): array
    {
        $allowed = self::allowedStatuses($actor, $ticket);
        $canView = Gate::forUser($actor)->allows('view', $ticket);
        // A closed ticket is read-only apart from being reopened.
        $editable = $canView && $ticket->status !== TicketStatus::Closed;

        return [
            'allowed_statuses' => $allowed,
            'can_change_status' => $allowed !== [],
            'can_change_priority' => $editable && $actor->can('ticket.update_priority'),
            'can_assign' => $editable && $actor->can('ticket.assign'),
        ];
    }
}
