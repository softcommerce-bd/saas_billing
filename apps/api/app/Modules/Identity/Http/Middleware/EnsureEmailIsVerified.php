<?php

namespace App\Modules\Identity\Http\Middleware;

use App\Support\Http\ErrorCode;
use App\Support\Http\ErrorResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureEmailIsVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->hasVerifiedEmail()) {
            return ErrorResponse::make(ErrorCode::EmailNotVerified, 'Please verify your email address to continue.', 403);
        }

        return $next($request);
    }
}
