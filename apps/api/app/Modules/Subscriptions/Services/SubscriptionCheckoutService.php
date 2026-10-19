<?php

namespace App\Modules\Subscriptions\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Subscriptions\Enums\OrderStatus;
use App\Modules\Subscriptions\Enums\PaymentStatus;
use App\Modules\Subscriptions\Gateway\CheckoutRequest;
use App\Modules\Subscriptions\Gateway\GatewayException;
use App\Modules\Subscriptions\Gateway\SubscriptionPaymentGateway;
use App\Modules\Subscriptions\Models\Plan;
use App\Modules\Subscriptions\Models\SubscriptionOrder;
use App\Modules\Subscriptions\Models\SubscriptionPayment;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Audit\AuditLogger;
use App\Support\Http\ApiException;
use App\Support\Http\ErrorCode;
use Illuminate\Support\Facades\Log;

final class SubscriptionCheckoutService
{
    public function __construct(
        private readonly SubscriptionPaymentGateway $gateway,
        private readonly SubscriptionEntitlementService $entitlements,
        private readonly AuditLogger $audit,
    ) {}

    public function createOrder(Tenant $tenant, User $user, Plan $plan): SubscriptionOrder
    {
        if (! $plan->is_active || $plan->price_minor <= 0) {
            throw new ApiException(ErrorCode::ValidationFailed, 'This package is not available.', 422);
        }

        if (! $this->entitlements->stateFor($tenant)->canRenew) {
            throw new ApiException(ErrorCode::PaymentNotAllowed, 'This company account is suspended. Please contact support.', 403);
        }

        $order = new SubscriptionOrder([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'plan_code' => $plan->code->value,
            'duration_months' => $plan->duration_months,
            'amount_minor' => $plan->price_minor,
            'currency' => $plan->currency,
            'status' => OrderStatus::Pending,
            'created_by_user_id' => $user->id,
        ]);
        $order->id = $order->newUniqueId();
        $order->merchant_invoice_number = 'SUB-'.strtoupper($order->id);
        $order->save();

        $this->audit->record('subscription_order.created', $order, [
            'plan_code' => $order->plan_code,
            'amount_minor' => $order->amount_minor,
        ]);

        return $order;
    }

    /**
     * @return array{payment: SubscriptionPayment, redirect_url: string}
     */
    public function startCheckout(SubscriptionOrder $order, User $user): array
    {
        if ($order->status !== OrderStatus::Pending) {
            throw new ApiException(ErrorCode::Conflict, 'This order can no longer be paid.', 409);
        }

        if (! $this->entitlements->stateFor($order->tenant)->canRenew) {
            throw new ApiException(ErrorCode::PaymentNotAllowed, 'This company account is suspended. Please contact support.', 403);
        }

        if (! $this->gateway->isAvailable()) {
            throw new ApiException(ErrorCode::PaymentGatewayUnavailable, 'Online payment is temporarily unavailable. Please try again later.', 503);
        }

        $payment = SubscriptionPayment::create([
            'tenant_id' => $order->tenant_id,
            'order_id' => $order->id,
            'gateway' => $this->gateway->name(),
            'environment' => $this->gateway->environment(),
            'status' => PaymentStatus::Initiated,
            'amount_minor' => $order->amount_minor,
            'currency' => $order->currency,
        ]);

        try {
            $session = $this->gateway->createCheckout(new CheckoutRequest(
                amountMinor: $order->amount_minor,
                currency: $order->currency,
                merchantReference: $order->merchant_invoice_number,
                payerReference: substr($order->tenant_id, -12),
                callbackUrl: route('subscription-payments.callback', ['gateway' => $this->gateway->name()]),
            ));
        } catch (GatewayException $e) {
            Log::warning('Subscription checkout creation failed.', ['payment_id' => $payment->id, 'error' => $e->getMessage()]);
            $payment->update(['status' => PaymentStatus::Failed, 'failure_code' => $e->gatewayCode ?? 'CREATE_FAILED', 'failure_message' => mb_substr($e->getMessage(), 0, 255)]);

            throw new ApiException(ErrorCode::PaymentGatewayUnavailable, 'Could not start the payment. Please try again.', 502);
        }

        $payment->gateway_payment_id = $session->paymentId;
        $payment->appendGatewayPayload('create', $session->raw);
        $payment->save();

        $this->audit->record('subscription_payment.initiated', $payment, ['gateway' => $payment->gateway, 'order_id' => $order->id], actorUserId: $user->id);

        return ['payment' => $payment, 'redirect_url' => $session->redirectUrl];
    }
}
