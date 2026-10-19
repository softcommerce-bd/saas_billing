<?php

namespace Tests\Feature\Catalog;

use Tests\TestCase;

class CatalogItemCrudTest extends TestCase
{
    public function test_create_service_and_product_items(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->createTenant();
        $client = $this->actingInTenant($owner, $tenant);

        $client->postJson('/api/v1/catalog-items', [
            'type' => 'service', 'name' => 'Website maintenance', 'unit' => 'Month', 'price' => '15000',
            'tax_rate_percent' => '15', 'tax_label' => 'VAT', 'default_discount_percent' => '2.5',
        ])->assertCreated()
            ->assertJsonPath('data.price_minor', 1500000)
            ->assertJsonPath('data.unit', 'month')
            ->assertJsonPath('data.tax_rate_bp', 1500)
            ->assertJsonPath('data.default_discount_bp', 250);

        $client->postJson('/api/v1/catalog-items', [
            'type' => 'product', 'name' => 'Rice 50kg bag', 'unit' => 'bag', 'price' => '3200.75', 'sku' => 'RICE-50',
        ])->assertCreated()->assertJsonPath('data.price', '3200.75');
    }

    public function test_sku_unique_per_tenant_case_insensitive(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->createTenant();
        ['tenant' => $other, 'owner' => $otherOwner] = $this->createTenant();
        $payload = ['type' => 'product', 'name' => 'A', 'unit' => 'piece', 'price' => '10', 'sku' => 'ABC-1'];

        $this->actingInTenant($owner, $tenant)->postJson('/api/v1/catalog-items', $payload)->assertCreated();
        $this->assertErrorCode(
            $this->actingInTenant($owner, $tenant)->postJson('/api/v1/catalog-items', ['sku' => 'abc-1'] + $payload),
            422,
            'VALIDATION_FAILED',
        );
        // Another company can use the same SKU.
        $this->actingInTenant($otherOwner, $other)->postJson('/api/v1/catalog-items', $payload)->assertCreated();
    }

    public function test_invalid_price_and_percent_rejected(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->createTenant();

        $response = $this->actingInTenant($owner, $tenant)->postJson('/api/v1/catalog-items', [
            'type' => 'product', 'name' => 'A', 'unit' => 'piece', 'price' => '-5', 'tax_rate_percent' => '150',
        ]);

        $this->assertErrorCode($response, 422, 'VALIDATION_FAILED');
        $response->assertJsonStructure(['error' => ['details' => ['fields' => ['price', 'tax_rate_percent']]]]);
    }

    public function test_filter_by_type_and_active(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->createTenant();
        $client = $this->actingInTenant($owner, $tenant);
        $client->postJson('/api/v1/catalog-items', ['type' => 'service', 'name' => 'S1', 'unit' => 'hour', 'price' => '1']);
        $client->postJson('/api/v1/catalog-items', ['type' => 'product', 'name' => 'P1', 'unit' => 'piece', 'price' => '1', 'is_active' => false]);

        $client->getJson('/api/v1/catalog-items?type=service')->assertJsonCount(1, 'data');
        $client->getJson('/api/v1/catalog-items?active=1')->assertJsonCount(1, 'data');
    }
}
