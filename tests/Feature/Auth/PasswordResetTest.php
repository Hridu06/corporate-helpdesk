<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private const OLD = 'Old!Passw0rd1';

    private const NEW = 'New!Passw0rd2';

    private function user(array $attributes = []): User
    {
        return User::factory()->create(array_merge(['password' => self::OLD], $attributes));
    }

    private function reset(User $user, string $token, array $overrides = [])
    {
        return $this->postJson('/api/reset-password', array_merge([
            'token' => $token,
            'email' => $user->email,
            'password' => self::NEW,
            'password_confirmation' => self::NEW,
        ], $overrides));
    }

    public function test_forgot_password_sends_link_to_the_frontend(): void
    {
        Notification::fake();
        $user = $this->user();

        $this->postJson('/api/forgot-password', ['email' => $user->email])->assertStatus(202);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $url = $notification->toMail($user)->actionUrl;

            return str_starts_with($url, config('helpdesk.frontend_url').'/reset-password?token=')
                && str_contains($url, 'email='.urlencode($user->email));
        });
    }

    public function test_forgot_password_response_does_not_reveal_whether_email_exists(): void
    {
        Notification::fake();
        $user = $this->user();

        $known = $this->postJson('/api/forgot-password', ['email' => $user->email]);
        $unknown = $this->postJson('/api/forgot-password', ['email' => 'nobody@example.com']);

        $this->assertSame($known->status(), $unknown->status());
        $this->assertSame($known->json(), $unknown->json());
        Notification::assertCount(1);
    }

    public function test_no_reset_email_for_inactive_accounts(): void
    {
        Notification::fake();
        $user = $this->user();
        $user->forceFill(['status' => 'inactive'])->save();

        $this->postJson('/api/forgot-password', ['email' => $user->email])->assertStatus(202);

        Notification::assertNothingSent();
    }

    public function test_forgot_password_validates_email(): void
    {
        $this->postJson('/api/forgot-password', [])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->postJson('/api/forgot-password', ['email' => 'nope'])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_forgot_password_is_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/forgot-password', ['email' => 'a@example.com'])->assertStatus(202);
        }

        $this->postJson('/api/forgot-password', ['email' => 'a@example.com'])->assertStatus(429);
    }

    public function test_password_can_be_reset_with_a_valid_token(): void
    {
        $user = $this->user();
        $token = Password::createToken($user);

        $this->reset($user, $token)->assertOk();

        $user->refresh();
        $this->assertTrue(password_verify(self::NEW, $user->password));
        $this->assertFalse(password_verify(self::OLD, $user->password));
    }

    public function test_reset_revokes_all_api_tokens(): void
    {
        $user = $this->user();
        $user->createToken('a');
        $user->createToken('b');
        $this->assertDatabaseCount('personal_access_tokens', 2);

        $this->reset($user, Password::createToken($user))->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_user_can_login_with_new_password_only(): void
    {
        $user = $this->user();
        $this->reset($user, Password::createToken($user))->assertOk();

        $this->postJson('/api/login', ['email' => $user->email, 'password' => self::OLD])->assertUnauthorized();
        $this->app['cache']->flush();
        $this->postJson('/api/login', ['email' => $user->email, 'password' => self::NEW])->assertOk();
    }

    public function test_token_is_single_use(): void
    {
        $user = $this->user();
        $token = Password::createToken($user);

        $this->reset($user, $token)->assertOk();
        $this->reset($user, $token, ['password' => 'Another!Passw0rd3', 'password_confirmation' => 'Another!Passw0rd3'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'invalid_reset_token');
    }

    public function test_invalid_token_is_rejected(): void
    {
        $user = $this->user();

        $this->reset($user, 'not-a-real-token')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'invalid_reset_token');

        $this->assertTrue(password_verify(self::OLD, $user->fresh()->password));
    }

    public function test_expired_token_is_rejected(): void
    {
        $user = $this->user();
        $token = Password::createToken($user);

        $this->travel(config('auth.passwords.users.expire') + 1)->minutes();

        $this->reset($user, $token)->assertUnprocessable()->assertJsonPath('code', 'invalid_reset_token');
        $this->assertTrue(password_verify(self::OLD, $user->fresh()->password));
    }

    public function test_token_cannot_be_used_with_a_different_email(): void
    {
        $user = $this->user();
        $other = $this->user();

        $this->reset($other, Password::createToken($user))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'invalid_reset_token');
    }

    public function test_unknown_email_gets_the_same_error_as_a_bad_token(): void
    {
        $user = $this->user();

        $bad = $this->reset($user, 'bad');
        $unknown = $this->postJson('/api/reset-password', [
            'token' => 'bad', 'email' => 'nobody@example.com',
            'password' => self::NEW, 'password_confirmation' => self::NEW,
        ]);

        $this->assertSame($bad->status(), $unknown->status());
        $this->assertSame($bad->json(), $unknown->json());
    }

    public function test_inactive_account_cannot_reset_password(): void
    {
        $user = $this->user();
        $token = Password::createToken($user);
        $user->forceFill(['status' => 'inactive'])->save();

        $this->reset($user, $token)->assertUnprocessable()->assertJsonPath('code', 'invalid_reset_token');
        $this->assertTrue(password_verify(self::OLD, $user->fresh()->password));
    }

    public function test_new_password_is_validated(): void
    {
        $user = $this->user();
        $token = Password::createToken($user);

        $this->reset($user, $token, ['password' => 'weak', 'password_confirmation' => 'weak'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->reset($user, $token, ['password_confirmation' => 'Different!Passw0rd'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->postJson('/api/reset-password', [])
            ->assertUnprocessable()->assertJsonValidationErrors(['token', 'email', 'password']);

        // A failed validation must not consume the token.
        $this->reset($user, $token)->assertOk();
    }
}
