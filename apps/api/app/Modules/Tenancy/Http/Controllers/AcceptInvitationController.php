<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Http\Resources\MeResource;
use App\Modules\Identity\Models\User;
use App\Modules\Subscriptions\Services\QuotaService;
use App\Modules\Subscriptions\Services\SubscriptionEntitlementService;
use App\Modules\Tenancy\Enums\Role;
use App\Modules\Tenancy\Models\TenantInvitation;
use App\Modules\Tenancy\Models\TenantMembership;
use App\Support\Audit\AuditLogger;
use App\Support\Http\ApiException;
use App\Support\Http\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;

/**
 * Accepting an invitation adds an employee, which is a business write: it requires an
 * entitled subscription and respects the user quota.
 */
class AcceptInvitationController extends Controller
{
    public function __construct(
        private readonly SubscriptionEntitlementService $entitlements,
        private readonly QuotaService $quotas,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Existing, signed-in user whose email matches the invitation.
     */
    public function accept(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:100']]);
        $invitation = $this->pendingInvitation($data['token']);
        $user = $request->user();

        if ($user->email !== $invitation->email) {
            throw ApiException::forbidden('This invitation was sent to a different email address.');
        }

        $this->join($invitation, $user);

        return (new MeResource($user))->response();
    }

    /**
     * New user signing up from an invitation link. The emailed token proves the address.
     */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);
        $invitation = $this->pendingInvitation($data['token']);

        if (User::query()->where('email', $invitation->email)->exists()) {
            throw new ApiException(ErrorCode::Conflict, 'An account already exists for this email. Please sign in to accept.', 409);
        }

        $user = DB::transaction(function () use ($invitation, $data) {
            $user = User::create(['name' => $data['name'], 'email' => $invitation->email, 'password' => $data['password']]);
            $user->forceFill(['email_verified_at' => now()])->save();
            $this->join($invitation, $user);

            return $user;
        });

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return (new MeResource($user))->response()->setStatusCode(201);
    }

    private function pendingInvitation(string $token): TenantInvitation
    {
        $invitation = TenantInvitation::acrossTenants()
            ->with('tenant')
            ->where('token_hash', TenantInvitation::hashToken($token))
            ->first();

        if ($invitation === null || ! $invitation->isPending() || $invitation->tenant->isClosed()) {
            throw new ApiException(ErrorCode::NotFound, 'This invitation is invalid or has expired.', 404);
        }

        return $invitation;
    }

    private function join(TenantInvitation $invitation, User $user): void
    {
        $tenant = $invitation->tenant;
        $this->entitlements->checkSubscription($tenant)->throwIfDenied();

        DB::transaction(function () use ($invitation, $user, $tenant) {
            $locked = TenantInvitation::acrossTenants()->lockForUpdate()->findOrFail($invitation->id);
            if (! $locked->isPending()) {
                throw new ApiException(ErrorCode::NotFound, 'This invitation is invalid or has expired.', 404);
            }

            $locked->forceFill(['accepted_at' => now()])->save();

            // The accepted invitation no longer counts as pending, so check the remaining capacity.
            $this->quotas->assertCanAddUser($tenant);

            $membership = TenantMembership::firstOrCreate(
                ['tenant_id' => $tenant->id, 'user_id' => $user->id],
                ['status' => 'active', 'invited_by_user_id' => $locked->invited_by_user_id, 'joined_at' => now()],
            );
            $membership->syncRoles(array_map(fn (string $r) => Role::from($r), $locked->roles));

            $this->audit->record('invitation.accepted', $membership, ['invitation_id' => $locked->id], tenantId: $tenant->id, actorUserId: $user->id);
        });
    }
}
