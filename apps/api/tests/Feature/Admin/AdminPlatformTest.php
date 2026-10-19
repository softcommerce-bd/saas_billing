<?php

namespace Tests\Feature\Admin;

use App\Modules\Identity\Models\User;
use App\Modules\Subscriptions\Models\Plan;
use App\Support\Audit\AuditEvent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminPlatformTest extends TestCase
{
    public function test_regular_users_and_support_staff_cannot_access_admin(): void
    {
        ['owner' => $owner] = $this->createTenant();
        $support = User::factory()->supportAdmin()->create();

        $this->actingAs($owner, 'web')->getJson('/api/v1/admin/tenants')->assertForbidden();
        $this->actingAs($support, 'web')->patchJson('/api/v1/admin/plans/'.Plan::first()->id, ['price' => '1'])->assertForbidden();
    }

    public function test_super_admin_updates_price_with_audit_trail(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $plan = Plan::query()->where('code', 'yearly')->first();

        $this->actingAs($admin, 'web')
            ->patchJson('/api/v1/admin/plans/'.$plan->id, ['price' => '9,999.50'])
            ->assertStatus(422); // commas are not accepted by the API

        $this->actingAs($admin, 'web')
            ->patchJson('/api/v1/admin/plans/'.$plan->id, ['price' => '9999.50'])
            ->assertOk()
            ->assertJsonPath('data.price_minor', 999950)
            ->assertJsonPath('data.monthly_equivalent_minor', 83329);

        $event = AuditEvent::query()->where('action', 'plan.updated')->first();
        $this->assertSame($admin->id, $event->actor_user_id);
        $this->assertSame(999950, $event->metadata['after']['price_minor']);
    }

    public function test_public_plan_list_hides_inactive_plans(): void
    {
        Plan::query()->where('code', 'quarterly')->update(['is_active' => false]);

        $codes = array_column($this->getJson('/api/v1/plans')->assertOk()->json('data'), 'code');

        $this->assertSame(['monthly', 'half_yearly', 'yearly'], $codes);
    }

    public function test_admin_tenant_list_shows_subscription_state_without_business_data(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $this->createTenant('active', 'Alpha');
        $this->createTenant('expired', 'Beta');

        $response = $this->actingAs($admin, 'web')->getJson('/api/v1/admin/tenants?state=expired')->assertOk();

        $this->assertSame(['Beta'], array_column($response->json('data'), 'name'));
        $this->assertArrayNotHasKey('customers', $response->json('data.0'));
    }

    public function test_suspend_and_unsuspend_are_audited(): void
    {
        $admin = User::factory()->superAdmin()->create();
        ['tenant' => $tenant, 'owner' => $owner] = $this->createTenant('active');

        $this->actingAs($admin, 'web')->postJson("/api/v1/admin/tenants/{$tenant->id}/suspend", ['reason' => 'Abuse report #7'])->assertOk();
        $this->assertErrorCode($this->actingInTenant($owner, $tenant)->postJson('/api/v1/customers', ['type' => 'individual', 'name' => 'X']), 403, 'TENANT_SUSPENDED');

        $this->actingAs($admin, 'web')->postJson("/api/v1/admin/tenants/{$tenant->id}/unsuspend", ['reason' => 'Resolved #7'])->assertOk();
        $this->actingInTenant($owner, $tenant)->postJson('/api/v1/customers', ['type' => 'individual', 'name' => 'X'])->assertCreated();

        $this->assertSame(2, AuditEvent::query()->whereIn('action', ['tenant.suspended', 'tenant.unsuspended'])->count());
    }

    public function test_audit_events_are_append_only_in_the_database(): void
    {
        AuditEvent::create(['actor_type' => 'system', 'action' => 'test.event']);

        $this->expectException(QueryException::class);
        DB::table('audit_events')->update(['action' => 'tampered']);
    }
}
