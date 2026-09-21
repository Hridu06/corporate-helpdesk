<?php

namespace App\Enums;

/** The ticket events that can send an email, and how each one reads. */
enum TicketActivity: string
{
    case Opened = 'opened';
    case NewForStaff = 'new_for_staff';
    case Assigned = 'assigned';
    case StaffReply = 'staff_reply';
    case CustomerReply = 'customer_reply';
    /** The customer answered a resolved ticket, which reopened it: one email, not a reply email plus a reopen email. */
    case CustomerReplyReopened = 'customer_reply_reopened';
    case InternalNote = 'internal_note';
    case Resolved = 'resolved';
    case Closed = 'closed';
    case Reopened = 'reopened';
    case CustomerClosed = 'customer_closed';
    case CustomerReopened = 'customer_reopened';

    /** Who the email is written for: the ticket's customer, or staff. */
    public function audience(): string
    {
        return match ($this) {
            self::Opened, self::StaffReply, self::Resolved, self::Closed, self::Reopened => 'customer',
            default => 'staff',
        };
    }

    /** Short label used in the email subject. */
    public function label(): string
    {
        return match ($this) {
            self::Opened => 'Ticket received',
            self::NewForStaff => 'New ticket',
            self::Assigned => 'Assigned to you',
            self::StaffReply => 'New reply',
            self::CustomerReply => 'Customer replied',
            self::CustomerReplyReopened => 'Customer replied and reopened',
            self::InternalNote => 'New internal note',
            self::Resolved => 'Resolved',
            self::Closed => 'Closed',
            self::Reopened => 'Reopened',
            self::CustomerClosed => 'Closed by customer',
            self::CustomerReopened => 'Reopened by customer',
        };
    }

    /** The one sentence in the email body. Deliberately never contains message text. */
    public function line(): string
    {
        return match ($this) {
            self::Opened => 'We received your ticket and our team will look at it.',
            self::NewForStaff => 'A new ticket has been opened and is not yet in a department.',
            self::Assigned => 'This ticket was assigned to you.',
            self::StaffReply => 'There is a new reply on your ticket.',
            self::CustomerReply => 'The customer replied to this ticket.',
            self::CustomerReplyReopened => 'The customer replied and reopened this ticket.',
            self::InternalNote => 'A staff-only note was added to this ticket.',
            self::Resolved => 'Your ticket was marked resolved.',
            self::Closed => 'Your ticket was closed.',
            self::Reopened => 'Your ticket was reopened.',
            self::CustomerClosed => 'The customer closed this ticket.',
            self::CustomerReopened => 'The customer reopened this ticket.',
        };
    }
}
