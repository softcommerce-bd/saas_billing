<?php

namespace App\Modules\Subscriptions\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Subscriptions\Http\Resources\PlanResource;
use App\Modules\Subscriptions\Http\Resources\SubscriptionPaymentResource;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Models\SubscriptionPayment;
use App\Modules\Subscriptions\Services\SubscriptionEntitlementService;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SubscriptionController extends Controller
{
    public function show(TenantContext $context, SubscriptionEntitlementService $entitlements): JsonResponse
    {
        $tenant = $context->tenant();
        $subscription = Subscription::forTenant($tenant->id)?->load('plan.entitlementProfile');

        $periods = $subscription?->periods()->orderByDesc('period_end')->limit(24)->get()->map(fn ($p) => [
            'id' => $p->id,
            'source' => $p->source->value,
            'months' => $p->months,
            'days' => $p->days,
            'period_start' => $p->period_start->toIso8601String(),
            'period_end' => $p->period_end->toIso8601String(),
            'reason' => $p->source->value === 'payment' ? null : $p->reason,
            'revoked' => $p->revoked_at !== null,
        ]) ?? [];

        return new JsonResponse(['data' => [
            'status' => $subscription?->status->value,
            'plan' => $subscription?->plan ? new PlanResource($subscription->plan) : null,
            'starts_at' => $subscription?->starts_at?->toIso8601String(),
            'expires_at' => $subscription?->expires_at?->toIso8601String(),
            'activated_at' => $subscription?->activated_at?->toIso8601String(),
            'renewed_at' => $subscription?->renewed_at?->toIso8601String(),
            'amount_minor' => $subscription?->amount_minor,
            'currency' => $subscription?->currency,
            'entitlement' => $entitlements->stateFor($tenant)->toArray(),
            'periods' => $periods,
        ]]);
    }

    public function payments(): AnonymousResourceCollection
    {
        return SubscriptionPaymentResource::collection(
            SubscriptionPayment::query()->with('order')->latest()->paginate(20),
        );
    }

    public function payment(SubscriptionPayment $payment): SubscriptionPaymentResource
    {
        return new SubscriptionPaymentResource($payment->load('order'));
    }
}
