<?php

namespace App\Support\Notifications;

use App\Enums\TicketActivity;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TicketActivityNotification;
use App\Support\Mailing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * The one way ticket code asks for an email to be sent.
 *
 *  - Off switch: `TICKET_NOTIFICATIONS_ENABLED=false` sends nothing.
 *  - Nothing goes out until the surrounding database transaction has committed (and never if
 *    it rolls back), whatever the queue connection is, `sync` included.
 *  - It can never break the ticket operation: any error while choosing recipients or handing
 *    the emails to the queue is reported (logged) and swallowed.
 */
final class TicketNotifier
{
    public static function send(TicketActivity $activity, Ticket $ticket, User $actor): void
    {
        if (! config('helpdesk.ticket_notifications')) {
            return;
        }

        Mailing::attempt(function () use ($activity, $ticket, $actor) {
            $recipients = TicketRecipients::for($activity, $ticket, $actor);

            if ($recipients->isEmpty()) {
                return;
            }

            $ticketId = $ticket->id;

            DB::afterCommit(fn () => Mailing::attempt(
                fn () => Notification::send($recipients, new TicketActivityNotification($ticketId, $activity)),
            ));
        });
    }
}
