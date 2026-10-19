<?php

namespace App\Modules\Subscriptions\Http\Middleware;

use App\Modules\Subscriptions\Services\SubscriptionEntitlementService;
use App\Modules\Tenancy\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fail-closed read-only enforcement for every business route group.
 *
 * Safe methods (GET/HEAD/OPTIONS) pass through. Every other method requires an
 * entitled subscription, so a newly added mutation endpoint is protected by default.
 * Subscription renewal and account-security routes are registered outside this group.
 */
final class EnsureBusinessWriteAllowed
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly SubscriptionEntitlementService $entitlements,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethodSafe()) {
            $this->entitlements->checkSubscription($this->context->tenant())->throwIfDenied();
        }

        return $next($request);
    }
}
