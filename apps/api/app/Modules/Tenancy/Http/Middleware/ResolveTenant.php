<?php

namespace App\Modules\Tenancy\Http\Middleware;

use App\Modules\Subscriptions\Services\SubscriptionEntitlementService;
use App\Modules\Tenancy\Models\TenantMembership;
use App\Modules\Tenancy\Support\TenantContext;
use App\Support\Http\ErrorCode;
use App\Support\Http\ErrorResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the tenant from the X-Tenant header and verifies that the authenticated user
 * holds an active membership in it. The client-supplied tenant id is never trusted on
 * its own: unknown tenants and tenants the user does not belong to both return 404,
 * so tenant ids cannot be probed.
 */
final class ResolveTenant
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly SubscriptionEntitlementService $entitlements,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenantId = (string) $request->header('X-Tenant', '');

        if ($tenantId === '') {
            return ErrorResponse::make(ErrorCode::TenantRequired, 'Select a company to continue.', 400);
        }

        $membership = null;
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $tenantId)) {
            $membership = TenantMembership::query()
                ->where('tenant_id', strtolower($tenantId))
                ->where('user_id', $request->user()->getAuthIdentifier())
                ->where('status', 'active')
                ->with(['tenant', 'roleRows'])
                ->first();
        }

        if ($membership === null || $membership->tenant->isClosed()) {
            return ErrorResponse::make(ErrorCode::TenantNotFound, 'Company not found.', 404);
        }

        $this->context->set($membership->tenant, $membership);

        $response = $next($request);

        // Lets the SPA flip into read-only mode as soon as the subscription lapses mid-session.
        $state = $this->entitlements->stateFor($membership->tenant);
        $response->headers->set('X-Subscription-Mode', $state->mode->value);

        return $response;
    }
}
