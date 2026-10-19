<?php

namespace Tests\Feature\Subscriptions;

use App\Modules\Identity\Models\User;
use App\Modules\Subscriptions\Gateway\RefundResult;
use App\Modules\Subscriptions\Gateway\SubscriptionPaymentGateway;
use App\Modules\Subscriptions\Models\Plan;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Models\SubscriptionPayment;
use App\Modules\Subscriptions\Models\SubscriptionPeriod;
use Illuminate\Support\Facades\Notification;
use Tests\Fakes\FakeSubscriptionGateway;
use Tests\TestCase;

class RefundAndDisputeTest extends TestCase
{
    private FakeSubscriptionGateway $gateway;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->gateway = new FakeSubscriptionGateway;
        $this->app->instance(SubscriptionPaymentGateway::class, $this->gateway);
        $this->admin = User::factory()->superAdmin()->create();
    }

    private function paidPayment(): array
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->createTenant('expired');
        $client = $this->actingInTenant($owner, $tenant);
        $orderId = $client->withHeader('Idempotency-Key', 'order-refund-1')
            ->postJson('/api/v1/subscription/orders', ['plan_id' => Plan::where('code', 'monthly')->first()->id])->json('data.id');
        $paymentId = $client->withHeader('Idempotency-Key', 'checkout-refund-1')
            ->postJson("/api/v1/subscription/orders/{$orderId}/checkout")->json('data.payment_id');
        $gid = $this->gateway->lastPaymentId();
        $this->gateway->executeQueue[$gid] = [$this->gateway->completed($gid)];
        $this->get("/api/v1/subscription-payments/callback/bkash?paymentID={$gid}&status=success");

        return ['tenant' => $tenant, 'payment_id' => $paymentId];
    }

    public function test_full_refund_revokes_the_period_and_returns_to_read_only(): void
    {
        ['tenant' => $tenant, 'payment_id' => $paymentId] = $this->paidPayment();
        $this->assertTrue(Subscription::forTenant($tenant->id)->expires_at->isFuture());

        $this->actingAs($this->admin, 'web')
            ->postJson("/api/v1/admin/subscription-payments/{$paymentId}/refund", ['amount' => '1000.00', 'reason' => 'Customer request'])
            ->assertOk()
            ->assertJsonPath('data.status', 'refunded');

        $this->assertFalse(Subscription::forTenant($tenant->id)->expires_at->isFuture());
        $this->assertNotNull(SubscriptionPeriod::query()->where('payment_id', $paymentId)->first()->revoked_at);
    }

    public function test_partial_refund_flags_for_review_without_changing_validity(): void
    {
        ['tenant' => $tenant, 'payment_id' => $paymentId] = $this->paidPayment();
        $expiry = Subscription::forTenant($tenant->id)->expires_at;

        $this->actingAs($this->admin, 'web')
            ->postJson("/api/v1/admin/subscription-payments/{$paymentId}/refund", ['amount' => '250', 'reason' => 'Goodwill partial'])
            ->assertOk()
            ->assertJsonPath('data.status', 'partially_refunded')
            ->assertJsonPath('data.requires_review', true);

        $this->assertTrue(Subscription::forTenant($tenant->id)->expires_at->equalTo($expiry));
    }

    public function test_refund_cannot_exceed_paid_amount(): void
    {
        ['payment_id' => $paymentId] = $this->paidPayment();

        $this->assertErrorCode($this->actingAs($this->admin, 'web')
            ->postJson("/api/v1/admin/subscription-payments/{$paymentId}/refund", ['amount' => '1000.01', 'reason' => 'Too much']), 422, 'VALIDATION_FAILED');
    }

    public function test_unconfirmed_gateway_refund_changes_nothing(): void
    {
        ['tenant' => $tenant, 'payment_id' => $paymentId] = $this->paidPayment();
        $this->gateway->refundResult = new RefundResult(false, null, null, '2071', 'Refund failed');

        $this->assertErrorCode($this->actingAs($this->admin, 'web')
            ->postJson("/api/v1/admin/subscription-payments/{$paymentId}/refund", ['amount' => '1000', 'reason' => 'Customer request']), 409, 'CONFLICT');

        $this->assertSame('completed', SubscriptionPayment::acrossTenants()->find($paymentId)->status->value);
        $this->assertTrue(Subscription::forTenant($tenant->id)->expires_at->isFuture());
    }

    public function test_refund_requires_super_admin(): void
    {
        ['payment_id' => $paymentId, 'tenant' => $tenant] = $this->paidPayment();
        $support = User::factory()->supportAdmin()->create();
        $owner = $tenant->memberships()->first()->user;

        $this->actingAs($support, 'web')->postJson("/api/v1/admin/subscription-payments/{$paymentId}/refund", ['amount' => '1', 'reason' => 'hello there'])->assertForbidden();
        $this->actingAs($owner, 'web')->postJson("/api/v1/admin/subscription-payments/{$paymentId}/refund", ['amount' => '1', 'reason' => 'hello there'])->assertForbidden();
    }

    public function test_dispute_flags_payment_without_changing_validity(): void
    {
        ['tenant' => $tenant, 'payment_id' => $paymentId] = $this->paidPayment();
        $expiry = Subscription::forTenant($tenant->id)->expires_at;

        $this->actingAs($this->admin, 'web')
            ->postJson("/api/v1/admin/subscription-payments/{$paymentId}/dispute", ['note' => 'Customer opened a dispute with bKash'])
            ->assertOk()
            ->assertJsonPath('data.requires_review', true);

        $this->assertNotNull(SubscriptionPayment::acrossTenants()->find($paymentId)->disputed_at);
        $this->assertTrue(Subscription::forTenant($tenant->id)->expires_at->equalTo($expiry));
    }
}
