<?php

namespace App\Modules\Subscriptions\Http\Middleware;

use App\Support\Http\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ->middleware('platform:super_admin') or 'platform:super_admin,support_admin'.
 */
final class RequirePlatformRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->is_active || ! in_array($user->platform_role, $roles, true)) {
            throw ApiException::forbidden();
        }

        return $next($request);
    }
}
