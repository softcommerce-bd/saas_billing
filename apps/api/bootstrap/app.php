<?php

use App\Modules\Identity\Http\Middleware\EnsureEmailIsVerified;
use App\Modules\Subscriptions\Http\Middleware\EnsureBusinessWriteAllowed;
use App\Modules\Subscriptions\Http\Middleware\RequirePlatformRole;
use App\Modules\Tenancy\Http\Middleware\RequirePermission;
use App\Modules\Tenancy\Http\Middleware\ResetTenantContext;
use App\Modules\Tenancy\Http\Middleware\ResolveTenant;
use App\Modules\Tenancy\Support\MissingTenantContext;
use App\Support\Http\ApiException;
use App\Support\Http\ErrorCode;
use App\Support\Http\ErrorResponse;
use App\Support\Idempotency\RequireIdempotencyKey;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        __DIR__.'/../app/Modules/Subscriptions/Console',
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        // Sanctum first-party SPA: session cookie + CSRF for requests from stateful domains.
        $middleware->prepend(ResetTenantContext::class);
        $middleware->statefulApi();
        $middleware->trustProxies(at: env('TRUSTED_PROXIES', '127.0.0.1'));

        $middleware->alias([
            'verified.api' => EnsureEmailIsVerified::class,
            'tenant' => ResolveTenant::class,
            'permission' => RequirePermission::class,
            'business.write' => EnsureBusinessWriteAllowed::class,
            'platform' => RequirePlatformRole::class,
            'idempotent' => RequireIdempotencyKey::class,
        ]);

        // Tenant context must exist before route-model binding runs tenant-scoped queries.
        $middleware->prependToPriorityList(SubstituteBindings::class, EnsureEmailIsVerified::class);
        $middleware->prependToPriorityList(SubstituteBindings::class, ResolveTenant::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->dontReport([ApiException::class]);

        $exceptions->render(function (Throwable $e, Request $request) {
            if (! ($request->is('api/*') || $request->expectsJson())) {
                return null;
            }

            return match (true) {
                $e instanceof ApiException => $e->render(),
                $e instanceof ValidationException => ErrorResponse::make(
                    ErrorCode::ValidationFailed,
                    'Please correct the highlighted fields.',
                    422,
                    ['fields' => $e->errors()],
                ),
                $e instanceof AuthenticationException => ErrorResponse::make(ErrorCode::Unauthenticated, 'Please sign in to continue.', 401),
                $e instanceof AuthorizationException => ErrorResponse::make(ErrorCode::Forbidden, 'You are not allowed to perform this action.', 403),
                $e instanceof ModelNotFoundException,
                $e instanceof NotFoundHttpException => ErrorResponse::make(ErrorCode::NotFound, 'The requested resource was not found.', 404),
                $e instanceof ThrottleRequestsException => ErrorResponse::make(ErrorCode::TooManyRequests, 'Too many requests. Please slow down.', 429),
                $e instanceof TokenMismatchException => ErrorResponse::make(ErrorCode::CsrfTokenMismatch, 'Your session expired. Please refresh the page.', 419),
                $e instanceof HttpExceptionInterface && $e->getStatusCode() === 419 => ErrorResponse::make(ErrorCode::CsrfTokenMismatch, 'Your session expired. Please refresh the page.', 419),
                $e instanceof MissingTenantContext => ErrorResponse::make(ErrorCode::TenantRequired, 'Select a company to continue.', 400),
                $e instanceof HttpExceptionInterface => ErrorResponse::make(
                    $e->getStatusCode() === 403 ? ErrorCode::Forbidden : ($e->getStatusCode() === 404 ? ErrorCode::NotFound : ErrorCode::ServerError),
                    $e->getStatusCode() < 500 ? ($e->getMessage() ?: 'Request failed.') : 'Something went wrong. Please try again.',
                    $e->getStatusCode(),
                ),
                // Never leak internals in production; debug mode shows the default page.
                config('app.debug') => null,
                default => ErrorResponse::make(ErrorCode::ServerError, 'Something went wrong. Please try again.', 500),
            };
        });
    })->create();
