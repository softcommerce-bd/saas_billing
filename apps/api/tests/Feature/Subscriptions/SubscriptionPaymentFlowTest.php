<?php

namespace Tests\Feature\Subscriptions;

use App\Modules\Identity\Models\User;
use App\Modules\Subscriptions\Gateway\GatewayPaymentStatus;
use App\Modules\Subscriptions\Gateway\SubscriptionPaymentGateway;
use App\Modules\Subscriptions\Models\Plan;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Models\SubscriptionOrder;
use App\Modules\Subscriptions\Models\SubscriptionPayment;
use App\Modules\Subscriptions\Models\SubscriptionPeriod;
use App\Modules\Subscriptions\Notifications\SubscriptionActivatedNotification;
use App\Modules\Subscriptions\Services\CalendarPeriod;
use App\Modules\Subscriptions\Services\SubscriptionActivationService;
use App\Modules\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\Fakes\FakeSubscriptionGateway;
use Tests\TestCase;

/**
 * End-to-end subscription purchase through the (faked) hosted checkout.
 */
class SubscriptionPaymentFlowTest extends TestCase
{
    private FakeSubscriptionGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gateway = new FakeSubscriptionGateway;
        $this->app->instance(SubscriptionPaymentGateway::class, $this->gateway);
        Notification::fake();
    }

    /**
     * @return array{payment_id: string, gateway_payment_id: string, order_id: string}
     */
    private function startCheckout(Tenant $tenant, $owner, string $planCode = 'monthly', string $key = 'k'): array
    {
        $plan = Plan::query()->where('code', $planCode)->firstOrFail();
        $client = $this->actingInTenant($owner, $tenant);

        $orderId = $client->withHeader('Idempotency-Key', "order-{$key}-123")
            ->postJson('/api/v1/subscription/orders', ['plan_id' => $plan->id])
            ->assertCreated()
            ->assertJsonPath('data.amount_minor', $plan->price_minor)
            ->json('data.id');

        $checkout = $client->withHeader('Idempotency-Key', "checkout-{$key}-123")
            ->postJson("/api/v1/subscription/orders/{$orderId}/checkout")
            ->assertCreated();

        $gatewayPaymentId = $this->gateway->lastPaymentId();
        $checkout->assertJsonPath('data.redirect_url', 'https://gateway.test/checkout/'.$gatewayPaymentId);

        return ['payment_id' => $checkout->json('data.payment_id'), 'gateway_payment_id' => $gatewayPaymentId, 'order_id' => $orderId];
    }

    private function gatewayReturn(string $gatewayPaymentId, string $status): TestResponse
    {
        return $this->get("/api/v1/subscription-payments/callback/bkash?paymentID={$gatewayPaymentId}&status={$status}");
    }

    private function subscription(Tenant $tenant): Subscription
    {
        return Subscription::forTenant($tenant->id);
    }

    private function validThrough(Subscription $subscription): string
    {
        return (new CalendarPeriod('Asia/Dhaka'))->validThrough($subscription->expires_at)->toDateString();
    }

    public function test_successful_purchase_activates_subscription_and_restores_writes(): void
    {
        $this->travelTo(CarbonImmutable::parse('2027-03-10 11:00', 'Asia/Dhaka'));
        ['tenant' => $tenant, 'owner' => $owner] = $this->createTenant('expired');
        $checkout = $this->startCheckout($tenant, $owner, 'quarterly');

        $this->assertSame(285000, $this->gateway->checkouts[$checkout['gateway_payment_id']]->amountMinor);
        $this->gateway->executeQueue[$checkout['gateway_payment_id']] = [$this->gateway->completed($checkout['gateway_payment_id'])];

        $this->gatewayReturn($checkout['gateway_payment_id'], 'success')
            ->assertRedirect('http://localhost:3000/app/subscription/payment-result?payment='.$checkout['payment_id']);

        $payment = SubscriptionPayment::acrossTenants()->find($checkout['payment_id']);
        $this->assertSame('completed', $payment->status->value);
        $this->assertSame('TRX'.$checkout['gateway_payment_id'], $payment->verified_trx_id);
        $this->assertNotNull($payment->applied_at);

        $subscription = $this->subscription($tenant);
        $this->assertSame('active', $subscription->status->value);
        $this->assertSame('quarterly', $subscription->plan->code->value);
        $this->assertSame('2027-06-10', $this->validThrough($subscription));
        $this->assertSame($payment->verified_trx_id, $subscription->verified_transaction_id);
        $this->assertSame($payment->id, $subscription->payment_id);
        $this->assertSame('paid', SubscriptionOrder::acrossTenants()->find($checkout['order_id'])->status->value);

        $this->actingInTenant($owner, $tenant)
            ->postJson('/api/v1/customers', ['type' => 'individual', 'name' => 'Back in business'])
            ->assertCreated();

        $this->actingInTenant($owner, $tenant)
            ->getJson('/api/v1/subscription/payments/'.$checkout['payment_id'])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.applied', true)
            ->assertJsonMissingPath('data.gateway_history');

        Notification::assertSentTo($owner, SubscriptionActivatedNotification::class);
    }

    public function test_cancelled_checkout_does_not_activate(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->createTenant('expired');
        $checkout = $this->startCheckout($tenant, $owner);
        $this->gateway->queryQueue[$checkout['gateway_payment_id']] = [$this->gateway->status($checkout['gateway_payment_id'], GatewayPaymentStatus::Cancelled)];

        $this->gatewayReturn($checkout['gateway_payment_id'], 'cancel')->assertRedirect();

        $this->assertSame('cancelled', SubscriptionPayment::acrossTenants()->find($checkout['payment_id'])->status->value);
        $this->assertSame(0, $this->gateway->executeCalls);
        $this->assertTrue($this->subscription($tenant)->expires_at->isPast());
    }

    public function test_failed_payment_does_not_activate(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->createTenant('expired');
        $checkout = $this->startCheckout($tenant, $owner);
        $this->gateway->executeQueue[$checkout['gateway_payment_id']] = [$this->gateway->status($checkout['gateway_payment_id'], GatewayPaymentStatus::Failed)];

        $this->gatewayReturn($checkout['gateway_payment_id'], 'success');

        $this->assertSame('failed', SubscriptionPayment::acrossTenants()->find($checkout['payment_id'])->status->value);
        $this->assertTrue($this->subscription($tenant)->expires_at->isPast());
    }

    public function test_forged_success_callback_without_gateway_confirmation_does_not_activate(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->createTenant('expired');
        $checkout = $this->startCheckout($tenant, $owner);

        // Attacker opens the callback URL directly; the gateway says the payment was never authorised.
        $this->gatewayReturn($checkout['gateway_payment_id'], 'success');
        $this->gatewayReturn($checkout['gateway_payment_id'], 'success');

        $payment = SubscriptionPayment::acrossTenants()->find($checkout['payment_id']);
        $this->assertSame('initiated', $payment->status->value);
        $this->assertNull($payment->verified_trx_id);
        $this->assertTrue($this->subscription($tenant)->expires_at->isPast());
        $this->assertSame(0, SubscriptionPeriod::query()->where('source', 'payment')->count());
    }

    public function test_callback_for_unknown_payment_id_is_rejected_quietly(): void
    {
        $this->gatewayReturn('DOES-NOT-EXIST', 'success')
            ->assertRedirect('http://localhost:3000/app/subscription/payment-result?error=invalid');
        $this->get('/api/v1/subscription-payments/callback/othergateway?paymentID=x&status=success')
            ->assertRedirect('http://localhost:3000/app/subscription/payment-result?error=invalid');
        $this->assertSame(0, $this->gateway->executeCalls);
    }

    public function test_wrong_amount_is_flagged_and_not_activated(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->createTenant('expired');
        $checkout = $this->startCheckout($tenant, $owner);
        $this->gateway->executeQueue[$checkout['gateway_payment_id']] = [
            $this->gateway->completed($checkout['gateway_payment_id'], ['amountMinor' => 100]),
        ];

        $this->gatewayReturn($checkout['gateway_payment_id'], 'success');

        $payment = SubscriptionPayment::acrossTenants()->find($checkout['payment_id']);
        $this->assertTrue($payment->requires_review);
        $this->assertSame('VERIFICATION_MISMATCH', $payment->failure_code);
        $this->assertNull($payment->verified_trx_id);
        $this->assertNull($payment->applied_at);
        $this->assertTrue($this->subscription($tenant)->expires_at->isPast());
    }

    public function test_wrong_merchant_reference_or_currency_is_flagged(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->createTenant('expired');
        $first = $this->startCheckout($tenant, $owner, 'monthly', 'a');
        $second = $this->startCheckout($tenant, $owner, 'monthly', 'b');
        $this->gateway->executeQueue[$first['gateway_payment_id']] = [$this->gateway->completed($first['gateway_payment_id'], ['merchantReference' => 'SUB-OTHER'])];
        $this->gateway->executeQueue[$second['gateway_payment_id']] = [$this->gateway->completed($second['gateway_payment_id'], ['currency' => 'USD'])];

        $this->gatewayReturn($first['gateway_payment_id'], 'success');
        $this->gatewayReturn($second['gateway_payment_id'], 'success');

        $this->assertSame(2, SubscriptionPayment::acrossTenants()->where('requires_review', true)->count());
        $this->assertTrue($this->subscription($tenant)->expires_at->isPast());
    }

    public function test_gateway_returning_different_payment_id_is_flagged(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->createTenant('expired');
        $checkout = $this->startCheckout($tenant, $owner);
        $this->gateway->executeQueue[$checkout['gateway_payment_id']] = [
            $this->gateway->completed($checkout['gateway_payment_id'], ['paymentId' => 'SOMEONE-ELSE']),
        ];

        $this->gatewayReturn($checkout['gateway_payment_id'], 'success');

        $this->assertSame('PAYMENT_ID_MISMATCH', SubscriptionPayment::acrossTenants()->find($checkout['payment_id'])->failure_code);
        $this->assertTrue($this->subscription($tenant)->expires_at->isPast());
    }

    public function test_duplicate_callbacks_extend_only_once(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->createTenant('expired');
        $checkout = $this->startCheckout($tenant, $owner);
        $this->gateway->executeQueue[$checkout['gateway_payment_id']] = [$this->gateway->completed($checkout['gateway_payment_id'])];
        $this->gateway->queryQueue[$checkout['gateway_payment_id']] = array_fill(0, 3, $this->gateway->completed($checkout['gateway_payment_id']));

        $this->gatewayReturn($checkout['gateway_payment_id'], 'success');
        $expiry = $this->subscription($tenant)->expires_at;

        $this->gatewayReturn($checkout['gateway_payment_id'], 'success');
        $this->gatewayReturn($checkout['gateway_payment_id'], 'failure');
        $this->artisan('subscriptions:reconcile-payments')->assertSuccessful();

        $this->assertTrue($this->subscription($tenant)->expires_at->equalTo($expiry));
        $this->assertSame(1, SubscriptionPeriod::query()->where('source', 'payment')->count());
        $this->assertSame(1, $this->gateway->executeCalls);
    }

    public function test_applying_the_same_payment_twice_is_a_no_op(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->createTenant('expired');
        $checkout = $this->startCheckout($tenant, $owner);
        $this->gateway->executeQueue[$checkout['gateway_payment_id']] = [$this->gateway->completed($checkout['gateway_payment_id'])];
        $this->gatewayReturn($checkout['gateway_payment_id'], 'success');
        $expiry = $this->subscription($tenant)->expires_at;

        $this->assertFalse(app(SubscriptionActivationService::class)->applyVerifiedPayment($checkout['payment_id']));
        $this->assertTrue($this->subscription($tenant)->expires_at->equalTo($expiry));
    }

    public function test_execute_timeout_is_resolved_by_query_then_reconciliation(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->createTenant('expired');
        $checkout = $this->startCheckout($tenant, $owner);
        $pid = $checkout['gateway_payment_id'];
        $this->gateway->executeQueue[$pid] = [$this->gateway->timeout($pid)];
        $this->gateway->queryQueue[$pid] = [$this->gateway->timeout($pid), $this->gateway->completed($pid)];

        $this->gatewayReturn($pid, 'success');
        $this->assertSame('unknown', SubscriptionPayment::acrossTenants()->find($checkout['payment_id'])->status->value);
        $this->assertTrue($this->subscription($tenant)->expires_at->isPast());

        $this->travel(5)->minutes();
        $this->artisan('subscriptions:reconcile-payments')->assertSuccessful();

        $this->assertSame('completed', SubscriptionPayment::acrossTenants()->find($checkout['payment_id'])->status->value);
        $this->assertTrue($this->subscription($tenant)->expires_at->isFuture());
    }

    public function test_abandoned_checkout_expires_after_window(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->createTenant('expired');
        $checkout = $this->startCheckout($tenant, $owner);

        $this->travel(25)->hours();
        $this->artisan('subscriptions:reconcile-payments')->assertSuccessful();

        $this->assertSame('expired', SubscriptionPayment::acrossTenants()->find($checkout['payment_id'])->status->value);
    }

    public function test_reconciler_completes_verified_but_unapplied_payment(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->createTenant('expired');
        $checkout = $this->startCheckout($tenant, $owner);
        // Simulate a crash between verification and activation.
        SubscriptionPayment::acrossTenants()->whereKey($checkout['payment_id'])->update([
            'status' => 'completed', 'verified_trx_id' => 'TRXCRASH', 'verified_amount_minor' => 100000, 'verified_at' => now(),
        ]);

        $this->artisan('subscriptions:reconcile-payments')->assertSuccessful();

        $this->assertNotNull(SubscriptionPayment::acrossTenants()->find($checkout['payment_id'])->applied_at);
        $this->assertTrue($this->subscription($tenant)->expires_at->isFuture());
    }

    public function test_early_renewal_extends_from_existing_expiry(): void
    {
        $this->travelTo(CarbonImmutable::parse('2027-05-01 10:00', 'Asia/Dhaka'));
        ['tenant' => $tenant, 'owner' => $owner] = $this->createTenant('expired');
        $first = $this->startCheckout($tenant, $owner, 'monthly', 'first');
        $this->gateway->executeQueue[$first['gateway_payment_id']] = [$this->gateway->completed($first['gateway_payment_id'])];
        $this->gatewayReturn($first['gateway_payment_id'], 'success');
        $this->assertSame('2027-06-01', $this->validThrough($this->subscription($tenant)));

        $this->travelTo(CarbonImmutable::parse('2027-05-20 10:00', 'Asia/Dhaka'));
        $second = $this->startCheckout($tenant, $owner, 'yearly', 'second');
        $this->gateway->executeQueue[$second['gateway_payment_id']] = [$this->gateway->completed($second['gateway_payment_id'])];
        $this->gatewayReturn($second['gateway_payment_id'], 'success');

        $subscription = $this->subscription($tenant);
        $this->assertSame('2028-06-01', $this->validThrough($subscription));
        $this->assertNotNull($subscription->renewed_at);
        $this->assertSame(2, SubscriptionPeriod::query()->where('source', 'payment')->count());
    }

    public function test_renewal_after_expiry_starts_from_verified_activation_time(): void
    {
        $this->travelTo(CarbonImmutable::parse('2027-08-20 15:00', 'Asia/Dhaka'));
        ['tenant' => $tenant, 'owner' => $owner] = $this->createTenant('expired');
        $checkout = $this->startCheckout($tenant, $owner, 'half_yearly');
        $this->gateway->executeQueue[$checkout['gateway_payment_id']] = [$this->gateway->completed($checkout['gateway_payment_id'])];

        $this->gatewayReturn($checkout['gateway_payment_id'], 'success');

        $subscription = $this->subscription($tenant);
        $this->assertSame('2028-02-20', $this->validThrough($subscription));
        $this->assertTrue($subscription->activated_at->equalTo(now()));
    }

    public function test_paying_during_trial_extends_from_trial_end(): void
    {
        $this->travelTo(CarbonImmutable::parse('2027-01-01 10:00', 'Asia/Dhaka'));
        ['tenant' => $tenant, 'owner' => $owner] = $this->createTenant('pending_payment');
        Subscription::query()->where('tenant_id', $tenant->id)->delete();
        app(SubscriptionActivationService::class)->initialize($tenant, 14); // valid through Jan 14

        $checkout = $this->startCheckout($tenant, $owner);
        $this->gateway->executeQueue[$checkout['gateway_payment_id']] = [$this->gateway->completed($checkout['gateway_payment_id'])];
        $this->gatewayReturn($checkout['gateway_payment_id'], 'success');

        $subscription = $this->subscription($tenant);
        $this->assertSame('2027-02-14', $this->validThrough($subscription));
        $this->assertFalse($subscription->is_trial);
    }

    public function test_two_orders_paid_concurrently_each_extend_once(): void
    {
        $this->travelTo(CarbonImmutable::parse('2027-04-10 10:00', 'Asia/Dhaka'));
        ['tenant' => $tenant, 'owner' => $owner] = $this->createTenant('expired');
        $a = $this->startCheckout($tenant, $owner, 'monthly', 'a');
        $b = $this->startCheckout($tenant, $owner, 'monthly', 'b');
        $this->gateway->executeQueue[$a['gateway_payment_id']] = [$this->gateway->completed($a['gateway_payment_id'])];
        $this->gateway->executeQueue[$b['gateway_payment_id']] = [$this->gateway->completed($b['gateway_payment_id'])];

        $this->gatewayReturn($a['gateway_payment_id'], 'success');
        $this->gatewayReturn($b['gateway_payment_id'], 'success');

        // Two genuine payments = two consecutive months, never overlapping.
        $this->assertSame('2027-06-10', $this->validThrough($this->subscription($tenant)));
        $periods = SubscriptionPeriod::query()->orderBy('period_start')->get();
        $this->assertCount(2, $periods);
        $this->assertTrue($periods[1]->period_start->equalTo($periods[0]->period_end));
    }

    public function test_second_successful_payment_for_same_order_is_flagged_not_applied(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->createTenant('expired');
        $checkout = $this->startCheckout($tenant, $owner, 'monthly', 'x');
        // Customer opens checkout again for the same order in another tab.
        $retry = $this->actingInTenant($owner, $tenant)->withHeader('Idempotency-Key', 'checkout-retry-123')
            ->postJson("/api/v1/subscription/orders/{$checkout['order_id']}/checkout")->assertCreated();
        $retryGatewayId = $this->gateway->lastPaymentId();

        $this->gateway->executeQueue[$checkout['gateway_payment_id']] = [$this->gateway->completed($checkout['gateway_payment_id'])];
        $this->gatewayReturn($checkout['gateway_payment_id'], 'success');
        $expiry = $this->subscription($tenant)->expires_at;

        // Order is now paid, so a new checkout is refused...
        $this->assertErrorCode($this->actingInTenant($owner, $tenant)->withHeader('Idempotency-Key', 'checkout-again-123')
            ->postJson("/api/v1/subscription/orders/{$checkout['order_id']}/checkout"), 409, 'CONFLICT');

        // ...and the parallel attempt that also completed is flagged for refund.
        $this->gateway->executeQueue[$retryGatewayId] = [$this->gateway->completed($retryGatewayId)];
        $this->gatewayReturn($retryGatewayId, 'success');

        $duplicate = SubscriptionPayment::acrossTenants()->find($retry->json('data.payment_id'));
        $this->assertSame('DUPLICATE_PAYMENT_FOR_ORDER', $duplicate->failure_code);
        $this->assertTrue($duplicate->requires_review);
        $this->assertTrue($this->subscription($tenant)->expires_at->equalTo($expiry));
    }

    public function test_reused_transaction_id_is_flagged(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->createTenant('expired');
        $a = $this->startCheckout($tenant, $owner, 'monthly', 'a');
        $b = $this->startCheckout($tenant, $owner, 'monthly', 'b');
        $this->gateway->executeQueue[$a['gateway_payment_id']] = [$this->gateway->completed($a['gateway_payment_id'], ['transactionId' => 'SAMETRX'])];
        $this->gateway->executeQueue[$b['gateway_payment_id']] = [$this->gateway->completed($b['gateway_payment_id'], ['transactionId' => 'SAMETRX'])];

        $this->gatewayReturn($a['gateway_payment_id'], 'success');
        $this->gatewayReturn($b['gateway_payment_id'], 'success');

        $this->assertSame('DUPLICATE_TRANSACTION', SubscriptionPayment::acrossTenants()->find($b['payment_id'])->failure_code);
        $this->assertSame(1, SubscriptionPeriod::query()->count());
    }

    public function test_order_creation_is_idempotent_and_price_comes_from_server(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->createTenant('expired');
        $plan = Plan::query()->where('code', 'monthly')->first();
        $client = $this->actingInTenant($owner, $tenant)->withHeader('Idempotency-Key', 'same-key-123');

        $first = $client->postJson('/api/v1/subscription/orders', ['plan_id' => $plan->id, 'amount_minor' => 1])->assertCreated();
        $second = $client->postJson('/api/v1/subscription/orders', ['plan_id' => $plan->id, 'amount_minor' => 1])->assertCreated();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $second->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame(1, SubscriptionOrder::acrossTenants()->count());
        $this->assertSame($plan->price_minor, SubscriptionOrder::acrossTenants()->first()->amount_minor);

        $this->assertErrorCode(
            $client->postJson('/api/v1/subscription/orders', ['plan_id' => Plan::where('code', 'yearly')->first()->id]),
            422,
            'IDEMPOTENCY_KEY_REUSED',
        );
    }

    public function test_idempotency_key_is_required(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->createTenant('expired');

        $this->assertErrorCode(
            $this->actingInTenant($owner, $tenant)->postJson('/api/v1/subscription/orders', ['plan_id' => Plan::first()->id]),
            400,
            'IDEMPOTENCY_KEY_REQUIRED',
        );
    }

    public function test_price_change_does_not_affect_existing_order(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->createTenant('expired');
        $checkout = $this->startCheckout($tenant, $owner);
        Plan::query()->where('code', 'monthly')->update(['price_minor' => 999900]);

        $this->gateway->executeQueue[$checkout['gateway_payment_id']] = [$this->gateway->completed($checkout['gateway_payment_id'])];
        $this->gatewayReturn($checkout['gateway_payment_id'], 'success');

        $this->assertTrue($this->subscription($tenant)->expires_at->isFuture());
        $this->assertSame(100000, $this->subscription($tenant)->amount_minor);
    }

    public function test_gateway_unavailable_returns_503(): void
    {
        $this->gateway->available = false;
        ['tenant' => $tenant, 'owner' => $owner] = $this->createTenant('expired');
        $client = $this->actingInTenant($owner, $tenant);
        $orderId = $client->withHeader('Idempotency-Key', 'order-unavail-1')
            ->postJson('/api/v1/subscription/orders', ['plan_id' => Plan::first()->id])->json('data.id');

        $this->assertErrorCode(
            $client->withHeader('Idempotency-Key', 'checkout-unavail-1')->postJson("/api/v1/subscription/orders/{$orderId}/checkout"),
            503,
            'PAYMENT_GATEWAY_UNAVAILABLE',
        );
    }

    public function test_cannot_checkout_another_tenants_order(): void
    {
        ['tenant' => $a, 'owner' => $ownerA] = $this->createTenant('expired');
        ['tenant' => $b, 'owner' => $ownerB] = $this->createTenant('expired');
        $orderB = $this->actingInTenant($ownerB, $b)->withHeader('Idempotency-Key', 'order-b-1234')
            ->postJson('/api/v1/subscription/orders', ['plan_id' => Plan::first()->id])->json('data.id');

        $this->actingInTenant($ownerA, $a)->withHeader('Idempotency-Key', 'checkout-a-1234')
            ->postJson("/api/v1/subscription/orders/{$orderB}/checkout")->assertNotFound();
    }

    public function test_complimentary_extension_is_recorded_separately_from_payments(): void
    {
        $this->travelTo(CarbonImmutable::parse('2027-02-01 10:00', 'Asia/Dhaka'));
        ['tenant' => $tenant, 'owner' => $owner] = $this->createTenant('expired');
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin, 'web')->postJson("/api/v1/admin/tenants/{$tenant->id}/extensions", [
            'days' => 10, 'reason' => 'Service outage compensation',
        ])->assertCreated();

        $period = SubscriptionPeriod::query()->firstOrFail();
        $this->assertSame('complimentary', $period->source->value);
        $this->assertNull($period->payment_id);
        $this->assertSame($admin->id, $period->granted_by_user_id);
        $this->assertSame('2027-02-10', $this->validThrough($this->subscription($tenant)));
        $this->assertNull($this->subscription($tenant)->verified_transaction_id);
        $this->assertSame(0, SubscriptionPayment::acrossTenants()->count());
    }
}
