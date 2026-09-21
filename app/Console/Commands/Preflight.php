<?php

namespace App\Console\Commands;

use App\Support\Preflight as PreflightChecks;
use Illuminate\Console\Command;

class Preflight extends Command
{
    protected $signature = 'helpdesk:preflight {--strict : Treat warnings as failures}';

    protected $description = 'Check this deployment is ready for production (debug off, mail, HTTPS, proxies, limits, scheduler)';

    public function handle(): int
    {
        $results = PreflightChecks::run();

        $this->table(
            ['', 'Check', 'Detail'],
            array_map(fn ($r) => [$r['status'], $r['check'], $r['detail']], $results),
        );

        $failed = count(array_filter($results, fn ($r) => $r['status'] === PreflightChecks::FAIL));
        $warned = count(array_filter($results, fn ($r) => $r['status'] === PreflightChecks::WARN));

        if ($failed > 0 || ($warned > 0 && $this->option('strict'))) {
            $this->error("Not ready: {$failed} failed, {$warned} warning(s).");

            return self::FAILURE;
        }

        $warned > 0 ? $this->warn("Ready, with {$warned} warning(s).") : $this->info('Ready.');

        return self::SUCCESS;
    }
}
