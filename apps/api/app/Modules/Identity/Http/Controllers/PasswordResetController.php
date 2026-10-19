<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Audit\AuditLogger;
use App\Support\Http\ApiException;
use App\Support\Http\ErrorCode;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

class PasswordResetController extends Controller
{
    /**
     * Always answers the same way so the endpoint cannot be used to discover accounts.
     */
    public function sendLink(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:254']]);

        Password::sendResetLink(['email' => mb_strtolower(trim($data['email']))]);

        return new JsonResponse(['data' => ['message' => 'If an account exists for this email, a reset link has been sent.']]);
    }

    public function reset(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:200'],
            'email' => ['required', 'email', 'max:254'],
            'password' => ['required', 'string', 'confirmed', PasswordRule::defaults()],
        ]);
        $data['email'] = mb_strtolower(trim($data['email']));

        $status = Password::reset($data, function ($user, string $password) use ($audit) {
            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
            // Sign the user out everywhere.
            DB::table('sessions')->where('user_id', $user->id)->delete();
            $audit->record('auth.password_reset', $user, [], actorUserId: $user->id);
            event(new PasswordReset($user));
        });

        if ($status !== Password::PASSWORD_RESET) {
            throw new ApiException(ErrorCode::ValidationFailed, 'This password reset link is invalid or has expired.', 422);
        }

        return new JsonResponse(['data' => ['message' => 'Your password has been reset. Please sign in.']]);
    }
}
