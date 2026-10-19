<?php

namespace Tests\Feature\Customers;

use App\Modules\Customers\Models\Customer;
use App\Support\Audit\AuditEvent;
use Tests\TestCase;

class CustomerCrudTest extends TestCase
{
    public function test_create_customer_normalizes_phone_and_money(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->createTenant();

        $this->actingInTenant($owner, $tenant)->postJson('/api/v1/customers', [
            'type' => 'business',
            'name' => 'করিম স্টোর',
            'company_name' => 'Karim Store',
            'mobile' => '017-1234-5678',
            'email' => 'Karim@Store.COM',
            'opening_balance' => '1500.50',
            'credit_limit' => '50000',
            'payment_terms_days' => 15,
            'preferred_language' => 'bn',
        ])->assertCreated()
            ->assertJsonPath('data.name', 'করিম স্টোর')
            ->assertJsonPath('data.mobile', '+8801712345678')
            ->assertJsonPath('data.mobile_display', '01712-345678')
            ->assertJsonPath('data.email', 'karim@store.com')
            ->assertJsonPath('data.opening_balance_minor', 150050)
            ->assertJsonPath('data.credit_limit_minor', 5000000);

        $this->assertTrue(AuditEvent::query()->where('action', 'customer.created')->where('tenant_id', $tenant->id)->exists());
    }

    public function test_invalid_mobile_and_money_rejected(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->createTenant();

        $response = $this->actingInTenant($owner, $tenant)->postJson('/api/v1/customers', [
            'type' => 'individual', 'name' => 'X', 'mobile' => '0121234567', 'opening_balance' => '10.999',
        ]);

        $this->assertErrorCode($response, 422, 'VALIDATION_FAILED');
        $response->assertJsonStructure(['error' => ['details' => ['fields' => ['mobile', 'opening_balance']]]]);
    }

    public function test_search_filter_sort_and_paginate(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->createTenant();
        $this->inTenant($tenant, function () {
            Customer::factory()->create(['name' => 'Alpha Traders', 'mobile' => '+8801711111111']);
            Customer::factory()->create(['name' => 'Beta Agency', 'status' => 'inactive']);
            Customer::factory()->count(30)->create();
        });
        $client = $this->actingInTenant($owner, $tenant);

        $client->getJson('/api/v1/customers?search=alpha')->assertJsonCount(1, 'data');
        $client->getJson('/api/v1/customers?search=01711111111')->assertJsonCount(1, 'data');
        $client->getJson('/api/v1/customers?status=inactive')->assertJsonCount(1, 'data');
        $client->getJson('/api/v1/customers?per_page=10')->assertJsonCount(10, 'data')->assertJsonPath('meta.total', 32);
        $client->getJson('/api/v1/customers?sort=-name&per_page=100')->assertOk();
        $client->getJson('/api/v1/customers?sort=password')->assertStatus(422);
    }

    public function test_search_treats_wildcards_literally(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->createTenant();
        $this->inTenant($tenant, fn () => Customer::factory()->count(3)->create());

        $this->actingInTenant($owner, $tenant)->getJson('/api/v1/customers?search=%25')->assertJsonCount(0, 'data');
    }

    public function test_update_and_archive(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->createTenant();
        $customer = $this->inTenant($tenant, fn () => Customer::factory()->create());
        $client = $this->actingInTenant($owner, $tenant);

        $client->patchJson('/api/v1/customers/'.$customer->id, ['status' => 'inactive', 'mobile' => null])
            ->assertOk()->assertJsonPath('data.status', 'inactive')->assertJsonPath('data.mobile', null);

        $client->deleteJson('/api/v1/customers/'.$customer->id)->assertNoContent();
        $client->getJson('/api/v1/customers/'.$customer->id)->assertNotFound();
        $this->assertSoftDeleted('customers', ['id' => $customer->id]);
    }

    public function test_export_neutralises_formula_injection(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->createTenant();
        $this->inTenant($tenant, fn () => Customer::factory()->create(['name' => '=HYPERLINK("http://evil")']));

        $csv = $this->actingInTenant($owner, $tenant)->get('/api/v1/customers/export')->streamedContent();

        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertTrue(AuditEvent::query()->where('action', 'customer.exported')->exists());
    }
}
