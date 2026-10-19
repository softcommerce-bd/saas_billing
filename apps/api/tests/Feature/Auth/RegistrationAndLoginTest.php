<?php

namespace Tests\Feature\Auth;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Notifications\ResetPasswordNotification;
use App\Modules\Identity\Notifications\VerifyEmailNotification;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Models\SubscriptionPeriod;
use App\Modules\Tenancy\Enums\Role;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class RegistrationAndLoginTest extends TestCase
{
    private function register(array $overrides = []): TestResponse
    {
        return $this->postJson('/api/v1/auth/register', array_merge([
            'name' => 'Rahim Uddin',
            'email' => 'Rahim@Example.com',
            'password' => 'secret-pass-123',
            'password_confirmation' => 'secret-pass-123',
            'company_name' => 'Rahim Traders',
        ], $overrides));
    }

    public function test_registration_creates_company_owner_and_trial(): void
    {
        Notification::fake();

        $response = $this->register();

        $response->assertCreated()
            ->assertJsonPath('data.email', 'rahim@example.com')
            ->assertJsonPath('data.email_verified', false)
            ->assertJsonPath('data.memberships.0.roles', ['owner'])
            ->assertJsonPath('data.memberships.0.subscription.mode', 'full')
            ->assertJsonPath('data.memberships.0.subscription.is_trial', true);

        $tenant = Tenant::query()->firstOrFail();
        $subscription = Subscription::forTenant($tenant->id);
        $this->assertSame('Rahim Traders', $tenant->name);
        $this->assertTrue($subscription->is_trial);
        $this->assertSame(1, SubscriptionPeriod::query()->where('source', 'trial')->count());
        $this->assertAuthenticated();

        Notification::assertSentTo(User::first(), VerifyEmailNotification::class);
    }

    public function test_registration_without_trial_starts_read_only(): void
    {
        config(['subscriptions.trial_days' => 0]);

        $this->register()->assertCreated()
            ->assertJsonPath('data.memberships.0.subscription.mode', 'read_only')
            ->assertJsonPath('data.memberships.0.subscription.reason', 'pending_payment');
    }

    public function test_registration_validates_input_and_duplicate_email(): void
    {
        User::factory()->create(['email' => 'rahim@example.com']);

        $response = $this->register(['password_confirmation' => 'different']);

        $this->assertErrorCode($response, 422, 'VALIDATION_FAILED');
        $response->assertJsonStructure(['error' => ['details' => ['fields' => ['email', 'password']]]]);
    }

    public function test_weak_password_rejected(): void
    {
        $this->assertErrorCode($this->register(['password' => 'short', 'password_confirmation' => 'short']), 422, 'VALIDATION_FAILED');
    }

    public function test_login_with_valid_credentials(): void
    {
        $user = User::factory()->create(['email' => 'karim@example.com']);

        $this->postJson('/api/v1/auth/login', ['email' => 'KARIM@example.com', 'password' => 'password123'])
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);

        $this->assertAuthenticatedAs($user);
    }

    public function test_login_failure_is_generic_for_unknown_and_wrong_password(): void
    {
        User::factory()->create(['email' => 'karim@example.com']);

        $wrong = $this->postJson('/api/v1/auth/login', ['email' => 'karim@example.com', 'password' => 'nope-nope-1']);
        $unknown = $this->postJson('/api/v1/auth/login', ['email' => 'ghost@example.com', 'password' => 'nope-nope-1']);

        $this->assertErrorCode($wrong, 422, 'INVALID_CREDENTIALS');
        $this->assertErrorCode($unknown, 422, 'INVALID_CREDENTIALS');
        $this->assertSame($wrong->json('error.message'), $unknown->json('error.message'));
        $this->assertGuest();
    }

    public function test_login_brute_force_is_throttled(): void
    {
        User::factory()->create(['email' => 'karim@example.com']);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'karim@example.com', 'password' => 'wrong-pass-1']);
        }

        // Even the correct password is refused while locked out.
        $response = $this->postJson('/api/v1/auth/login', ['email' => 'karim@example.com', 'password' => 'password123']);
        $this->assertErrorCode($response, 429, 'TOO_MANY_REQUESTS');
    }

    public function test_disabled_user_cannot_login(): void
    {
        $user = User::factory()->create(['email' => 'karim@example.com']);
        $user->forceFill(['is_active' => false])->save();

        $this->assertErrorCode(
            $this->postJson('/api/v1/auth/login', ['email' => 'karim@example.com', 'password' => 'password123']),
            403,
            'ACCOUNT_DISABLED',
        );
    }

    public function test_me_requires_authentication(): void
    {
        $this->assertErrorCode($this->getJson('/api/v1/auth/me'), 401, 'UNAUTHENTICATED');
    }

    public function test_email_verification_link(): void
    {
        $user = User::factory()->unverified()->create();
        $url = URL::temporarySignedRoute('auth.verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1($user->email)]);

        $this->get($url)->assertRedirectContains('verified=1');
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_email_verification_rejects_tampered_link(): void
    {
        $user = User::factory()->unverified()->create();
        $url = URL::temporarySignedRoute('auth.verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1($user->email)]);

        $this->get(str_replace(sha1($user->email), sha1('other@example.com'), $url))->assertForbidden();
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_unverified_user_cannot_access_tenant_routes(): void
    {
        ['tenant' => $tenant] = $this->createTenant();
        $user = User::factory()->unverified()->create();
        $this->addMember($tenant, $user, [Role::Owner]);

        $this->assertErrorCode($this->actingInTenant($user, $tenant)->getJson('/api/v1/customers'), 403, 'EMAIL_NOT_VERIFIED');
    }

    public function test_password_reset_flow(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'karim@example.com']);

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'karim@example.com'])->assertOk();
        // Same response for unknown addresses (no account enumeration).
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'ghost@example.com'])->assertOk();

        $token = null;
        Notification::assertSentTo($user, ResetPasswordNotification::class, function ($n) use (&$token) {
            $token = $n->token;

            return true;
        });

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token,
            'email' => 'karim@example.com',
            'password' => 'brand-new-pass-1',
            'password_confirmation' => 'brand-new-pass-1',
        ])->assertOk();

        $this->postJson('/api/v1/auth/login', ['email' => 'karim@example.com', 'password' => 'brand-new-pass-1'])->assertOk();
    }

    public function test_password_reset_with_invalid_token_fails(): void
    {
        User::factory()->create(['email' => 'karim@example.com']);

        $this->assertErrorCode($this->postJson('/api/v1/auth/reset-password', [
            'token' => 'invalid',
            'email' => 'karim@example.com',
            'password' => 'brand-new-pass-1',
            'password_confirmation' => 'brand-new-pass-1',
        ]), 422, 'VALIDATION_FAILED');
    }

    public function test_reset_token_is_single_use(): void
    {
        $user = User::factory()->create(['email' => 'karim@example.com']);
        $token = Password::createToken($user);
        $payload = ['token' => $token, 'email' => 'karim@example.com', 'password' => 'brand-new-pass-1', 'password_confirmation' => 'brand-new-pass-1'];

        $this->postJson('/api/v1/auth/reset-password', $payload)->assertOk();
        $this->assertErrorCode($this->postJson('/api/v1/auth/reset-password', $payload), 422, 'VALIDATION_FAILED');
    }
}
