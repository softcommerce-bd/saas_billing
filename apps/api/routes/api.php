<?php

use App\Modules\Catalog\Http\Controllers\CatalogItemController;
use App\Modules\Customers\Http\Controllers\CustomerController;
use App\Modules\Identity\Http\Controllers\AuthController;
use App\Modules\Identity\Http\Controllers\EmailVerificationController;
use App\Modules\Identity\Http\Controllers\PasswordResetController;
use App\Modules\Subscriptions\Http\Controllers\Admin\AdminPlanController;
use App\Modules\Subscriptions\Http\Controllers\Admin\AdminSubscriptionPaymentController;
use App\Modules\Subscriptions\Http\Controllers\Admin\AdminTenantController;
use App\Modules\Subscriptions\Http\Controllers\PaymentCallbackController;
use App\Modules\Subscriptions\Http\Controllers\PlanController;
use App\Modules\Subscriptions\Http\Controllers\SubscriptionController;
use App\Modules\Subscriptions\Http\Controllers\SubscriptionOrderController;
use App\Modules\Tenancy\Http\Controllers\AcceptInvitationController;
use App\Modules\Tenancy\Http\Controllers\CurrentTenantController;
use App\Modules\Tenancy\Http\Controllers\InvitationController;
use App\Modules\Tenancy\Http\Controllers\MemberController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1
|--------------------------------------------------------------------------
| Route groups and their guarantees:
|  - public:   no authentication (rate limited)
|  - account:  authenticated user, no tenant
|  - tenant:   authenticated + verified + active membership in the X-Tenant company
|      - renewal:  always available (expired companies must be able to pay)
|      - business: 'business.write' blocks every non-GET request without entitlement
|  - admin:    platform staff only, no tenant business data
*/

Route::prefix('v1')->group(function () {
    // ---- public ----------------------------------------------------------
    Route::middleware('throttle:auth')->group(function () {
        Route::post('auth/register', [AuthController::class, 'register']);
        Route::post('auth/login', [AuthController::class, 'login']);
        Route::post('auth/forgot-password', [PasswordResetController::class, 'sendLink']);
        Route::post('auth/reset-password', [PasswordResetController::class, 'reset']);
        Route::post('invitations/register', [AcceptInvitationController::class, 'register']);
    });

    Route::get('auth/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware(['signed', 'throttle:6,1'])
        ->name('auth.verification.verify');

    Route::get('plans', [PlanController::class, 'index']);

    Route::get('subscription-payments/callback/{gateway}', PaymentCallbackController::class)
        ->middleware('throttle:payment-callback')
        ->name('subscription-payments.callback');

    // ---- account ----------------------------------------------------------
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::post('auth/email/resend', [EmailVerificationController::class, 'resend'])->middleware('throttle:3,1');
        Route::post('invitations/accept', [AcceptInvitationController::class, 'accept'])->middleware('verified.api');

        // ---- tenant -----------------------------------------------------------
        Route::middleware(['verified.api', 'tenant'])->group(function () {
            // Renewal: reachable in read-only mode.
            Route::get('subscription', [SubscriptionController::class, 'show'])->middleware('permission:subscription.view');
            Route::get('subscription/payments', [SubscriptionController::class, 'payments'])->middleware('permission:subscription.view');
            Route::get('subscription/payments/{payment}', [SubscriptionController::class, 'payment'])->middleware('permission:subscription.view');
            Route::post('subscription/orders', [SubscriptionOrderController::class, 'store'])
                ->middleware(['permission:subscription.manage', 'idempotent:subscription.order']);
            Route::post('subscription/orders/{order}/checkout', [SubscriptionOrderController::class, 'checkout'])
                ->middleware(['permission:subscription.manage', 'idempotent:subscription.checkout']);

            // Business data: read always, write only with entitlement.
            Route::middleware('business.write')->group(function () {
                Route::get('company', [CurrentTenantController::class, 'show']);
                Route::patch('company', [CurrentTenantController::class, 'update'])->middleware('permission:settings.manage');

                Route::get('members', [MemberController::class, 'index'])->middleware('permission:users.manage');
                Route::patch('members/{membership}', [MemberController::class, 'update'])->middleware('permission:users.manage');
                Route::delete('members/{membership}', [MemberController::class, 'destroy'])->middleware('permission:users.manage');
                Route::get('invitations', [InvitationController::class, 'index'])->middleware('permission:users.manage');
                Route::post('invitations', [InvitationController::class, 'store'])->middleware('permission:users.manage');
                Route::delete('invitations/{invitation}', [InvitationController::class, 'destroy'])->middleware('permission:users.manage');

                Route::get('customers/export', [CustomerController::class, 'export'])->middleware('permission:data.export');
                Route::get('customers', [CustomerController::class, 'index'])->middleware('permission:customers.view');
                Route::get('customers/{customer}', [CustomerController::class, 'show'])->middleware('permission:customers.view');
                Route::post('customers', [CustomerController::class, 'store'])->middleware('permission:customers.manage');
                Route::patch('customers/{customer}', [CustomerController::class, 'update'])->middleware('permission:customers.manage');
                Route::delete('customers/{customer}', [CustomerController::class, 'destroy'])->middleware('permission:customers.manage');

                Route::get('catalog-items', [CatalogItemController::class, 'index'])->middleware('permission:catalog.view');
                Route::get('catalog-items/{item}', [CatalogItemController::class, 'show'])->middleware('permission:catalog.view');
                Route::post('catalog-items', [CatalogItemController::class, 'store'])->middleware('permission:catalog.manage');
                Route::patch('catalog-items/{item}', [CatalogItemController::class, 'update'])->middleware('permission:catalog.manage');
                Route::delete('catalog-items/{item}', [CatalogItemController::class, 'destroy'])->middleware('permission:catalog.manage');
            });
        });

        // ---- platform admin ---------------------------------------------------
        Route::prefix('admin')->middleware(['verified.api', 'platform:super_admin'])->group(function () {
            Route::get('plans', [AdminPlanController::class, 'index']);
            Route::patch('plans/{plan}', [AdminPlanController::class, 'update']);
            Route::get('tenants', [AdminTenantController::class, 'index']);
            Route::post('tenants/{tenant}/suspend', [AdminTenantController::class, 'suspend']);
            Route::post('tenants/{tenant}/unsuspend', [AdminTenantController::class, 'unsuspend']);
            Route::post('tenants/{tenant}/extensions', [AdminTenantController::class, 'extend']);
            Route::post('tenants/{tenant}/overrides', [AdminTenantController::class, 'grantOverride']);
            Route::delete('overrides/{override}', [AdminTenantController::class, 'revokeOverride']);
            Route::get('subscription-payments', [AdminSubscriptionPaymentController::class, 'index']);
            Route::get('subscription-payments/{payment}', [AdminSubscriptionPaymentController::class, 'show']);
            Route::post('subscription-payments/{payment}/reconcile', [AdminSubscriptionPaymentController::class, 'reconcile']);
            Route::post('subscription-payments/{payment}/refund', [AdminSubscriptionPaymentController::class, 'refund']);
            Route::post('subscription-payments/{payment}/dispute', [AdminSubscriptionPaymentController::class, 'dispute']);
            Route::get('audit-events', [AdminSubscriptionPaymentController::class, 'auditEvents']);
        });
    });
});
