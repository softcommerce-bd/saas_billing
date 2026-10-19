<?php

namespace App\Modules\Tenancy\Http\Middleware;

use App\Modules\Tenancy\Enums\Permission;
use App\Modules\Tenancy\Support\TenantContext;
use App\Support\Http\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route-level role permission check: ->middleware('permission:customers.view').
 * Must run after ResolveTenant.
 */
final class RequirePermission
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $membership = $this->context->membership();

        if ($membership === null || ! $membership->can(Permission::from($permission))) {
            throw ApiException::forbidden();
        }

        return $next($request);
    }
}
