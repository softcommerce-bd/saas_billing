<?php

namespace App\Modules\Subscriptions\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\EntitlementOverride;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Services\EntitlementOverrideService;
use App\Modules\Subscriptions\Services\SubscriptionActivationService;
use App\Modules\Subscriptions\Services\SubscriptionEntitlementService;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Audit\AuditLogger;
use App\Support\Time\BusinessClock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Platform view of tenants: subscription metadata only, never tenant business data.
 */
class AdminTenantController extends Controller
{
    public function index(Request $request, SubscriptionEntitlementService $entitlements): JsonResponse
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'in:active,trial,expired,suspended,pending_payment'],
        ]);

        $now = BusinessClock::now();
        $query = Tenant::query()->with('subscription.plan')->orderByDesc('created_at');

        if ($search = $request->query('search')) {
            $query->where(fn ($q) => $q->where('name', 'ilike', '%'.addcslashes($search, '%_\\').'%')
                ->orWhere('slug', 'ilike', '%'.addcslashes($search, '%_\\').'%'));
        }

        match ($request->query('state')) {
            'suspended' => $query->whereNotNull('suspended_at'),
            'active' => $query->whereNull('suspended_at')->whereHas('subscription', fn ($q) => $q->where('expires_at', '>', $now)->where('is_trial', false)),
            'trial' => $query->whereNull('suspended_at')->whereHas('subscription', fn ($q) => $q->where('expires_at', '>', $now)->where('is_trial', true)),
            'expired' => $query->whereNull('suspended_at')->whereHas('subscription', fn ($q) => $q->where('expires_at', '<=', $now)),
            'pending_payment' => $query->whereHas('subscription', fn ($q) => $q->whereNull('expires_at')),
            default => null,
        };

        $page = $query->paginate(25);

        return new JsonResponse([
            'data' => collect($page->items())->map(fn (Tenant $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'slug' => $t->slug,
                'created_at' => $t->created_at->toIso8601String(),
                'suspended_at' => $t->suspended_at?->toIso8601String(),
                'subscription' => [
                    'status' => $t->subscription?->status->value,
                    'plan_code' => $t->subscription?->plan?->code->value,
                    'expires_at' => $t->subscription?->expires_at?->toIso8601String(),
                    'is_trial' => (bool) $t->subscription?->is_trial,
                ],
                'entitlement' => $entitlements->stateFor($t)->toArray(),
            ]),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function suspend(Request $request, Tenant $tenant, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);

        DB::transaction(function () use ($tenant, $data, $audit) {
            $tenant->forceFill(['suspended_at' => BusinessClock::now(), 'suspension_reason' => $data['reason']])->save();
            Subscription::query()->where('tenant_id', $tenant->id)->update(['suspended_at' => BusinessClock::now()]);
            $audit->record('tenant.suspended', $tenant, ['reason' => $data['reason']], tenantId: $tenant->id, actorType: 'platform');
        });

        return new JsonResponse(['data' => ['suspended' => true]]);
    }

    public function unsuspend(Request $request, Tenant $tenant, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);

        DB::transaction(function () use ($tenant, $data, $audit) {
            $tenant->forceFill(['suspended_at' => null, 'suspension_reason' => null])->save();
            Subscription::query()->where('tenant_id', $tenant->id)->where('status', SubscriptionStatus::Suspended)
                ->update(['status' => SubscriptionStatus::Active, 'suspended_at' => null]);
            Subscription::query()->where('tenant_id', $tenant->id)->update(['suspended_at' => null]);
            $audit->record('tenant.unsuspended', $tenant, ['reason' => $data['reason']], tenantId: $tenant->id, actorType: 'platform');
        });

        return new JsonResponse(['data' => ['suspended' => false]]);
    }

    public function extend(Request $request, Tenant $tenant, SubscriptionActivationService $activation): JsonResponse
    {
        $data = $request->validate([
            'months' => ['nullable', 'integer', 'min:1', 'max:12', 'required_without:days', 'prohibits:days'],
            'days' => ['nullable', 'integer', 'min:1', 'max:90', 'required_without:months'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $period = $activation->grantComplimentary($tenant, $request->user(), $data['months'] ?? null, $data['days'] ?? null, $data['reason']);

        return new JsonResponse(['data' => [
            'period_id' => $period->id,
            'expires_at' => $period->period_end->toIso8601String(),
        ]], 201);
    }

    public function grantOverride(Request $request, Tenant $tenant, EntitlementOverrideService $overrides): JsonResponse
    {
        $data = $request->validate([
            'hours' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $override = $overrides->grant($tenant, $request->user(), $data['hours'], $data['reason']);

        return new JsonResponse(['data' => ['id' => $override->id, 'expires_at' => $override->expires_at->toIso8601String()]], 201);
    }

    public function revokeOverride(Request $request, EntitlementOverride $override, EntitlementOverrideService $overrides): JsonResponse
    {
        $overrides->revoke($override, $request->user());

        return new JsonResponse(['data' => ['revoked' => true]]);
    }
}
