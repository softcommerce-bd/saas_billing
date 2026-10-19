<?php

namespace App\Providers;

use App\Modules\Subscriptions\Gateway\Bkash\BkashGateway;
use App\Modules\Subscriptions\Gateway\Simulator\SimulatorGateway;
use App\Modules\Subscriptions\Gateway\SubscriptionPaymentGateway;
use App\Modules\Subscriptions\Services\BusinessWriteGuard;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Reset for every request and queued job (also under Octane / long-running workers).
        $this->app->scoped(TenantContext::class);
        $this->app->scoped(BusinessWriteGuard::class);

        $this->app->singleton(SubscriptionPaymentGateway::class, function ($app) {
            if (config('subscriptions.gateway') === 'simulator') {
                // Throws outside local/testing: the simulator can never take production traffic.
                return new SimulatorGateway($app->make(Cache::class), $app->environment());
            }

            return new BkashGateway($app->make(Http::class), $app->make(Cache::class), config('bkash'));
        });
    }

    public function boot(): void
    {
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        Password::defaults(function () {
            $rule = Password::min(10)->letters()->numbers();

            return $this->app->isProduction() ? $rule->uncompromised() : $rule;
        });

        RateLimiter::for('auth', fn (Request $request) => [
            Limit::perMinute(10)->by('auth:'.$request->ip()),
        ]);

        RateLimiter::for('payment-callback', fn (Request $request) => Limit::perMinute(30)->by('cb:'.$request->ip()));

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->getAuthIdentifier() ?: $request->ip()));
    }
}
