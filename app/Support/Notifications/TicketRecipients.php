<?php

namespace App\Support\Notifications;

use App\Enums\TicketActivity;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;

/**
 * The single place that decides who is emailed about a ticket event. It only decides;
 * nothing is sent from here.
 *
 * Two layers, both applied when choosing recipients and again just before a queued
 * email is sent (mayReceive), so a change made while the email waited in the queue counts:
 *  - who is a candidate for the event, and
 *  - whether that person may receive this kind of email at all. Emails for staff need the
 *    right to view the ticket, and internal-note emails additionally need
 *    `ticket.internal_note` and can never reach the ticket's customer.
 */
final class TicketRecipients
{
    /** @return Collection<int, User> */
    public static function for(TicketActivity $activity, Ticket $ticket, User $actor): Collection
    {
        $ticket->loadMissing(['customer', 'assignee']);

        $candidates = match ($activity) {
            TicketActivity::Opened,
            TicketActivity::StaffReply,
            TicketActivity::Resolved,
            TicketActivity::Closed,
            TicketActivity::Reopened => collect([$ticket->customer]),

            // Tickets that name a department are for that department's people, not for every admin.
            TicketActivity::NewForStaff => $ticket->department_id === null ? self::assigners() : collect(),

            TicketActivity::Assigned,
            TicketActivity::InternalNote,
            TicketActivity::CustomerClosed,
            TicketActivity::CustomerReopened => collect([$ticket->assignee]),

            TicketActivity::CustomerReply => $ticket->assignee ? collect([$ticket->assignee]) : self::assigners(),
        };

        return $candidates
            ->filter()
            ->filter(fn (User $user) => self::mayReceive($activity, $ticket, $user))
            // You are not emailed about what you just did. The one exception is the customer's
            // own "we received your ticket" confirmation, where the actor is the recipient.
            ->reject(fn (User $user) => $activity !== TicketActivity::Opened && $user->is($actor))
            ->unique('id')
            ->values();
    }

    /** May this person be emailed about this kind of event on this ticket right now? */
    public static function mayReceive(TicketActivity $activity, Ticket $ticket, User $user): bool
    {
        if (! $user->isActive() || ! $user->hasVerifiedEmail() || blank($user->email)) {
            return false;
        }

        if ($activity->audience() === 'customer') {
            return $user->id === $ticket->customer_id;
        }

        // Staff emails (internal notes, assignments, the customer's own replies...) are closed to
        // the ticket's customer, whatever permissions they happen to hold.
        if ($user->id === $ticket->customer_id || ! Gate::forUser($user)->allows('view', $ticket)) {
            return false;
        }

        if ($activity === TicketActivity::InternalNote && ! $user->can('ticket.internal_note')) {
            return false;
        }

        return true;
    }

    /**
     * People holding `ticket.assign` through a role or directly. Super Admins hold every
     * ability implicitly rather than as stored permissions, so they are not in this list.
     *
     * @return Collection<int, User>
     */
    private static function assigners(): Collection
    {
        if (! Permission::query()->where('name', 'ticket.assign')->where('guard_name', 'web')->exists()) {
            return collect();
        }

        return User::permission('ticket.assign')->get();
    }
}
