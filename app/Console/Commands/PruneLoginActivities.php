<?php

namespace App\Console\Commands;

use App\Models\LoginActivity;
use Illuminate\Console\Command;

class PruneLoginActivities extends Command
{
    protected $signature = 'helpdesk:prune-login-activities {--days= : Keep this many days (default from config)}';

    protected $description = 'Delete sign-in history older than the retention period';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: config('helpdesk.login_activity_retention_days'));

        if ($days < 1) {
            $this->error('Retention must be at least 1 day.');

            return self::FAILURE;
        }

        $deleted = LoginActivity::query()->where('login_at', '<', now()->subDays($days))->delete();

        $this->info("Deleted {$deleted} login activity record(s) older than {$days} days.");

        return self::SUCCESS;
    }
}
