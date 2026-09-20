<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    private function signedUrl(User $user, ?string $hash = null, $expiry = null): string
    {
        return URL::temporarySignedRoute(
            'verification.verify',
            $expiry ?? now()->addMinutes(60),
            ['id' => $user->id, 'hash' => $hash ?? sha1($user->getEmailForVerification())],
        );
    }

    public function test_valid_link_verifies_email_and_redirects_to_frontend(): void
    {
        $user = User::factory()->unverified()->create();

        $this->get($this->signedUrl($user))
            ->assertRedirect(config('helpdesk.frontend_url').'/verify-email?status=success');

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_tampered_hash_is_rejected(): void
    {
        $user = User::factory()->unverified()->create();

        $this->get($this->signedUrl($user, sha1('other@example.com')))
            ->assertRedirect(config('helpdesk.frontend_url').'/verify-email?status=invalid');

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_expired_link_is_rejected(): void
    {
        $user = User::factory()->unverified()->create();

        $this->get($this->signedUrl($user, null, now()->subMinute()))
            ->assertRedirect(config('helpdesk.frontend_url').'/verify-email?status=invalid');

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_unsigned_link_is_rejected(): void
    {
        $user = User::factory()->unverified()->create();

        $this->get("/api/email/verify/{$user->id}/".sha1($user->email))
            ->assertRedirect(config('helpdesk.frontend_url').'/verify-email?status=invalid');

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_resend_sends_notification_only_for_unverified_active_users(): void
    {
        Notification::fake();
        $unverified = User::factory()->unverified()->create();
        $verified = User::factory()->create();

        foreach ([$unverified->email, $verified->email, 'nobody@example.com'] as $email) {
            $this->app['cache']->flush();
            $this->postJson('/api/email/verification-notification', ['email' => $email])
                ->assertStatus(202)
                ->assertJsonPath('message', 'If that account exists and is not yet verified, a new verification link has been sent.');
        }

        Notification::assertSentTo($unverified, VerifyEmail::class);
        Notification::assertNotSentTo($verified, VerifyEmail::class);
        Notification::assertCount(1);
    }

    public function test_resend_validates_email_and_is_rate_limited(): void
    {
        $this->postJson('/api/email/verification-notification', [])->assertUnprocessable();

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/email/verification-notification', ['email' => 'a@example.com'])->assertStatus(202);
        }
        $this->postJson('/api/email/verification-notification', ['email' => 'a@example.com'])->assertStatus(429);
    }
}
