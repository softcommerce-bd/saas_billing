<?php

namespace Tests\Feature\Subscriptions;

use App\Modules\Catalog\Models\CatalogItem;
use App\Modules\Customers\Models\Customer;
use App\Modules\Identity\Models\User;
use App\Modules\Subscriptions\Jobs\Middleware\RequiresBusinessWriteEntitlement;
use App\Modules\Subscriptions\Models\EntitlementOverride;
use App\Modules\Subscriptions\Models\Plan;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Services\EntitlementOverrideService;
use App\Modules\Tenancy\Enums\Role;
use App\Modules\Tenancy\Models\TenantInvitation;
use App\Modules\Tenancy\Support\TenantContext;
use App\Support\Audit\AuditEvent;
use App\Support\Http\ApiException;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Non-negotiable rule: after expiry the company keeps read access and can renew,
 * but every business mutation is rejected with 403 SUBSCRIPTION_EXPIRED.
 */
class ReadOnlyModeTest extends TestCase
{
    private function assertExpired(TestResponse $response): void
    {
        $response->assertStatus(403)
            ->assertJsonPath('error.code', 'SUBSCRIPTION_EXPIRED')
            ->assertJsonPath('error.message', 'Renew your subscription to continue managing your business.')
            ->assertJsonPath('error.details.subscription.mode', 'read_only')
            ->assertJsonPath('error.details.subscription.can_renew', true);
    }

    public function test_active_subscription_allows_full_crud(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->createTenant('active');
        $client = $this->actingInTenant($owner, $tenant);

        $id = $client->postJson('/api/v1/customers', ['type' => 'business', 'name' => 'Acme'])->assertCreated()->json('data.id');
        $client->patchJson("/api/v1/customers/{$id}", ['name' => 'Acme Ltd'])->assertOk()->assertHeader('X-Subscription-Mode', 'full');
        $client->deleteJson("/api/v1/customers/{$id}")->assertNoContent();
    }

    public function test_expired_subscription_can_still_read_everything(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->createTenant('active');
        $customer = $this->inTenant($tenant, fn () => Customer::factory()->create());
        $this->expireSubscription($tenant);
        $client = $this->actingInTenant($owner, $tenant);

        $client->getJson('/api/v1/customers')->assertOk()->assertJsonCount(1, 'data')->assertHeader('X-Subscription-Mode', 'read_only');
        $client->getJson('/api/v1/customers/'.$customer->id)->assertOk();
        $client->getJson('/api/v1/catalog-items')->assertOk();
        $client->getJson('/api/v1/company')->assertOk();
        $client->getJson('/api/v1/members')->assertOk();
        $client->get('/api/v1/customers/export')->assertOk();
        $client->getJson('/api/v1/subscription')->assertOk()->assertJsonPath('data.entitlement.mode', 'read_only');
        $client->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.memberships.0.subscription.can_write', false);
    }

    public function test_expired_subscription_rejects_create_edit_delete(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->createTenant('expired');
        $customer = $this->inTenant($tenant, fn () => Customer::factory()->create(['name' => 'Original']));
        $item = $this->inTenant($tenant, fn () => CatalogItem::factory()->create());
        $client = $this->actingInTenant($owner, $tenant);

        $this->assertExpired($client->postJson('/api/v1/customers', ['type' => 'individual', 'name' => 'New']));
        $this->assertExpired($client->patchJson('/api/v1/customers/'.$customer->id, ['name' => 'Changed']));
        $this->assertExpired($client->deleteJson('/api/v1/customers/'.$customer->id));
        $this->assertExpired($client->postJson('/api/v1/catalog-items', ['type' => 'service', 'name' => 'S', 'unit' => 'hour', 'price' => '100']));
        $this->assertExpired($client->patchJson('/api/v1/catalog-items/'.$item->id, ['name' => 'Changed']));
        $this->assertExpired($client->deleteJson('/api/v1/catalog-items/'.$item->id));
        $this->assertExpired($client->patchJson('/api/v1/company', ['name' => 'Renamed']));
        $this->assertExpired($client->postJson('/api/v1/invitations', ['email' => 'staff@example.com', 'roles' => ['viewer']]));

        $this->assertSame('Original', Customer::acrossTenants()->find($customer->id)->name);
        $this->assertNull(Customer::acrossTenants()->find($customer->id)->deleted_at);
        $this->assertSame(1, Customer::acrossTenants()->where('tenant_id', $tenant->id)->count());
    }

    public function test_validation_never_runs_before_the_read_only_gate(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->createTenant('expired');

        // Invalid payloads are still answered with SUBSCRIPTION_EXPIRED, not a validation error.
        $this->assertExpired($this->actingInTenant($owner, $tenant)->postJson('/api/v1/customers', []));
    }

    public function test_expired_subscription_can_still_open_renewal(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->createTenant('expired');

        $this->actingInTenant($owner, $tenant)
            ->withHeader('Idempotency-Key', 'renewal-order-1')
            ->postJson('/api/v1/subscription/orders', ['plan_id' => Plan::where('code', 'yearly')->first()->id])
            ->assertCreated();
    }

    public function test_never_paid_tenant_is_read_only(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->createTenant('pending_payment');
        $response = $this->actingInTenant($owner, $tenant)->postJson('/api/v1/customers', ['type' => 'individual', 'name' => 'X']);

        $this->assertErrorCode($response, 403, 'SUBSCRIPTION_EXPIRED');
        $response->assertJsonPath('error.details.subscription.reason', 'pending_payment');
    }

    public function test_subscription_expiring_during_active_session(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->createTenant('active');
        Subscription::query()->where('tenant_id', $tenant->id)
            ->update(['expires_at' => now()->addMinutes(10)]);
        $client = $this->actingInTenant($owner, $tenant);

        $client->postJson('/api/v1/customers', ['type' => 'individual', 'name' => 'Before expiry'])->assertCreated();

        // Same logged-in session; time passes the exclusive expiry boundary.
        $this->travel(11)->minutes();

        $client->getJson('/api/v1/customers')->assertOk()->assertHeader('X-Subscription-Mode', 'read_only');
        $this->assertExpired($client->postJson('/api/v1/customers', ['type' => 'individual', 'name' => 'After expiry']));
    }

    public function test_access_ends_exactly_at_exclusive_expiry_even_if_status_says_active(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->createTenant('active');
        $this->freezeSecond();
        $this->expireSubscription($tenant, now()->toImmutable());

        // Stored status is still "active" (no sync job ran) but access is computed from expires_at.
        $this->assertSame('active', Subscription::forTenant($tenant->id)->status->value);
        $this->assertExpired($this->actingInTenant($owner, $tenant)->postJson('/api/v1/customers', ['type' => 'individual', 'name' => 'X']));
    }

    public function test_suspended_tenant_is_read_only_and_cannot_renew(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->createTenant('suspended');
        $client = $this->actingInTenant($owner, $tenant);

        $client->getJson('/api/v1/customers')->assertOk()->assertHeader('X-Subscription-Mode', 'suspended');
        $this->assertErrorCode($client->postJson('/api/v1/customers', ['type' => 'individual', 'name' => 'X']), 403, 'TENANT_SUSPENDED');
        $this->assertErrorCode(
            $client->withHeader('Idempotency-Key', 'suspended-order')->postJson('/api/v1/subscription/orders', ['plan_id' => Plan::first()->id]),
            403,
            'PAYMENT_NOT_ALLOWED',
        );
    }

    public function test_model_guard_blocks_writes_outside_http(): void
    {
        ['tenant' => $tenant] = $this->createTenant('expired');

        try {
            app(TenantContext::class)->runAs($tenant, fn () => Customer::factory()->create());
            $this->fail('Expected the business write guard to block the write.');
        } catch (ApiException $e) {
            $this->assertSame('SUBSCRIPTION_EXPIRED', $e->errorCode->value);
        }

        $this->assertSame(0, Customer::acrossTenants()->where('tenant_id', $tenant->id)->count());
    }

    public function test_background_business_job_is_skipped_after_expiry(): void
    {
        ['tenant' => $tenant] = $this->createTenant('expired');
        $job = new class($tenant->id)
        {
            public bool $ran = false;

            public bool $deleted = false;

            public function __construct(public string $tenantId) {}

            public function delete(): void
            {
                $this->deleted = true;
            }
        };

        (new RequiresBusinessWriteEntitlement)->handle($job, function ($job) {
            $job->ran = true;
        });

        $this->assertFalse($job->ran);
        $this->assertTrue($job->deleted);
        $this->assertTrue(AuditEvent::query()->where('action', 'business_job.skipped_no_entitlement')->where('tenant_id', $tenant->id)->exists());
    }

    public function test_background_business_job_runs_with_active_subscription(): void
    {
        ['tenant' => $tenant] = $this->createTenant('active');
        $ran = false;

        (new RequiresBusinessWriteEntitlement)->handle((object) ['tenantId' => $tenant->id], function () use (&$ran) {
            $ran = true;
        });

        $this->assertTrue($ran);
    }

    public function test_invitation_cannot_be_accepted_after_expiry(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->createTenant('active');
        $this->inTenant($tenant, fn () => TenantInvitation::create([
            'email' => 'staff@example.com', 'roles' => ['viewer'], 'token_hash' => TenantInvitation::hashToken('tok-123456789'),
            'invited_by_user_id' => $owner->id, 'expires_at' => now()->addDays(7),
        ]));
        $this->expireSubscription($tenant);

        $this->assertErrorCode($this->postJson('/api/v1/invitations/register', [
            'token' => 'tok-123456789', 'name' => 'Staff', 'password' => 'staff-pass-123', 'password_confirmation' => 'staff-pass-123',
        ]), 403, 'SUBSCRIPTION_EXPIRED');
        $this->assertFalse(User::query()->where('email', 'staff@example.com')->exists());
    }

    public function test_platform_override_temporarily_restores_writes_and_is_audited(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->createTenant('expired');
        $admin = User::factory()->superAdmin()->create();
        app(EntitlementOverrideService::class)->grant($tenant, $admin->fresh(), 2, 'Gateway outage, ticket #42');
        $client = $this->actingInTenant($owner, $tenant);

        $client->postJson('/api/v1/customers', ['type' => 'individual', 'name' => 'During override'])->assertCreated();
        $this->assertTrue(AuditEvent::query()->where('action', 'entitlement_override.granted')->exists());

        $this->travel(3)->hours();
        $this->assertExpired($client->postJson('/api/v1/customers', ['type' => 'individual', 'name' => 'After override']));
    }

    public function test_override_never_bypasses_suspension(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->createTenant('suspended');
        $admin = User::factory()->superAdmin()->create();
        app(EntitlementOverrideService::class)->grant($tenant, $admin->fresh(), 2, 'Testing suspension precedence');

        $this->assertErrorCode(
            $this->actingInTenant($owner, $tenant)->postJson('/api/v1/customers', ['type' => 'individual', 'name' => 'X']),
            403,
            'TENANT_SUSPENDED',
        );
    }

    public function test_override_requires_super_admin_and_bounded_length(): void
    {
        ['tenant' => $tenant] = $this->createTenant('expired');
        $support = User::factory()->supportAdmin()->create()->fresh();
        $admin = User::factory()->superAdmin()->create()->fresh();

        $this->assertThrows(fn () => app(EntitlementOverrideService::class)->grant($tenant, $support, 2, 'reason here'), ApiException::class);
        $this->assertThrows(fn () => app(EntitlementOverrideService::class)->grant($tenant, $admin, 24 * 30, 'reason here'), ApiException::class);
        $this->assertSame(0, EntitlementOverride::query()->count());
    }

    public function test_expired_staff_without_permission_still_sees_expiry_not_permission_error(): void
    {
        ['tenant' => $tenant] = $this->createTenant('expired');
        $viewer = $this->memberWithRoles($tenant, [Role::Viewer]);

        $this->assertErrorCode(
            $this->actingInTenant($viewer, $tenant)->postJson('/api/v1/customers', ['type' => 'individual', 'name' => 'X']),
            403,
            'SUBSCRIPTION_EXPIRED',
        );
    }
}
