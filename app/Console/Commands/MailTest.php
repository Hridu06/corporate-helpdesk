<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

class MailTest extends Command
{
    protected $signature = 'helpdesk:mail-test {to : Address to send the test message to}';

    protected $description = 'Send one test email through the configured mail transport and report the result';

    public function handle(): int
    {
        $to = (string) $this->argument('to');

        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->error('That is not a valid email address.');

            return self::INVALID;
        }

        $mailer = config('mail.default');
        $this->line("Sending through \"{$mailer}\" from ".config('mail.from.address').' …');

        try {
            Mail::raw('This is a test message from '.config('app.name').'. If you can read it, outgoing email works.', function ($message) use ($to) {
                $message->to($to)->subject('Helpdesk mail test');
            });
        } catch (Throwable $e) {
            $this->error('Sending failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info(in_array($mailer, ['log', 'array'], true)
            ? "Handed to the \"{$mailer}\" transport, which does not deliver anything. Set MAIL_MAILER=smtp (or ses, postmark, resend) for real delivery."
            : "Accepted by the \"{$mailer}\" transport. Check the inbox (and spam) of {$to}.");

        return self::SUCCESS;
    }
}
