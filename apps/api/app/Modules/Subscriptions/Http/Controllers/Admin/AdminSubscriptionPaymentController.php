<?php

namespace App\Modules\Subscriptions\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Subscriptions\Http\Resources\SubscriptionPaymentResource;
use App\Modules\Subscriptions\Models\SubscriptionPayment;
use App\Modules\Subscriptions\Services\SubscriptionPaymentProcessor;
use App\Modules\Subscriptions\Services\SubscriptionRefundService;
use App\Support\Audit\AuditEvent;
use App\Support\Money\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AdminSubscriptionPaymentController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'status' => ['nullable', 'string', 'max:20'],
            'requires_review' => ['nullable', 'boolean'],
            'tenant_id' => ['nullable', 'string', 'size:26'],
        ]);

        $query = SubscriptionPayment::acrossTenants()->with('order')->latest();
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($request->has('requires_review')) {
            $query->where('requires_review', $request->boolean('requires_review'));
        }
        if ($tenantId = $request->query('tenant_id')) {
            $query->where('tenant_id', strtolower($tenantId));
        }

        return SubscriptionPaymentResource::collection($query->paginate(25));
    }

    public function show(string $payment): SubscriptionPaymentResource
    {
        return new SubscriptionPaymentResource(SubscriptionPayment::acrossTenants()->with('order')->findOrFail($payment));
    }

    public function reconcile(string $payment, SubscriptionPaymentProcessor $processor): SubscriptionPaymentResource
    {
        $model = SubscriptionPayment::acrossTenants()->findOrFail($payment);

        return new SubscriptionPaymentResource($processor->reconcile($model)->load('order'));
    }

    public function refund(Request $request, string $payment, SubscriptionRefundService $refunds): SubscriptionPaymentResource
    {
        $data = $request->validate([
            'amount' => ['required', 'string', 'regex:/^\d{1,9}(\.\d{1,2})?$/'],
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ]);

        $model = SubscriptionPayment::acrossTenants()->findOrFail($payment);

        return new SubscriptionPaymentResource(
            $refunds->refund($model, $request->user(), Money::parseToMinor($data['amount']), $data['reason'])->load('order'),
        );
    }

    public function dispute(Request $request, string $payment, SubscriptionRefundService $refunds): SubscriptionPaymentResource
    {
        $data = $request->validate(['note' => ['required', 'string', 'min:5', 'max:1000']]);
        $model = SubscriptionPayment::acrossTenants()->findOrFail($payment);

        return new SubscriptionPaymentResource($refunds->markDisputed($model, $request->user(), $data['note'])->load('order'));
    }

    /**
     * Platform audit trail: subscription, payment and tenant-administration events only.
     */
    public function auditEvents(Request $request): JsonResponse
    {
        $page = AuditEvent::query()
            ->where(fn ($q) => $q->where('action', 'like', 'subscription%')
                ->orWhere('action', 'like', 'plan.%')
                ->orWhere('action', 'like', 'tenant.%')
                ->orWhere('action', 'like', 'entitlement_override.%'))
            ->when($request->query('tenant_id'), fn ($q, $id) => $q->where('tenant_id', strtolower((string) $id)))
            ->orderByDesc('id')
            ->paginate(50);

        return new JsonResponse([
            'data' => $page->items(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }
}
