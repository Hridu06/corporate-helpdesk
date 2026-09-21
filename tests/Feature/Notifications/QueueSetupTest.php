<?php

namespace Tests\Feature\Notifications;

use App\Support\Preflight;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** The queue side of ticket emails: what the example files promise and what preflight reports. */
class QueueSetupTest extends TestCase
{
    use RefreshDatabase;

    private function setting(string $file, string $key): ?string
    {
        preg_match('/^'.preg_quote($key, '/').'=(.*)$/m', (string) file_get_contents(base_path($file)), $match);

        return isset($match[1]) ? trim($match[1]) : null;
    }

    /** @return array<string, string> check => status */
    private function statuses(): array
    {
        return collect(Preflight::run())->pluck('status', 'check')->all();
    }

    private function job(int $createdAt): void
    {
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => $createdAt, 'created_at' => $createdAt]);
    }

    private function failedJob(): void
    {
        DB::table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()]);
    }

    public function test_only_the_development_example_uses_the_sync_queue(): void
    {
        $this->assertSame('sync', $this->setting('.env.example', 'QUEUE_CONNECTION'));
        $this->assertSame('database', $this->setting('.env.production.example', 'QUEUE_CONNECTION'));
    }

    public function test_the_production_example_switches_ticket_emails_on_and_explains_the_worker(): void
    {
        $this->assertSame('true', $this->setting('.env.production.example', 'TICKET_NOTIFICATIONS_ENABLED'));
        $text = (string) file_get_contents(base_path('.env.production.example'));
        $this->assertStringContainsString('queue:work', $text);
        $this->assertStringContainsString('MUST stay "database"', $text);
    }

    public function test_the_notification_switch_defaults_to_on(): void
    {
        $this->assertTrue(config('helpdesk.ticket_notifications'));
    }

    public function test_preflight_warns_when_the_queue_is_sync(): void
    {
        config(['queue.default' => 'sync']);

        $this->assertSame(Preflight::WARN, $this->statuses()['Queue runs in the background']);
    }

    public function test_preflight_is_content_with_a_healthy_database_queue(): void
    {
        config(['queue.default' => 'database']);
        $this->job(now()->getTimestamp()); // just queued: the worker is not late yet

        $statuses = $this->statuses();
        $this->assertSame(Preflight::OK, $statuses['Queue runs in the background']);
        $this->assertSame(Preflight::OK, $statuses['No queued jobs are stuck']);
        $this->assertSame(Preflight::OK, $statuses['No failed queue jobs']);
    }

    public function test_preflight_warns_about_jobs_that_have_waited_too_long(): void
    {
        config(['queue.default' => 'database']);
        $this->job(now()->subMinutes(30)->getTimestamp());

        $result = collect(Preflight::run())->firstWhere('check', 'No queued jobs are stuck');
        $this->assertSame(Preflight::WARN, $result['status']);
        $this->assertStringContainsString('queue:work', $result['detail']);
        $this->assertStringContainsString('1 job(s)', $result['detail']);
    }

    public function test_preflight_warns_about_failed_jobs(): void
    {
        config(['queue.default' => 'database']);
        $this->failedJob();

        $result = collect(Preflight::run())->firstWhere('check', 'No failed queue jobs');
        $this->assertSame(Preflight::WARN, $result['status']);
        $this->assertStringContainsString('queue:retry', $result['detail']);
    }

    public function test_preflight_does_not_look_for_stuck_jobs_on_the_sync_queue(): void
    {
        config(['queue.default' => 'sync']);

        $this->assertArrayNotHasKey('No queued jobs are stuck', $this->statuses());
    }
}
