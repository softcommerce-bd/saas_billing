<?php

namespace Tests\Concerns;

use App\Modules\Identity\Models\User;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\Plan;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Services\BusinessWriteGuard;
use App\Modules\Subscriptions\Services\SubscriptionActivationService;
use App\Modules\Tenancy\Enums\Role;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantMembership;
use App\Modules\Tenancy\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;

trait InteractsWithTenants
{
    /**
     * Create a tenant with an owner and a subscription in the requested state.
     *
     * @param  'active'|'trial'|'expired'|'pending_payment'|'suspended'  $state
     * @return array{tenant: Tenant, owner: User}
     */
    protected function createTenant(string $state = 'active', ?string $name = null): array
    {
        $tenant = Tenant::factory()->create($name ? ['name' => $name] : []);
        $owner = User::factory()->create();
        $this->addMember($tenant, $owner, [Role::Owner]);

        app(SubscriptionActivationService::class)->initialize($tenant, 0);
        $subscription = Subscription::forTenant($tenant->id);
        $monthly = Plan::query()->where('code', 'monthly')->first();

        match ($state) {
            'active' => $subscription->update(['status' => SubscriptionStatus::Active, 'plan_id' => $monthly->id, 'starts_at' => now()->subDays(5), 'expires_at' => now()->addDays(25)->startOfDay(), 'anchor_day' => now()->day]),
            'trial' => $subscription->update(['status' => SubscriptionStatus::Active, 'is_trial' => true, 'starts_at' => now(), 'expires_at' => now()->addDays(14)->startOfDay()]),
            'expired' => $subscription->update(['status' => SubscriptionStatus::Active, 'plan_id' => $monthly->id, 'starts_at' => now()->subDays(40), 'expires_at' => now()->subDays(2)->startOfDay()]),
            'suspended' => (function () use ($tenant, $subscription, $monthly) {
                $subscription->update(['status' => SubscriptionStatus::Active, 'plan_id' => $monthly->id, 'expires_at' => now()->addDays(20)]);
                $tenant->forceFill(['suspended_at' => now(), 'suspension_reason' => 'Test suspension'])->save();
            })(),
            'pending_payment' => null,
        };

        return ['tenant' => $tenant->refresh(), 'owner' => $owner];
    }

    /**
     * @param  list<Role>  $roles
     */
    protected function addMember(Tenant $tenant, User $user, array $roles, string $status = 'active'): TenantMembership
    {
        $membership = TenantMembership::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'status' => $status,
            'joined_at' => now(),
        ]);
        $membership->syncRoles($roles);

        return $membership;
    }

    /**
     * @param  list<Role>  $roles
     */
    protected function memberWithRoles(Tenant $tenant, array $roles): User
    {
        $user = User::factory()->create();
        $this->addMember($tenant, $user, $roles);

        return $user;
    }

    protected function actingInTenant(User $user, Tenant $tenant): static
    {
        $this->actingAs($user, 'web');

        return $this->withHeader('X-Tenant', $tenant->id);
    }

    protected function expireSubscription(Tenant $tenant, ?CarbonImmutable $at = null): void
    {
        Subscription::query()->where('tenant_id', $tenant->id)->update(['expires_at' => $at ?? now()->subMinute()]);
    }

    /**
     * Create tenant-owned records regardless of entitlement (test fixtures only).
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    protected function inTenant(Tenant $tenant, callable $callback): mixed
    {
        return app(BusinessWriteGuard::class)->bypass(
            fn () => app(TenantContext::class)->runAs($tenant, $callback),
        );
    }

    protected function assertErrorCode(TestResponse $response, int $status, string $code): void
    {
        $response->assertStatus($status)->assertJsonPath('error.code', $code);
    }
}
