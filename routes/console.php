<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Retention: drop sign-in history past the configured age (needs the scheduler: `php artisan schedule:work`
// locally, or a `schedule:run` cron entry in production).
Schedule::command('helpdesk:prune-login-activities')->daily();

// Removes attachment files left behind by failed uploads or deleted tickets.
Schedule::command('helpdesk:prune-orphan-attachments')->daily();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
