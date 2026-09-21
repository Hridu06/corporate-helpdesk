<?php

namespace Tests\Feature\Production;

use App\Enums\RoleName;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Support\Preflight;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductionReadinessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    protected function tearDown(): void
    {
        TrustProxies::flushState();
        parent::tearDown();
    }

    private function customer(array $attributes = []): User
    {
        return User::factory()->create($attributes)->assignRole(RoleName::Customer->value);
    }

    /** Make every attempt to send mail fail, like an SMTP outage. */
    private function smtpDown(): void
    {
        config(['mail.default' => 'array']);
        Event::listen(MessageSending::class, fn () => throw new \RuntimeException('smtp is down'));
    }

    // ---- Mail outages -------------------------------------------------------

    public function test_a_mail_outage_does_not_change_the_forgot_password_answer(): void
    {
        $this->smtpDown();
        Exceptions::fake();
        $existing = $this->customer(['email' => 'real@example.org']);

        $known = $this->postJson('/api/forgot-password', ['email' => $existing->email]);
        $unknown = $this->postJson('/api/forgot-password', ['email' => 'nobody@example.org']);

        // Identical status and body: the outage cannot be used to tell accounts apart.
        $known->assertStatus(202);
        $this->assertSame($unknown->getStatusCode(), $known->getStatusCode());
        $this->assertSame($unknown->json(), $known->json());
        Exceptions::assertReported(fn (\RuntimeException $e) => $e->getMessage() === 'smtp is down');
    }

    public function test_registration_survives_a_mail_outage(): void
    {
        $this->smtpDown();
        Exceptions::fake();

        $this->postJson('/api/register', [
            'name' => 'New Person', 'email' => 'new@example.org',
            'password' => 'Str0ng!Passw0rd', 'password_confirmation' => 'Str0ng!Passw0rd',
        ])->assertCreated();

        $this->assertDatabaseHas('users', ['email' => 'new@example.org']);
        Exceptions::assertReported(\RuntimeException::class);
    }

    public function test_resend_verification_gives_the_same_answer_during_an_outage(): void
    {
        $this->smtpDown();
        Exceptions::fake();
        $unverified = $this->customer(['email' => 'unverified@example.org', 'email_verified_at' => null]);

        $known = $this->postJson('/api/email/verification-notification', ['email' => $unverified->email]);
        $unknown = $this->postJson('/api/email/verification-notification', ['email' => 'nobody@example.org']);

        $known->assertStatus(202);
        $this->assertSame($unknown->json(), $known->json());
    }

    public function test_an_admin_is_told_when_the_invitation_email_could_not_be_sent(): void
    {
        $this->smtpDown();
        Exceptions::fake();
        Sanctum::actingAs(User::factory()->create()->assignRole(RoleName::SuperAdmin->value));

        $this->postJson('/api/users', ['name' => 'Invitee', 'email' => 'invitee@example.org', 'role' => 'agent'])
            ->assertCreated()
            ->assertJsonPath('email_sent', false)
            ->assertJsonPath('user.email', 'invitee@example.org');

        $this->assertDatabaseHas('users', ['email' => 'invitee@example.org']);
    }

    public function test_when_mail_works_the_admin_message_is_unchanged(): void
    {
        config(['mail.default' => 'array']);
        Sanctum::actingAs(User::factory()->create()->assignRole(RoleName::SuperAdmin->value));

        $this->postJson('/api/users', ['name' => 'Invitee', 'email' => 'invitee@example.org', 'role' => 'agent'])
            ->assertCreated()
            ->assertJsonPath('email_sent', true)
            ->assertJsonPath('message', 'User created. A link to set their password has been emailed.');
    }

    // ---- Proxies ------------------------------------------------------------

    private function probe(): TestResponse
    {
        Route::get('/_probe', fn (Request $r) => ['ip' => $r->ip(), 'secure' => $r->isSecure()]);

        return $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])
            ->withHeaders(['X-Forwarded-For' => '203.0.113.9', 'X-Forwarded-Proto' => 'https'])
            ->getJson('/_probe');
    }

    public function test_forwarded_headers_are_ignored_unless_the_proxy_is_trusted(): void
    {
        AppServiceProvider::configureTrustedProxies(null);
        $this->probe()->assertJson(['ip' => '10.0.0.5', 'secure' => false]);
    }

    public function test_a_configured_proxy_lets_the_real_client_ip_through(): void
    {
        AppServiceProvider::configureTrustedProxies('10.0.0.5, 192.168.0.0/16');
        $this->probe()->assertJson(['ip' => '203.0.113.9', 'secure' => true]);
    }

    public function test_a_different_caller_cannot_spoof_the_address(): void
    {
        AppServiceProvider::configureTrustedProxies('10.0.0.99');
        $this->probe()->assertJson(['ip' => '10.0.0.5', 'secure' => false]);
    }

    public function test_the_wildcard_trusts_every_caller(): void
    {
        AppServiceProvider::configureTrustedProxies('*');
        $this->probe()->assertJson(['ip' => '203.0.113.9']);
    }

    // ---- Headers, CORS, errors ----------------------------------------------

    public function test_api_responses_carry_the_security_headers_even_for_errors(): void
    {
        foreach ([$this->getJson('/api/user'), $this->postJson('/api/login', [])] as $response) {
            $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
            $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
            $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            $this->assertNull($response->headers->get('Strict-Transport-Security'));
        }
    }

    public function test_hsts_is_sent_only_when_enabled_and_the_request_is_https(): void
    {
        config(['helpdesk.hsts' => true, 'helpdesk.hsts_max_age' => 1000]);

        $this->assertNull($this->getJson('/api/user')->headers->get('Strict-Transport-Security'));
        $this->assertSame('max-age=1000', $this->getJson('https://localhost/api/user')->headers->get('Strict-Transport-Security'));
    }

    public function test_a_header_set_by_a_controller_is_not_overwritten(): void
    {
        Route::middleware('api')->get('/api/_own-headers', fn () => response('x')->header('X-Frame-Options', 'SAMEORIGIN')->header('Cache-Control', 'private, no-store'));

        $response = $this->get('/api/_own-headers');
        $this->assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('no-cache', $response->headers->get('Cache-Control'));
    }

    public function test_cors_allows_the_frontend_origin_and_nobody_else(): void
    {
        config(['cors.allowed_origins' => ['https://help.example.org']]);
        $preflight = fn (string $origin, string $method = 'POST') => $this->call('OPTIONS', '/api/tickets', [], [], [], [
            'HTTP_ORIGIN' => $origin,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => $method,
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization,content-type',
        ]);

        $allowed = $preflight('https://help.example.org');
        $this->assertSame('https://help.example.org', $allowed->headers->get('Access-Control-Allow-Origin'));
        $this->assertNull($allowed->headers->get('Access-Control-Allow-Credentials'));
        $this->assertNotNull($allowed->headers->get('Access-Control-Max-Age'));

        // The library answers with the single configured origin, never the caller's, so a browser
        // on any other origin sees a mismatch and refuses the response.
        $this->assertNotSame('https://evil.example.com', $preflight('https://evil.example.com')->headers->get('Access-Control-Allow-Origin'));
        $this->assertNotSame('http://help.example.org', $preflight('http://help.example.org')->headers->get('Access-Control-Allow-Origin')); // wrong scheme
    }

    public function test_cors_configuration_is_explicit_not_a_wildcard(): void
    {
        $this->assertNotContains('*', config('cors.allowed_origins'));
        $this->assertNotContains('*', config('cors.allowed_methods'));
        $this->assertNotContains('*', config('cors.allowed_headers'));
        $this->assertFalse(config('cors.supports_credentials'));
        $this->assertContains('Authorization', config('cors.allowed_headers'));
    }

    public function test_with_debug_off_an_unexpected_error_leaks_nothing(): void
    {
        config(['app.debug' => false]);
        Exceptions::fake();
        Route::middleware('api')->get('/api/_boom', fn () => throw new \RuntimeException('secret database detail /var/www/app/Secret.php'));

        $response = $this->getJson('/api/_boom')->assertStatus(500);
        $body = $response->getContent();

        $this->assertSame('Server Error', $response->json('message'));
        foreach (['secret database detail', 'Secret.php', 'trace', 'exception', 'file', 'line'] as $leak) {
            $this->assertStringNotContainsString($leak, $body);
        }
    }

    // ---- Scheduler ----------------------------------------------------------

    public function test_the_scheduler_writes_a_heartbeat(): void
    {
        $key = config('helpdesk.scheduler_heartbeat_key');
        $this->assertNull(Cache::get($key));

        $this->artisan('schedule:run')->assertSuccessful();

        $this->assertEqualsWithDelta(now()->getTimestamp(), (int) Cache::get($key), 5);
    }

    // ---- Preflight ----------------------------------------------------------

    /** A configuration that should pass every hard requirement. */
    private function productionConfig(): void
    {
        config([
            'app.env' => 'production',
            'app.debug' => false,
            'app.url' => 'https://api.company.org',
            'helpdesk.frontend_url' => 'https://help.company.org',
            'cors.allowed_origins' => ['https://help.company.org'],
            'mail.default' => 'smtp',
            'mail.from.address' => 'support@company.org',
            // Small enough for PHP's stock upload limits, so this holds on any machine (CI included).
            'helpdesk.attachments.max_size_kb' => 512,
            'helpdesk.attachments.max_request_kb' => 1024,
        ]);
    }

    /** @return array<string, string> check => status */
    private function statuses(): array
    {
        return collect(Preflight::run())->pluck('status', 'check')->all();
    }

    public function test_a_correct_production_configuration_has_no_failures(): void
    {
        $this->productionConfig();

        $failed = array_keys(array_filter($this->statuses(), fn ($s) => $s === Preflight::FAIL));
        $this->assertSame([], $failed);
    }

    public function test_each_unsafe_setting_is_reported_as_a_failure(): void
    {
        $cases = [
            'APP_DEBUG is off' => ['app.debug' => true],
            'APP_ENV is production' => ['app.env' => 'local'],
            'APP_KEY is set' => ['app.key' => ''],
            'APP_URL is an https URL' => ['app.url' => 'http://api.company.org'],
            'FRONTEND_URL is a public https URL' => ['helpdesk.frontend_url' => 'http://localhost:5173'],
            'CORS origins are public https origins' => ['cors.allowed_origins' => ['*']],
            'A real mail transport is configured' => ['mail.default' => 'log'],
            'MAIL_FROM_ADDRESS is a real address' => ['mail.from.address' => 'hello@example.com'],
            'Filesystem disks are not served directly' => ['filesystems.disks.local.serve' => true],
            'Attachments are stored outside public/' => ['filesystems.disks.attachments.root' => public_path('files')],
            'PHP upload_max_filesize covers the attachment limit' => ['helpdesk.attachments.max_size_kb' => 900000],
            'PHP post_max_size covers a full upload request' => ['helpdesk.attachments.max_request_kb' => 900000],
        ];

        foreach ($cases as $check => $override) {
            $this->productionConfig();
            config($override);

            $this->assertSame(Preflight::FAIL, $this->statuses()[$check] ?? null, "Expected \"{$check}\" to fail.");
        }
    }

    public function test_softer_findings_are_warnings_not_failures(): void
    {
        $this->productionConfig();
        config(['helpdesk.trusted_proxies' => null, 'helpdesk.hsts' => false]);
        Cache::forget(config('helpdesk.scheduler_heartbeat_key'));

        $statuses = $this->statuses();
        $this->assertSame(Preflight::WARN, $statuses['TRUSTED_PROXIES is set']);
        $this->assertSame(Preflight::WARN, $statuses['HSTS is enabled']);
        $this->assertSame(Preflight::WARN, $statuses['Scheduler is running']);

        config(['helpdesk.trusted_proxies' => '*', 'helpdesk.hsts' => true]);
        Cache::put(config('helpdesk.scheduler_heartbeat_key'), now()->getTimestamp());
        $statuses = $this->statuses();
        $this->assertSame(Preflight::OK, $statuses['TRUSTED_PROXIES is set']);
        $this->assertSame(Preflight::OK, $statuses['Scheduler is running']);

        Cache::put(config('helpdesk.scheduler_heartbeat_key'), now()->subMinutes(20)->getTimestamp());
        $this->assertSame(Preflight::WARN, $this->statuses()['Scheduler is running']);
    }

    public function test_the_command_fails_a_local_setup_and_passes_a_correct_one(): void
    {
        $this->artisan('helpdesk:preflight')->assertFailed();

        $this->productionConfig();
        $this->artisan('helpdesk:preflight')->assertSuccessful();
        // Warnings (no proxy setting, no heartbeat...) fail only in strict mode.
        $this->artisan('helpdesk:preflight', ['--strict' => true])->assertFailed();
    }

    // ---- Mail test command --------------------------------------------------

    public function test_mail_test_sends_and_reports(): void
    {
        config(['mail.default' => 'array']);

        $this->artisan('helpdesk:mail-test', ['to' => 'me@example.org'])
            ->expectsOutputToContain('does not deliver anything')
            ->assertSuccessful();

        $sent = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(1, $sent);
        $this->assertSame('me@example.org', $sent[0]->getOriginalMessage()->getTo()[0]->getAddress());
    }

    public function test_mail_test_reports_a_failing_transport_and_bad_input(): void
    {
        $this->smtpDown();

        $this->artisan('helpdesk:mail-test', ['to' => 'me@example.org'])
            ->expectsOutputToContain('Sending failed: smtp is down')
            ->assertFailed();

        $this->artisan('helpdesk:mail-test', ['to' => 'not-an-email'])->assertExitCode(2);
    }
}
