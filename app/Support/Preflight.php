<?php

namespace App\Support;

use App\Support\Attachments\AttachmentStorage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Deployment self-check behind `php artisan helpdesk:preflight`. FAIL = must be fixed
 * before real users arrive; WARN = should be looked at; OK = fine.
 */
final class Preflight
{
    public const OK = 'OK';

    public const WARN = 'WARN';

    public const FAIL = 'FAIL';

    /** @return list<array{status: string, check: string, detail: string}> */
    public static function run(): array
    {
        $results = [];
        $add = function (string $status, string $check, string $detail = '') use (&$results) {
            $results[] = ['status' => $status, 'check' => $check, 'detail' => $detail];
        };
        $must = fn (bool $ok, string $check, string $problem) => $add($ok ? self::OK : self::FAIL, $check, $ok ? '' : $problem);
        $should = fn (bool $ok, string $check, string $problem) => $add($ok ? self::OK : self::WARN, $check, $ok ? '' : $problem);

        // ---- Application
        $must(config('app.env') === 'production', 'APP_ENV is production', 'APP_ENV is "'.config('app.env').'".');
        $must(config('app.debug') === false, 'APP_DEBUG is off', 'Debug mode shows stack traces and configuration to anyone who triggers an error.');
        $must(filled(config('app.key')), 'APP_KEY is set', 'Run `php artisan key:generate` (once, and keep it).');
        $must(self::isHttpsUrl(config('app.url')), 'APP_URL is an https URL', 'APP_URL is "'.config('app.url').'".');

        // ---- Frontend / CORS
        $front = (string) config('helpdesk.frontend_url');
        $must(self::isHttpsUrl($front) && ! self::isLocal($front), 'FRONTEND_URL is a public https URL', 'FRONTEND_URL is "'.$front.'". Emailed links and CORS use it.');
        $origins = config('cors.allowed_origins');
        $must(! in_array('*', $origins, true) && collect($origins)->every(fn ($o) => self::isHttpsUrl($o) && ! self::isLocal($o)), 'CORS origins are public https origins', 'Allowed origins: '.implode(', ', $origins));

        // ---- Mail
        $mailer = (string) config('mail.default');
        $must(! in_array($mailer, ['log', 'array'], true), 'A real mail transport is configured', 'MAIL_MAILER is "'.$mailer.'": verification and reset emails would never be delivered.');
        $must(! str_ends_with((string) config('mail.from.address'), '@example.com'), 'MAIL_FROM_ADDRESS is a real address', 'It is "'.config('mail.from.address').'".');

        // ---- Database
        try {
            DB::connection()->getPdo();
            $add(self::OK, 'Database reachable');
            $user = (string) config('database.connections.'.config('database.default').'.username');
            $should($user !== 'root', 'Database user is not root', 'Use a dedicated account with rights on this schema only.');
        } catch (Throwable $e) {
            $add(self::FAIL, 'Database reachable', $e->getMessage());
        }

        // ---- Files
        $root = (string) config('filesystems.disks.'.config('helpdesk.attachments.disk').'.root');
        $must($root !== '' && ! str_starts_with(str_replace('\\', '/', $root), str_replace('\\', '/', public_path())), 'Attachments are stored outside public/', 'Root is "'.$root.'".');
        $must(config('filesystems.disks.attachments.serve') === false && config('filesystems.disks.local.serve') === false, 'Filesystem disks are not served directly', 'Set "serve" to false on the local and attachments disks.');
        try {
            $probe = '.preflight-'.bin2hex(random_bytes(4));
            AttachmentStorage::disk()->put($probe, 'x');
            AttachmentStorage::disk()->delete($probe);
            $add(self::OK, 'Attachments disk is writable');
        } catch (Throwable $e) {
            $add(self::FAIL, 'Attachments disk is writable', $e->getMessage());
        }
        $must(self::bytes(ini_get('upload_max_filesize')) >= config('helpdesk.attachments.max_size_kb') * 1024, 'PHP upload_max_filesize covers the attachment limit', 'upload_max_filesize is '.ini_get('upload_max_filesize').'; a '.round(config('helpdesk.attachments.max_size_kb') / 1024, 1).' MB file would fail.');
        $must(self::bytes(ini_get('post_max_size')) >= (config('helpdesk.attachments.max_request_kb') + 1024) * 1024, 'PHP post_max_size covers a full upload request', 'post_max_size is '.ini_get('post_max_size').'.');

        // ---- Network
        $should(filled(config('helpdesk.trusted_proxies')), 'TRUSTED_PROXIES is set', 'Behind a proxy/load balancer every client looks like one IP, so login/registration rate limits are shared. Ignore only if the app is exposed directly.');
        $should(config('helpdesk.hsts') === true, 'HSTS is enabled', 'Set HSTS_ENABLED=true once the site is HTTPS-only.');

        // ---- Operations
        // The file channels all read LOG_LEVEL, so one of them stands for the setting.
        $should(config('logging.channels.single.level') !== 'debug', 'Log level is not debug', 'LOG_LEVEL=debug logs far more than needed and grows fast; use warning (or error).');
        $should(app()->configurationIsCached(), 'Configuration is cached', 'Run `php artisan config:cache` after every deploy.');
        $should(app()->routesAreCached(), 'Routes are cached', 'Run `php artisan route:cache` after every deploy.');

        try {
            $beat = Cache::get(config('helpdesk.scheduler_heartbeat_key'));
            $fresh = $beat && now()->getTimestamp() - (int) $beat < 300;
            $should($fresh, 'Scheduler is running', $beat ? 'Last heartbeat was '.now()->diffForHumans(Carbon::createFromTimestamp((int) $beat), true).' ago. Is `schedule:run` in cron every minute?' : 'No heartbeat yet. Add `* * * * * php artisan schedule:run` to cron; retention and orphan cleanup depend on it.');
        } catch (Throwable $e) {
            $add(self::WARN, 'Scheduler is running', 'Cache unavailable: '.$e->getMessage());
        }

        // ---- Queue (ticket notification emails are queued)
        $connection = (string) config('queue.default');
        $should($connection !== 'sync', 'Queue runs in the background', 'QUEUE_CONNECTION=sync sends ticket emails inside the web request (slower, and an SMTP outage delays users). Use "database" and run `php artisan queue:work`.');

        if ($connection === 'database') {
            try {
                $waiting = DB::table(config('queue.connections.database.table', 'jobs'))
                    ->where('created_at', '<', now()->subMinutes(5)->getTimestamp())
                    ->count();
                $should($waiting === 0, 'No queued jobs are stuck', "{$waiting} job(s) have waited over 5 minutes: is `php artisan queue:work` running (Supervisor)?");

                $failed = DB::table(config('queue.failed.table', 'failed_jobs'))->count();
                $should($failed === 0, 'No failed queue jobs', "{$failed} failed job(s): inspect with `php artisan queue:failed`, retry with `php artisan queue:retry all`.");
            } catch (Throwable $e) {
                $add(self::WARN, 'Queue tables are readable', $e->getMessage());
            }
        }

        return $results;
    }

    private static function isHttpsUrl(mixed $url): bool
    {
        return is_string($url) && str_starts_with(strtolower($url), 'https://');
    }

    private static function isLocal(string $url): bool
    {
        return (bool) preg_match('#^https?://(localhost|127\.|0\.0\.0\.0|\[::1\])#i', $url);
    }

    /** php.ini shorthand ("6M", "1G", "512K") in bytes; -1 means unlimited. */
    private static function bytes(string|false $value): int
    {
        $value = trim((string) $value);

        if ($value === '' || $value === '-1') {
            return PHP_INT_MAX;
        }

        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
