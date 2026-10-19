<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Http\Requests\RegisterRequest;
use App\Modules\Identity\Http\Resources\MeResource;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\RegistrationService;
use App\Support\Audit\AuditLogger;
use App\Support\Http\ApiException;
use App\Support\Http\ErrorCode;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Sanctum first-party SPA authentication (session cookie + CSRF), no bearer tokens
 * stored in the browser.
 */
class AuthController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 900;

    public function register(RegisterRequest $request, RegistrationService $registration): JsonResponse
    {
        ['user' => $user] = $registration->register($request->validated());

        event(new Registered($user));

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return (new MeResource($user))->response()->setStatusCode(201);
    }

    public function login(Request $request, AuditLogger $audit): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email', 'max:254'],
            'password' => ['required', 'string', 'max:200'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        $email = mb_strtolower(trim($credentials['email']));
        $key = 'login:'.sha1($email.'|'.$request->ip());

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw new ApiException(ErrorCode::TooManyRequests, 'Too many login attempts. Please try again later.', 429, [
                'retry_after' => RateLimiter::availableIn($key),
            ]);
        }

        $user = User::query()->where('email', $email)->first();

        // Hash even when the user does not exist to keep timing uniform.
        $valid = $user
            ? Hash::check($credentials['password'], $user->password)
            : Hash::check($credentials['password'], '$2y$12$'.Str::repeat('a', 53));

        if (! $valid) {
            RateLimiter::hit($key, self::DECAY_SECONDS);
            $audit->record('auth.login_failed', $user, ['email_hash' => sha1($email)], actorType: 'system');

            throw new ApiException(ErrorCode::InvalidCredentials, 'These credentials do not match our records.', 422);
        }

        if (! $user->is_active) {
            throw new ApiException(ErrorCode::AccountDisabled, 'Your account is disabled.', 403);
        }

        RateLimiter::clear($key);
        Auth::guard('web')->login($user, (bool) ($credentials['remember'] ?? false));
        $request->session()->regenerate();

        $user->forceFill(['last_login_at' => now()])->save();
        $audit->record('auth.login', $user, [], actorUserId: $user->id);

        return (new MeResource($user))->response();
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return new JsonResponse(null, 204);
    }

    public function me(Request $request): MeResource
    {
        return new MeResource($request->user());
    }
}
