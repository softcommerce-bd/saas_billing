<?php

namespace Tests\Feature\Tenancy;

use App\Modules\Subscriptions\Models\Plan;
use App\Modules\Tenancy\Enums\Role;
use App\Modules\Tenancy\Models\TenantMembership;
use Tests\TestCase;

class PermissionTest extends TestCase
{
    public function test_viewer_can_read_but_not_write_customers(): void
    {
        ['tenant' => $tenant] = $this->createTenant();
        $viewer = $this->memberWithRoles($tenant, [Role::Viewer]);
        $client = $this->actingInTenant($viewer, $tenant);

        $client->getJson('/api/v1/customers')->assertOk();
        $this->assertErrorCode($client->postJson('/api/v1/customers', ['type' => 'individual', 'name' => 'X']), 403, 'FORBIDDEN');
    }

    public function test_collection_executive_cannot_manage_catalog_or_export(): void
    {
        ['tenant' => $tenant] = $this->createTenant();
        $user = $this->memberWithRoles($tenant, [Role::CollectionExecutive]);
        $client = $this->actingInTenant($user, $tenant);

        $client->postJson('/api/v1/catalog-items', ['type' => 'service', 'name' => 'X', 'unit' => 'hour', 'price' => '10'])->assertForbidden();
        $client->getJson('/api/v1/customers/export')->assertForbidden();
        $client->getJson('/api/v1/catalog-items')->assertForbidden();
    }

    public function test_billing_executive_cannot_view_subscription_or_manage_users(): void
    {
        ['tenant' => $tenant] = $this->createTenant();
        $user = $this->memberWithRoles($tenant, [Role::BillingExecutive]);
        $client = $this->actingInTenant($user, $tenant);

        $client->getJson('/api/v1/subscription')->assertForbidden();
        $client->getJson('/api/v1/members')->assertForbidden();
        $client->patchJson('/api/v1/company', ['name' => 'X'])->assertForbidden();
    }

    public function test_company_admin_cannot_buy_subscription(): void
    {
        ['tenant' => $tenant] = $this->createTenant();
        $admin = $this->memberWithRoles($tenant, [Role::Admin]);

        $this->actingInTenant($admin, $tenant)
            ->withHeader('Idempotency-Key', 'order-key-0001')
            ->postJson('/api/v1/subscription/orders', ['plan_id' => Plan::first()->id])
            ->assertForbidden();
    }

    public function test_multiple_roles_combine(): void
    {
        ['tenant' => $tenant] = $this->createTenant();
        $user = $this->memberWithRoles($tenant, [Role::Viewer, Role::BillingExecutive]);

        $this->actingInTenant($user, $tenant)
            ->postJson('/api/v1/customers', ['type' => 'individual', 'name' => 'Combo'])
            ->assertCreated();
    }

    public function test_admin_cannot_escalate_to_owner(): void
    {
        ['tenant' => $tenant] = $this->createTenant();
        $admin = $this->memberWithRoles($tenant, [Role::Admin]);
        $adminMembership = TenantMembership::query()->where('user_id', $admin->id)->first();
        $client = $this->actingInTenant($admin, $tenant);

        $client->patchJson('/api/v1/members/'.$adminMembership->id, ['roles' => ['owner']])->assertForbidden();
        $client->withHeader('Idempotency-Key', 'x')->postJson('/api/v1/invitations', ['email' => 'new@example.com', 'roles' => ['owner']])->assertForbidden();

        $this->assertFalse($adminMembership->fresh()->load('roleRows')->hasRole(Role::Owner));
    }

    public function test_admin_cannot_modify_owner(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->createTenant();
        $admin = $this->memberWithRoles($tenant, [Role::Admin]);
        $ownerMembership = TenantMembership::query()->where('user_id', $owner->id)->first();

        $this->actingInTenant($admin, $tenant)->patchJson('/api/v1/members/'.$ownerMembership->id, ['roles' => ['viewer']])->assertForbidden();
        $this->actingInTenant($admin, $tenant)->deleteJson('/api/v1/members/'.$ownerMembership->id)->assertForbidden();
    }

    public function test_last_owner_cannot_be_removed_or_demoted(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->createTenant();
        $membership = TenantMembership::query()->where('user_id', $owner->id)->first();
        $client = $this->actingInTenant($owner, $tenant);

        $this->assertErrorCode($client->patchJson('/api/v1/members/'.$membership->id, ['roles' => ['admin']]), 409, 'CONFLICT');
        $this->assertErrorCode($client->deleteJson('/api/v1/members/'.$membership->id), 409, 'CONFLICT');
        $this->assertTrue($membership->fresh()->load('roleRows')->hasRole(Role::Owner));
    }

    public function test_owner_can_change_roles_of_staff(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->createTenant();
        $staff = $this->memberWithRoles($tenant, [Role::Viewer]);
        $membership = TenantMembership::query()->where('user_id', $staff->id)->first();

        $this->actingInTenant($owner, $tenant)
            ->patchJson('/api/v1/members/'.$membership->id, ['roles' => ['accounts_manager', 'collection_executive']])
            ->assertOk()
            ->assertJsonPath('data.roles', ['accounts_manager', 'collection_executive']);
    }

    public function test_member_of_other_tenant_cannot_be_modified(): void
    {
        ['tenant' => $a, 'owner' => $ownerA] = $this->createTenant();
        ['tenant' => $b] = $this->createTenant();
        $staffB = $this->memberWithRoles($b, [Role::Viewer]);
        $membershipB = TenantMembership::query()->where('user_id', $staffB->id)->first();

        $this->actingInTenant($ownerA, $a)->patchJson('/api/v1/members/'.$membershipB->id, ['status' => 'disabled'])->assertNotFound();
        $this->assertSame('active', $membershipB->fresh()->status);
    }
}
