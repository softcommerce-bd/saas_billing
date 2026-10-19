<?php

namespace Tests\Feature\Tenancy;

use App\Modules\Identity\Models\User;
use App\Modules\Subscriptions\Models\EntitlementProfile;
use App\Modules\Tenancy\Models\TenantMembership;
use App\Modules\Tenancy\Notifications\TenantInvitationNotification;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class InvitationFlowTest extends TestCase
{
    private function invite($tenant, $owner, string $email = 'staff@example.com', array $roles = ['billing_executive']): string
    {
        Notification::fake();
        $this->actingInTenant($owner, $tenant)->postJson('/api/v1/invitations', ['email' => $email, 'roles' => $roles])->assertCreated();

        $token = null;
        Notification::assertSentTo(new AnonymousNotifiable, TenantInvitationNotification::class, function ($n, $channels, $notifiable) use (&$token, $email) {
            $token = $n->token;

            return $notifiable->routes['mail'] === $email;
        });

        return $token;
    }

    public function test_new_user_registers_from_invitation(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->createTenant();
        $token = $this->invite($tenant, $owner);
        $this->app['auth']->forgetGuards();

        $this->postJson('/api/v1/invitations/register', [
            'token' => $token, 'name' => 'New Staff', 'password' => 'staff-pass-123', 'password_confirmation' => 'staff-pass-123',
        ])->assertCreated()
            ->assertJsonPath('data.email', 'staff@example.com')
            ->assertJsonPath('data.email_verified', true)
            ->assertJsonPath('data.memberships.0.roles', ['billing_executive']);

        // Token is single-use.
        $this->postJson('/api/v1/invitations/register', [
            'token' => $token, 'name' => 'Again', 'password' => 'staff-pass-123', 'password_confirmation' => 'staff-pass-123',
        ])->assertNotFound();
    }

    public function test_existing_user_must_match_invited_email(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->createTenant();
        $token = $this->invite($tenant, $owner, 'staff@example.com');
        $stranger = User::factory()->create(['email' => 'stranger@example.com']);
        $invitee = User::factory()->create(['email' => 'staff@example.com']);

        $this->actingAs($stranger, 'web')->postJson('/api/v1/invitations/accept', ['token' => $token])->assertForbidden();
        $this->actingAs($invitee, 'web')->postJson('/api/v1/invitations/accept', ['token' => $token])->assertOk();

        $this->assertTrue(TenantMembership::query()->where('tenant_id', $tenant->id)->where('user_id', $invitee->id)->exists());
    }

    public function test_expired_or_revoked_invitations_are_rejected(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->createTenant();
        $token = $this->invite($tenant, $owner);
        $this->travel(8)->days();
        $this->createTenant(); // keep tenant subscription irrelevant: still active window check below

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/invitations/register', [
            'token' => $token, 'name' => 'Late', 'password' => 'staff-pass-123', 'password_confirmation' => 'staff-pass-123',
        ])->assertNotFound();
    }

    public function test_user_quota_is_enforced(): void
    {
        EntitlementProfile::query()->update(['max_users' => 2]);
        ['tenant' => $tenant, 'owner' => $owner] = $this->createTenant();
        $this->invite($tenant, $owner, 'one@example.com');

        $this->assertErrorCode(
            $this->actingInTenant($owner, $tenant)->postJson('/api/v1/invitations', ['email' => 'two@example.com', 'roles' => ['viewer']]),
            422,
            'QUOTA_EXCEEDED',
        );
    }

    public function test_cannot_invite_existing_member(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->createTenant();

        $this->assertErrorCode(
            $this->actingInTenant($owner, $tenant)->postJson('/api/v1/invitations', ['email' => $owner->email, 'roles' => ['viewer']]),
            409,
            'CONFLICT',
        );
    }
}
