<?php

namespace Tests\Feature\Auth;

use App\Enums\LoginStatus;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\LoginActivity;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LoginActivityTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Str0ng!Passw0rd';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function makeUser(RoleName $role = RoleName::Customer, array $attributes = []): User
    {
        return User::factory()->create(array_merge(['password' => self::PASSWORD], $attributes))->assignRole($role->value);
    }

    private function login(User|string $who, string $password = self::PASSWORD, array $headers = [])
    {
        $email = $who instanceof User ? $who->email : $who;

        return $this->withHeaders($headers)->postJson('/api/login', ['email' => $email, 'password' => $password]);
    }

    private function activity(User $user, array $attributes = []): LoginActivity
    {
        return LoginActivity::create(array_merge([
            'user_id' => $user->id,
            'status' => LoginStatus::Success,
            'ip_address' => '203.0.113.7',
            'user_agent' => 'Mozilla/5.0 Test',
            'login_at' => now(),
        ], $attributes));
    }

    // ---- Recording -----------------------------------------------------------

    public function test_successful_login_is_recorded_with_ip_agent_and_token_link(): void
    {
        $user = $this->makeUser();

        $this->login($user, headers: ['User-Agent' => 'PHPUnit-Agent/1.0'])->assertOk();

        $activity = LoginActivity::sole();
        $this->assertSame($user->id, $activity->user_id);
        $this->assertSame(LoginStatus::Success, $activity->status);
        $this->assertSame('127.0.0.1', $activity->ip_address);
        $this->assertSame('PHPUnit-Agent/1.0', $activity->user_agent);
        $this->assertNotNull($activity->login_at);
        $this->assertNull($activity->logout_at);
        $this->assertSame($user->tokens()->sole()->id, $activity->token_id);
    }

    public function test_oversized_user_agent_is_truncated(): void
    {
        $this->login($this->makeUser(), headers: ['User-Agent' => str_repeat('A', 2000)])->assertOk();

        $this->assertSame(512, strlen(LoginActivity::sole()->user_agent));
    }

    public function test_wrong_password_for_an_existing_account_is_recorded_as_failed(): void
    {
        $user = $this->makeUser();

        $this->login($user, 'Wrong!Passw0rd')->assertUnauthorized();

        $activity = LoginActivity::sole();
        $this->assertSame($user->id, $activity->user_id);
        $this->assertSame(LoginStatus::Failed, $activity->status);
        $this->assertNull($activity->token_id);
    }

    public function test_attempts_against_unknown_emails_are_never_stored(): void
    {
        $this->login('nobody@example.com', 'Wrong!Passw0rd')->assertUnauthorized();
        $this->login('someone@example.com', 'my-secret-typed-in-the-email-box')->assertUnauthorized();

        $this->assertDatabaseCount('login_activities', 0);
    }

    public function test_correct_password_on_blocked_accounts_is_recorded_as_blocked(): void
    {
        $inactive = $this->makeUser(attributes: ['status' => UserStatus::Inactive]);
        $unverified = $this->makeUser(attributes: ['email_verified_at' => null]);

        $this->login($inactive)->assertForbidden();
        $this->login($unverified)->assertForbidden();

        $this->assertSame(2, LoginActivity::where('status', LoginStatus::Blocked)->count());
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_wrong_password_on_a_blocked_account_is_still_just_failed(): void
    {
        $inactive = $this->makeUser(attributes: ['status' => UserStatus::Inactive]);

        $this->login($inactive, 'Wrong!Passw0rd')->assertUnauthorized();

        $this->assertSame(LoginStatus::Failed, LoginActivity::sole()->status);
    }

    public function test_logout_stamps_only_the_session_that_ended(): void
    {
        $user = $this->makeUser();
        $tokenA = $this->login($user)->json('token');
        $this->app['cache']->flush();
        $this->login($user)->assertOk();
        $this->app['auth']->forgetGuards();

        $this->withToken($tokenA)->postJson('/api/logout')->assertOk();

        $ended = LoginActivity::whereNotNull('logout_at')->get();
        $this->assertCount(1, $ended);
        $this->assertSame(1, LoginActivity::whereNull('logout_at')->count());
    }

    public function test_the_table_stores_no_credentials_or_email(): void
    {
        $columns = Schema::getColumnListing('login_activities');

        foreach (['email', 'password', 'token', 'remember_token'] as $forbidden) {
            $this->assertNotContains($forbidden, $columns);
        }
    }

    // ---- API -----------------------------------------------------------------

    public function test_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/login-activities')->assertUnauthorized();
    }

    public function test_only_users_with_activity_view_can_read_the_history(): void
    {
        foreach ([RoleName::Customer, RoleName::Agent, RoleName::Admin] as $role) {
            Sanctum::actingAs($this->makeUser($role));
            $this->getJson('/api/login-activities')->assertForbidden();
        }

        $admin = $this->makeUser(RoleName::Admin);
        $admin->givePermissionTo('activity.view');
        Sanctum::actingAs($admin);
        $this->getJson('/api/login-activities')->assertOk();

        Sanctum::actingAs($this->makeUser(RoleName::SuperAdmin));
        $this->getJson('/api/login-activities')->assertOk();
    }

    public function test_listing_shape_order_and_privacy(): void
    {
        $super = $this->makeUser(RoleName::SuperAdmin);
        $jane = $this->makeUser(attributes: ['name' => 'Jane Doe', 'email' => 'jane@example.com']);
        $this->activity($jane, ['login_at' => now()->subHours(2), 'token_id' => 999]);
        $this->activity($jane, ['login_at' => now()->subHour(), 'status' => LoginStatus::Failed]);
        Sanctum::actingAs($super);

        $response = $this->getJson('/api/login-activities')->assertOk();

        $response->assertJsonStructure([
            'data' => [['id', 'user' => ['id', 'name', 'email'], 'status', 'ip_address', 'user_agent', 'login_at', 'logout_at']],
            'meta' => ['current_page', 'per_page', 'total'],
        ]);
        $response->assertJsonPath('data.0.status', 'failed')->assertJsonPath('data.0.user.email', 'jane@example.com');
        $this->assertSame('success', $response->json('data.1.status'));
        $body = json_encode($response->json());
        $this->assertStringNotContainsString('token_id', $body);
        $this->assertStringNotContainsString('password', $body);
    }

    public function test_filters_by_status_user_search_and_dates(): void
    {
        Sanctum::actingAs($this->makeUser(RoleName::SuperAdmin));
        $jane = $this->makeUser(attributes: ['name' => 'Jane Doe', 'email' => 'jane@example.com']);
        $omar = $this->makeUser(attributes: ['name' => 'Omar Khan', 'email' => 'omar@example.com']);
        $this->activity($jane, ['login_at' => '2026-03-10 09:00:00']);
        $this->activity($jane, ['login_at' => '2026-03-20 09:00:00', 'status' => LoginStatus::Failed]);
        $this->activity($omar, ['login_at' => '2026-04-01 09:00:00', 'status' => LoginStatus::Blocked]);

        $this->getJson('/api/login-activities?status=failed')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/login-activities?user_id={$omar->id}")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.user.name', 'Omar Khan');
        $this->getJson('/api/login-activities?search=jane')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/login-activities?search=OMAR@')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/login-activities?from=2026-03-15&to=2026-03-31')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'failed');
        $this->getJson('/api/login-activities?from=2026-03-20')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/login-activities?per_page=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 3);
        $this->getJson('/api/login-activities?search=%25')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_invalid_filters_are_rejected(): void
    {
        Sanctum::actingAs($this->makeUser(RoleName::SuperAdmin));

        $this->getJson('/api/login-activities?status=bogus')->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->getJson('/api/login-activities?from=not-a-date')->assertUnprocessable()->assertJsonValidationErrors('from');
        $this->getJson('/api/login-activities?from=2026-05-01&to=2026-04-01')->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->getJson('/api/login-activities?per_page=1000')->assertUnprocessable()->assertJsonValidationErrors('per_page');
    }

    public function test_history_survives_account_deletion_with_a_null_user(): void
    {
        $super = $this->makeUser(RoleName::SuperAdmin);
        $gone = $this->makeUser();
        $this->activity($gone);
        $gone->delete();
        Sanctum::actingAs($super);

        $this->getJson('/api/login-activities')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.user', null);
    }

    // ---- Retention -----------------------------------------------------------

    public function test_prune_command_deletes_only_rows_older_than_the_retention_period(): void
    {
        $user = $this->makeUser();
        $old = $this->activity($user, ['login_at' => now()->subDays(100)]);
        $edge = $this->activity($user, ['login_at' => now()->subDays(91)]);
        $recent = $this->activity($user, ['login_at' => now()->subDays(10)]);

        $this->artisan('helpdesk:prune-login-activities')->assertSuccessful();

        $this->assertModelMissing($old);
        $this->assertModelMissing($edge);
        $this->assertModelExists($recent);
    }

    public function test_prune_days_option_overrides_the_default_and_is_validated(): void
    {
        $user = $this->makeUser();
        $tenDays = $this->activity($user, ['login_at' => now()->subDays(10)]);

        $this->artisan('helpdesk:prune-login-activities', ['--days' => 30])->assertSuccessful();
        $this->assertModelExists($tenDays);

        $this->artisan('helpdesk:prune-login-activities', ['--days' => 5])->assertSuccessful();
        $this->assertModelMissing($tenDays);

        $this->artisan('helpdesk:prune-login-activities', ['--days' => -1])->assertFailed();
    }

    public function test_prune_is_scheduled_daily(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('helpdesk:prune-login-activities')->assertSuccessful();
    }
}
