<?php

namespace Tests\Feature\Notifications;

use App\Enums\RoleName;
use App\Enums\TicketActivity;
use App\Enums\TicketStatus;
use App\Enums\UserStatus;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketMessage;
use App\Models\User;
use App\Notifications\TicketActivityNotification;
use App\Support\Notifications\TicketNotifier;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class TicketNotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    private User $agent;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        config(['helpdesk.frontend_url' => 'https://help.example.org']);
        $this->customer = User::factory()->create()->assignRole(RoleName::Customer->value);
        $this->agent = User::factory()->create()->assignRole(RoleName::Agent->value);
        $this->admin = User::factory()->create()->assignRole(RoleName::Admin->value);
    }

    private function ticket(array $attributes = []): Ticket
    {
        return Ticket::factory()->create(array_merge([
            'customer_id' => $this->customer->id,
            'assigned_to' => $this->agent->id,
        ], $attributes));
    }

    private function mail(TicketActivity $activity, Ticket $ticket, User $to)
    {
        return (new TicketActivityNotification($ticket->id, $activity))->toMail($to);
    }

    private function html(TicketActivity $activity, Ticket $ticket, User $to): string
    {
        return (string) $this->mail($activity, $ticket, $to)->render();
    }

    // ---- What the email says ------------------------------------------------

    public function test_every_activity_renders_a_short_email_with_a_sign_in_link(): void
    {
        $ticket = $this->ticket(['subject' => 'Printer is down']);

        foreach (TicketActivity::cases() as $activity) {
            $mail = $this->mail($activity, $ticket, $this->agent);
            $html = (string) $mail->render();

            $this->assertSame("[{$ticket->number}] {$activity->label()}: Printer is down", $mail->subject);
            $this->assertStringContainsString($activity->line(), $html);
            $this->assertStringContainsString($ticket->number, $html);
            $this->assertStringContainsString('href="https://help.example.org/tickets/'.$ticket->id.'"', $html);
            $this->assertStringContainsString('sign in', $html);
        }
    }

    public function test_the_email_never_contains_message_text_the_description_or_file_details(): void
    {
        $ticket = $this->ticket(['description' => 'DESCRIPTION-SECRET-TEXT']);
        $message = TicketMessage::create(['ticket_id' => $ticket->id, 'user_id' => $this->agent->id, 'type' => 'public_reply', 'body' => 'REPLY-BODY-SECRET']);
        TicketAttachment::create([
            'ticket_id' => $ticket->id, 'ticket_message_id' => $message->id, 'uploaded_by' => $this->agent->id,
            'storage_path' => 'tickets/1/abc', 'original_name' => 'FILE-NAME-SECRET.pdf', 'extension' => 'pdf',
            'mime_type' => 'application/pdf', 'size' => 10, 'sha256' => str_repeat('a', 64),
        ]);

        foreach (TicketActivity::cases() as $activity) {
            $mail = $this->mail($activity, $ticket, $this->customer);
            $html = (string) $mail->render();

            foreach (['REPLY-BODY-SECRET', 'DESCRIPTION-SECRET-TEXT', 'FILE-NAME-SECRET', 'application/pdf'] as $secret) {
                $this->assertStringNotContainsString($secret, $html, "{$activity->value} leaked {$secret}");
                $this->assertStringNotContainsString($secret, (string) $mail->subject);
            }
            $this->assertSame([], $mail->attachments);
            $this->assertSame([], $mail->rawAttachments);
        }
    }

    public function test_a_hostile_subject_cannot_inject_links_markup_or_headers(): void
    {
        $ticket = $this->ticket(['subject' => "[Reset your password](https://evil.example/login) www.evil.example https://evil.example <script>alert(1)</script> <b>x</b> **bold** & Hi\r\nBcc: attacker@example.com"]);

        $mail = $this->mail(TicketActivity::StaffReply, $ticket, $this->customer);
        $html = (string) $mail->render();

        // Subject header: one line, no header injection.
        $this->assertStringNotContainsString("\n", $mail->subject);
        $this->assertStringNotContainsString("\r", $mail->subject);

        // Body: every link is ours: the layout's header link (the app URL) and the "View ticket" button.
        preg_match_all('/<a\s[^>]*href="([^"]+)"/i', $html, $links);
        $this->assertEqualsCanonicalizing([config('app.url'), 'https://help.example.org/tickets/'.$ticket->id], $links[1]);
        $this->assertStringNotContainsString('evil.example', implode(' ', $links[1]));
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<b>x</b>', $html);
        $this->assertStringNotContainsString('<strong>bold</strong>', $html);
        // The words are still shown, as plain text.
        $this->assertStringContainsString('Reset your password', $html);
    }

    public function test_a_very_long_subject_is_shortened(): void
    {
        $ticket = $this->ticket(['subject' => str_repeat('x', 150)]);

        $this->assertLessThanOrEqual(160, mb_strlen($this->mail(TicketActivity::Assigned, $ticket, $this->agent)->subject));
    }

    // ---- Sent when it still makes sense --------------------------------------

    public function test_it_is_queued_and_waits_for_a_commit(): void
    {
        $notification = new TicketActivityNotification(1, TicketActivity::Assigned);

        $this->assertInstanceOf(ShouldQueue::class, $notification);
        $this->assertTrue($notification->afterCommit);
        $this->assertSame(['mail'], $notification->via($this->agent));
        $this->assertSame(3, $notification->tries);
    }

    public function test_should_send_is_checked_again_when_the_email_finally_goes_out(): void
    {
        $ticket = $this->ticket();
        $note = new TicketActivityNotification($ticket->id, TicketActivity::InternalNote);
        $this->assertTrue($note->shouldSend($this->agent, 'mail'));

        // The agent loses the permission while the email waits in the queue.
        $this->agent->roles->first()->revokePermissionTo('ticket.internal_note');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertFalse($note->shouldSend($this->agent->fresh(), 'mail'));

        // The account is deactivated.
        $reply = new TicketActivityNotification($ticket->id, TicketActivity::StaffReply);
        $this->assertTrue($reply->shouldSend($this->customer, 'mail'));
        $this->customer->forceFill(['status' => UserStatus::Inactive])->save();
        $this->assertFalse($reply->shouldSend($this->customer->fresh(), 'mail'));

        // The ticket is gone.
        $ticket->forceFill(['assigned_to' => null])->save();
        $ticket->delete();
        $this->assertFalse($reply->shouldSend($this->customer, 'mail'));
    }

    public function test_a_note_email_is_refused_for_the_customer_at_send_time_too(): void
    {
        $ticket = $this->ticket();
        $this->customer->givePermissionTo('ticket.internal_note', 'ticket.view_all');

        $this->assertFalse((new TicketActivityNotification($ticket->id, TicketActivity::InternalNote))->shouldSend($this->customer, 'mail'));
    }

    // ---- The notifier -------------------------------------------------------

    public function test_the_notifier_sends_to_the_right_people(): void
    {
        Notification::fake();
        $ticket = $this->ticket();

        TicketNotifier::send(TicketActivity::StaffReply, $ticket, $this->agent);

        Notification::assertSentTo($this->customer, TicketActivityNotification::class, fn ($n) => $n->activity === TicketActivity::StaffReply && $n->ticketId === $ticket->id);
        Notification::assertSentToTimes($this->customer, TicketActivityNotification::class, 1);
        Notification::assertNotSentTo([$this->agent, $this->admin], TicketActivityNotification::class);
    }

    public function test_the_off_switch_sends_nothing(): void
    {
        Notification::fake();
        config(['helpdesk.ticket_notifications' => false]);

        TicketNotifier::send(TicketActivity::StaffReply, $this->ticket(), $this->agent);

        Notification::assertNothingSent();
    }

    public function test_nothing_is_sent_for_an_event_nobody_should_hear_about(): void
    {
        Notification::fake();

        TicketNotifier::send(TicketActivity::InternalNote, $this->ticket(['assigned_to' => null]), $this->admin);

        Notification::assertNothingSent();
    }

    public function test_nothing_is_sent_when_the_transaction_rolls_back(): void
    {
        Notification::fake();
        $ticket = $this->ticket();

        try {
            DB::transaction(function () use ($ticket) {
                TicketNotifier::send(TicketActivity::StaffReply, $ticket, $this->agent);
                Notification::assertNothingSent(); // not yet: the transaction is still open
                throw new \RuntimeException('something failed after the notification was requested');
            });
        } catch (\RuntimeException) {
        }

        Notification::assertNothingSent();
    }

    public function test_it_is_sent_only_after_the_transaction_commits(): void
    {
        Notification::fake();
        $ticket = $this->ticket();

        DB::transaction(function () use ($ticket) {
            TicketNotifier::send(TicketActivity::StaffReply, $ticket, $this->agent);
            Notification::assertNothingSent();
        });

        Notification::assertSentTo($this->customer, TicketActivityNotification::class);
    }

    public function test_a_failure_while_sending_never_reaches_the_caller(): void
    {
        Exceptions::fake();
        Notification::shouldReceive('send')->andThrow(new \RuntimeException('queue is down'));

        TicketNotifier::send(TicketActivity::StaffReply, $this->ticket(), $this->agent);

        Exceptions::assertReported(fn (\RuntimeException $e) => $e->getMessage() === 'queue is down');
    }

    public function test_a_failure_choosing_recipients_never_reaches_the_caller(): void
    {
        Exceptions::fake();
        $ticket = $this->ticket();
        $broken = new class extends Ticket
        {
            public function loadMissing($relations)
            {
                throw new \RuntimeException('database hiccup');
            }
        };

        TicketNotifier::send(TicketActivity::StaffReply, $broken, $this->agent);

        Exceptions::assertReported(fn (\RuntimeException $e) => $e->getMessage() === 'database hiccup');
        $this->assertSame(TicketStatus::Open, $ticket->fresh()->status);
    }

    public function test_it_goes_through_the_queue_when_the_connection_is_a_real_queue(): void
    {
        Queue::fake();
        $ticket = $this->ticket(['assigned_to' => null]);

        TicketNotifier::send(TicketActivity::NewForStaff, $ticket, $this->customer);

        Queue::assertPushed(SendQueuedNotifications::class, 1); // one job per recipient (the one admin)
    }

    public function test_with_the_sync_connection_the_email_is_sent_at_once(): void
    {
        config(['queue.default' => 'sync', 'mail.default' => 'array']);
        $ticket = $this->ticket();

        TicketNotifier::send(TicketActivity::StaffReply, $ticket, $this->agent);

        $sent = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(1, $sent);
        $email = $sent[0]->getOriginalMessage();
        $this->assertSame($this->customer->email, $email->getTo()[0]->getAddress());
        $this->assertStringContainsString("[{$ticket->number}] New reply", $email->getSubject());
    }
}
