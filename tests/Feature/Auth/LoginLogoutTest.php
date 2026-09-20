<?php

namespace Tests\Feature\Auth;

use App\Enums\RoleName;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class LoginLogoutTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Str0ng!Passw0rd';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function customer(array $attributes = []): User
    {
        return User::factory()->create(array_merge(['password' => self::PASSWORD], $attributes))
            ->assignRole(RoleName::Customer->value);
    }

    private function login(User $user, array $extra = [])
    {
        return $this->postJson('/api/login', array_merge(
            ['email' => $user->email, 'password' => self::PASSWORD],
            $extra,
        ));
    }

    /** Sanctum's guard caches the resolved user between requests in one test. */
    private function withFreshGuard(): void
    {
        $this->app['auth']->forgetGuards();
    }

    public function test_successful_login_returns_token_and_safe_user(): void
    {
        $user = $this->customer();

        $response = $this->login($user);

        $response->assertOk()
            ->assertJsonStructure(['message', 'token', 'token_type', 'expires_at', 'user' => ['id', 'name', 'email', 'roles', 'permissions']])
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('user.roles', ['customer'])
            ->assertJsonMissingPath('user.password')
            ->assertJsonMissingPath('user.remember_token');

        $this->assertContains('ticket.create', $response->json('user.permissions'));
        $this->assertNotNull($user->fresh()->last_login_at);
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_email_is_matched_case_insensitively(): void
    {
        $user = $this->customer(['email' => 'jane@example.com']);

        $this->postJson('/api/login', ['email' => 'JANE@example.com', 'password' => self::PASSWORD])->assertOk();
    }

    public function test_invalid_password_is_rejected(): void
    {
        $user = $this->customer();

        $this->login($user, ['password' => 'Wrong!Passw0rd'])
            ->assertUnauthorized()
            ->assertJsonPath('code', 'invalid_credentials')
            ->assertJsonMissingPath('token');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_unknown_email_gets_same_response_as_wrong_password(): void
    {
        $user = $this->customer();

        $unknown = $this->postJson('/api/login', ['email' => 'nobody@example.com', 'password' => self::PASSWORD]);
        $wrong = $this->login($user, ['password' => 'Wrong!Passw0rd']);

        $unknown->assertUnauthorized();
        $this->assertSame($wrong->json(), $unknown->json());
    }

    public function test_inactive_account_cannot_login(): void
    {
        $user = $this->customer();
        $user->forceFill(['status' => 'inactive'])->save();

        $this->login($user)->assertForbidden()->assertJsonPath('code', 'account_inactive');
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_unverified_account_cannot_login(): void
    {
        $user = $this->customer(['email_verified_at' => null]);

        $this->login($user)->assertForbidden()->assertJsonPath('code', 'email_not_verified');
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_account_state_is_not_revealed_without_the_correct_password(): void
    {
        $user = $this->customer(['email_verified_at' => null]);

        $this->login($user, ['password' => 'Wrong!Passw0rd'])
            ->assertUnauthorized()
            ->assertJsonPath('code', 'invalid_credentials');
    }

    public function test_validation_errors_on_login(): void
    {
        $this->postJson('/api/login', [])->assertUnprocessable()->assertJsonValidationErrors(['email', 'password']);
        $this->postJson('/api/login', ['email' => 'bad', 'password' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_login_is_rate_limited(): void
    {
        $user = $this->customer();

        for ($i = 0; $i < 5; $i++) {
            $this->login($user, ['password' => 'Wrong!Passw0rd'])->assertUnauthorized();
        }

        $this->login($user)->assertStatus(429);
    }

    public function test_remember_me_issues_a_longer_lived_token(): void
    {
        $user = $this->customer();

        $short = $this->login($user)->json('expires_at');
        $this->app['cache']->flush();
        $long = $this->login($user, ['remember' => true])->json('expires_at');

        $this->assertTrue(now()->parse($long)->greaterThan(now()->parse($short)->addDays(20)));
        $this->assertEqualsWithDelta(
            config('helpdesk.token_ttl'),
            now()->parse($short)->diffInMinutes(now(), true),
            2,
        );
    }

    public function test_token_authenticates_current_user_endpoint(): void
    {
        $user = $this->customer();
        $token = $this->login($user)->json('token');
        $this->withFreshGuard();

        $this->withToken($token)->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('user.email', $user->email)
            ->assertJsonMissingPath('user.password');
    }

    public function test_user_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/user')->assertUnauthorized();
        $this->withToken('invalid-token')->getJson('/api/user')->assertUnauthorized();
    }

    public function test_super_admin_receives_all_permissions(): void
    {
        $super = User::factory()->create(['password' => self::PASSWORD])->assignRole(RoleName::SuperAdmin->value);
        $token = $this->login($super)->json('token');
        $this->withFreshGuard();

        $permissions = $this->withToken($token)->getJson('/api/user')->json('user.permissions');

        $this->assertContains('settings.manage', $permissions);
        $this->assertCount(30, $permissions);
    }

    public function test_logout_revokes_only_the_current_token(): void
    {
        $user = $this->customer();
        $this->login($user);
        $tokenA = $this->login($user)->json('token');
        $tokenB = $this->login($user)->json('token');
        $this->withFreshGuard();

        $this->withToken($tokenA)->postJson('/api/logout')->assertOk();
        $this->withFreshGuard();

        $this->assertDatabaseCount('personal_access_tokens', 2);
        $this->withToken($tokenA)->getJson('/api/user')->assertUnauthorized();
        $this->withFreshGuard();
        $this->withToken($tokenB)->getJson('/api/user')->assertOk();
    }

    public function test_logout_requires_authentication(): void
    {
        $this->postJson('/api/logout')->assertUnauthorized();
    }

    public function test_expired_token_is_rejected(): void
    {
        $user = $this->customer();
        $token = $this->login($user)->json('token');

        PersonalAccessToken::query()->update(['expires_at' => now()->subMinute()]);
        $this->withFreshGuard();

        $this->withToken($token)->getJson('/api/user')->assertUnauthorized();
    }

    public function test_deactivated_user_with_existing_token_is_blocked(): void
    {
        $user = $this->customer();
        $token = $this->login($user)->json('token');

        $user->forceFill(['status' => 'inactive'])->save();
        $this->withFreshGuard();

        $this->withToken($token)->getJson('/api/user')
            ->assertForbidden()
            ->assertJsonPath('code', 'account_inactive');
    }
}
