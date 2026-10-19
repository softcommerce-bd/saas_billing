<?php

namespace Tests\Feature\Tenancy;

use App\Modules\Catalog\Models\CatalogItem;
use App\Modules\Customers\Models\Customer;
use App\Modules\Identity\Models\User;
use App\Modules\Subscriptions\Enums\OrderStatus;
use App\Modules\Subscriptions\Enums\PaymentStatus;
use App\Modules\Subscriptions\Models\Plan;
use App\Modules\Subscriptions\Models\SubscriptionOrder;
use App\Modules\Subscriptions\Models\SubscriptionPayment;
use App\Modules\Tenancy\Enums\Role;
use App\Modules\Tenancy\Support\MissingTenantContext;
use App\Modules\Tenancy\Support\TenantContext;
use Tests\TestCase;

/**
 * Company A must never read or change Company B's data, whatever ids it sends.
 */
class TenantIsolationTest extends TestCase
{
    private array $a;

    private array $b;

    private Customer $customerB;

    private CatalogItem $itemB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = $this->createTenant('active', 'Company A');
        $this->b = $this->createTenant('active', 'Company B');

        $this->inTenant($this->a['tenant'], fn () => Customer::factory()->count(3)->create());
        $this->customerB = $this->inTenant($this->b['tenant'], fn () => Customer::factory()->create(['name' => 'Secret B Customer']));
        $this->itemB = $this->inTenant($this->b['tenant'], fn () => CatalogItem::factory()->create());
    }

    public function test_listing_only_returns_own_customers(): void
    {
        $response = $this->actingInTenant($this->a['owner'], $this->a['tenant'])->getJson('/api/v1/customers');

        $response->assertOk()->assertJsonCount(3, 'data');
        $this->assertNotContains('Secret B Customer', array_column($response->json('data'), 'name'));
    }

    public function test_cannot_read_other_tenants_customer_by_id(): void
    {
        $this->assertErrorCode(
            $this->actingInTenant($this->a['owner'], $this->a['tenant'])->getJson('/api/v1/customers/'.$this->customerB->id),
            404,
            'NOT_FOUND',
        );
    }

    public function test_cannot_update_or_delete_other_tenants_customer(): void
    {
        $client = $this->actingInTenant($this->a['owner'], $this->a['tenant']);

        $client->patchJson('/api/v1/customers/'.$this->customerB->id, ['name' => 'Hacked'])->assertNotFound();
        $client->deleteJson('/api/v1/customers/'.$this->customerB->id)->assertNotFound();

        $this->assertSame('Secret B Customer', Customer::acrossTenants()->find($this->customerB->id)->name);
        $this->assertNull(Customer::acrossTenants()->find($this->customerB->id)->deleted_at);
    }

    public function test_cannot_access_other_tenants_catalog_item(): void
    {
        $client = $this->actingInTenant($this->a['owner'], $this->a['tenant']);

        $client->getJson('/api/v1/catalog-items/'.$this->itemB->id)->assertNotFound();
        $client->patchJson('/api/v1/catalog-items/'.$this->itemB->id, ['name' => 'x'])->assertNotFound();
    }

    public function test_switching_x_tenant_header_to_non_member_company_is_rejected(): void
    {
        $this->assertErrorCode(
            $this->actingInTenant($this->a['owner'], $this->b['tenant'])->getJson('/api/v1/customers'),
            404,
            'TENANT_NOT_FOUND',
        );
    }

    public function test_nonexistent_and_malformed_tenant_ids_look_identical(): void
    {
        $client = $this->actingInTenant($this->a['owner'], $this->a['tenant']);

        $unknown = $client->withHeader('X-Tenant', '01JZZZZZZZZZZZZZZZZZZZZZZZ')->getJson('/api/v1/customers');
        $malformed = $client->withHeader('X-Tenant', "' OR 1=1 --")->getJson('/api/v1/customers');

        $this->assertErrorCode($unknown, 404, 'TENANT_NOT_FOUND');
        $this->assertErrorCode($malformed, 404, 'TENANT_NOT_FOUND');
    }

    public function test_missing_tenant_header(): void
    {
        $this->actingAs($this->a['owner'], 'web');

        $this->assertErrorCode($this->getJson('/api/v1/customers'), 400, 'TENANT_REQUIRED');
    }

    public function test_disabled_membership_loses_access(): void
    {
        $user = User::factory()->create();
        $this->addMember($this->a['tenant'], $user, [Role::Viewer], 'disabled');

        $this->actingInTenant($user, $this->a['tenant'])->getJson('/api/v1/customers')->assertNotFound();
    }

    public function test_search_does_not_leak_across_tenants(): void
    {
        $this->actingInTenant($this->a['owner'], $this->a['tenant'])
            ->getJson('/api/v1/customers?search=Secret')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_export_contains_only_own_tenant_rows(): void
    {
        $response = $this->actingInTenant($this->a['owner'], $this->a['tenant'])->get('/api/v1/customers/export');

        $response->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringNotContainsString('Secret B Customer', $csv);
        $this->assertSame(4, substr_count(trim($csv), "\n") + 1); // header + 3 rows
    }

    public function test_cannot_view_other_tenants_subscription_payment(): void
    {
        $plan = Plan::query()->first();
        $paymentB = $this->inTenant($this->b['tenant'], function () use ($plan) {
            $order = SubscriptionOrder::create([
                'plan_id' => $plan->id, 'plan_code' => 'monthly', 'duration_months' => 1, 'amount_minor' => 100000,
                'currency' => 'BDT', 'status' => OrderStatus::Pending, 'merchant_invoice_number' => 'SUB-TEST-B',
            ]);

            return SubscriptionPayment::create([
                'order_id' => $order->id, 'gateway' => 'bkash', 'environment' => 'sandbox',
                'status' => PaymentStatus::Initiated, 'amount_minor' => 100000, 'currency' => 'BDT',
            ]);
        });

        $this->actingInTenant($this->a['owner'], $this->a['tenant'])
            ->getJson('/api/v1/subscription/payments/'.$paymentB->id)
            ->assertNotFound();
    }

    public function test_tenant_scoped_query_without_context_fails_closed(): void
    {
        app(TenantContext::class)->clear();

        $this->expectException(MissingTenantContext::class);
        Customer::query()->count();
    }

    public function test_cannot_create_record_for_another_tenant_inside_context(): void
    {
        $this->expectException(\LogicException::class);

        $this->inTenant($this->a['tenant'], fn () => Customer::factory()->create(['tenant_id' => $this->b['tenant']->id]));
    }

    public function test_tenant_id_cannot_be_reassigned(): void
    {
        $this->expectException(\LogicException::class);

        $this->inTenant($this->b['tenant'], function () {
            $this->customerB->tenant_id = $this->a['tenant']->id;
            $this->customerB->save();
        });
    }

    public function test_client_supplied_tenant_id_in_body_is_ignored(): void
    {
        $this->actingInTenant($this->a['owner'], $this->a['tenant'])
            ->postJson('/api/v1/customers', ['type' => 'individual', 'name' => 'Injected', 'tenant_id' => $this->b['tenant']->id])
            ->assertCreated();

        $this->assertSame($this->a['tenant']->id, Customer::acrossTenants()->where('name', 'Injected')->first()->tenant_id);
    }

    public function test_platform_super_admin_without_membership_cannot_read_tenant_data(): void
    {
        $admin = User::factory()->superAdmin()->create();

        $this->actingInTenant($admin, $this->b['tenant'])->getJson('/api/v1/customers')->assertNotFound();
    }
}
