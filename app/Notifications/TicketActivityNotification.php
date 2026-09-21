<?php

namespace App\Notifications;

use App\Enums\TicketActivity;
use App\Models\Ticket;
use App\Models\User;
use App\Support\Notifications\TicketRecipients;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;
use Throwable;

/**
 * One short email about something that happened on a ticket. It carries only the ticket
 * id and the kind of event, and reads everything else fresh when it is sent. It never
 * contains message text or attachments: the reader signs in to see those.
 */
class TicketActivityNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $ticketId, public readonly TicketActivity $activity)
    {
        // Wait for the surrounding database transaction (if any) to commit before queueing.
        $this->afterCommit();
    }

    /** @return list<int> seconds to wait before each retry */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Checked when the email is about to go out, which can be long after the event:
     * the ticket may be gone, or the person's account or rights may have changed.
     */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        $ticket = Ticket::query()->find($this->ticketId);

        return $ticket !== null
            && $notifiable instanceof User
            && TicketRecipients::mayReceive($this->activity, $ticket, $notifiable);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $ticket = Ticket::query()->findOrFail($this->ticketId);

        return (new MailMessage)
            ->subject(self::subjectFor($this->activity, $ticket))
            ->markdown('mail.tickets.activity', [
                'heading' => $this->activity->label(),
                'line' => $this->activity->line(),
                'number' => $ticket->number,
                'ticketSubject' => self::escapeMarkdown(self::plain($ticket->subject)),
                'status' => Str::of($ticket->status->value)->replace('_', ' ')->ucfirst()->toString(),
                'url' => config('helpdesk.frontend_url').'/tickets/'.$ticket->id,
            ]);
    }

    public function failed(Throwable $e): void
    {
        report($e);
    }

    /** `[TCK-000123] New reply: <subject>`, on one line and of bounded length. */
    public static function subjectFor(TicketActivity $activity, Ticket $ticket): string
    {
        return "[{$ticket->number}] {$activity->label()}: ".Str::limit(self::plain($ticket->subject), 120, '…');
    }

    /** Text with control and invisible formatting characters (line breaks included) replaced by spaces. */
    private static function plain(string $text): string
    {
        return trim((string) preg_replace('/[\p{Cc}\p{Cf}\x{2028}\x{2029}]+/u', ' ', $text));
    }

    /**
     * The ticket subject is written by a customer and lands inside a Markdown email, where
     * `[text](https://evil)` would become a clickable link. Escaping every punctuation
     * character keeps it as plain text (and stops automatic links).
     */
    private static function escapeMarkdown(string $text): string
    {
        return (string) preg_replace('/([\\\\`*_{}\[\]()#+\-.!|<>~&:@\/=])/u', '\\\\$1', $text);
    }
}
