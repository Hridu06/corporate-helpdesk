<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Str0ng!Passw0rd';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Jane Customer',
            'email' => 'jane@example.com',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ], $overrides);
    }

    public function test_successful_registration_creates_active_customer_and_sends_verification(): void
    {
        Notification::fake();

        $response = $this->postJson('/api/register', $this->payload());

        $response->assertCreated()
            ->assertJsonPath('user.email', 'jane@example.com')
            ->assertJsonPath('user.roles', ['customer'])
            ->assertJsonPath('user.status', 'active')
            ->assertJsonMissingPath('user.password')
            ->assertJsonMissingPath('token');

        $user = User::where('email', 'jane@example.com')->firstOrFail();
        $this->assertNull($user->email_verified_at);
        $this->assertTrue(password_verify(self::PASSWORD, $user->password));
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_duplicate_email_is_rejected(): void
    {
        User::factory()->create(['email' => 'jane@example.com']);

        $this->postJson('/api/register', $this->payload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_duplicate_email_check_is_case_insensitive(): void
    {
        User::factory()->create(['email' => 'jane@example.com']);

        $this->postJson('/api/register', $this->payload(['email' => 'JANE@Example.com']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_invalid_email_is_rejected(): void
    {
        $this->postJson('/api/register', $this->payload(['email' => 'not-an-email']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_weak_passwords_are_rejected(): void
    {
        foreach (['short1!', 'alllowercase1!', 'NoNumbersHere!', 'NoSymbols1234'] as $weak) {
            $this->postJson('/api/register', $this->payload(['password' => $weak, 'password_confirmation' => $weak]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('password');
        }
    }

    public function test_password_confirmation_mismatch_is_rejected(): void
    {
        $this->postJson('/api/register', $this->payload(['password_confirmation' => 'Different!Passw0rd']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');
    }

    public function test_required_fields_are_validated(): void
    {
        $this->postJson('/api/register', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email', 'password']);
    }

    public function test_privileged_fields_are_ignored(): void
    {
        $this->postJson('/api/register', $this->payload([
            'role' => 'super-admin',
            'roles' => ['super-admin'],
            'is_admin' => true,
            'status' => 'inactive',
            'email_verified_at' => now()->toDateTimeString(),
        ]))->assertCreated();

        $user = User::where('email', 'jane@example.com')->firstOrFail();
        $this->assertSame(['customer'], $user->getRoleNames()->all());
        $this->assertTrue($user->isActive());
        $this->assertNull($user->email_verified_at);
    }
}
