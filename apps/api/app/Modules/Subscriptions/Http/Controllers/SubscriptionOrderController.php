<?php

namespace App\Modules\Subscriptions\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Subscriptions\Models\Plan;
use App\Modules\Subscriptions\Models\SubscriptionOrder;
use App\Modules\Subscriptions\Services\SubscriptionCheckoutService;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Renewal endpoints. Deliberately registered OUTSIDE the business-write group so that
 * expired (read-only) tenants can always renew.
 */
class SubscriptionOrderController extends Controller
{
    public function store(Request $request, TenantContext $context, SubscriptionCheckoutService $checkout): JsonResponse
    {
        $validated = $request->validate([
            'plan_id' => ['required', 'string', 'size:26'],
        ]);

        // The price always comes from the server-side plan, never from the request.
        $plan = Plan::query()->where('is_active', true)->findOrFail(strtolower($validated['plan_id']));
        $order = $checkout->createOrder($context->tenant(), $request->user(), $plan);

        return new JsonResponse(['data' => [
            'id' => $order->id,
            'plan_code' => $order->plan_code,
            'duration_months' => $order->duration_months,
            'amount_minor' => $order->amount_minor,
            'currency' => $order->currency,
            'status' => $order->status->value,
            'merchant_reference' => $order->merchant_invoice_number,
        ]], 201);
    }

    public function checkout(Request $request, SubscriptionOrder $order, SubscriptionCheckoutService $checkout): JsonResponse
    {
        $result = $checkout->startCheckout($order, $request->user());

        return new JsonResponse(['data' => [
            'payment_id' => $result['payment']->id,
            'redirect_url' => $result['redirect_url'],
        ]], 201);
    }
}
