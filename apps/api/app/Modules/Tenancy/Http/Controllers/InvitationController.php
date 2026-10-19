<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Subscriptions\Services\QuotaService;
use App\Modules\Tenancy\Enums\Role;
use App\Modules\Tenancy\Models\TenantInvitation;
use App\Modules\Tenancy\Notifications\TenantInvitationNotification;
use App\Modules\Tenancy\Support\TenantContext;
use App\Support\Audit\AuditLogger;
use App\Support\Http\ApiException;
use App\Support\Http\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class InvitationController extends Controller
{
    private const EXPIRY_DAYS = 7;

    public function index(): JsonResponse
    {
        $invitations = TenantInvitation::query()
            ->whereNull('accepted_at')->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->latest()->get();

        return new JsonResponse(['data' => $invitations->map(fn (TenantInvitation $i) => [
            'id' => $i->id,
            'email' => $i->email,
            'roles' => $i->roles,
            'expires_at' => $i->expires_at->toIso8601String(),
        ])]);
    }

    public function store(Request $request, TenantContext $context, QuotaService $quotas, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:254'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', Rule::enum(Role::class)],
        ]);
        $email = mb_strtolower(trim($data['email']));
        $tenant = $context->tenant();

        if (in_array(Role::Owner->value, $data['roles'], true) && ! $context->membership()->hasRole(Role::Owner)) {
            throw ApiException::forbidden('Only a business owner can invite another owner.');
        }

        $alreadyMember = $tenant->memberships()->whereHas('user', fn ($q) => $q->where('email', $email))->exists();
        if ($alreadyMember) {
            throw new ApiException(ErrorCode::Conflict, 'This person is already a member of your company.', 409);
        }

        // Replace any pending invitation for the same address.
        TenantInvitation::query()->where('email', $email)->whereNull('accepted_at')->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        $quotas->assertCanAddUser($tenant);

        $token = Str::random(48);
        $invitation = TenantInvitation::create([
            'email' => $email,
            'roles' => array_values(array_unique($data['roles'])),
            'token_hash' => TenantInvitation::hashToken($token),
            'invited_by_user_id' => $request->user()->id,
            'expires_at' => now()->addDays(self::EXPIRY_DAYS),
        ]);

        Notification::route('mail', $email)->notify(new TenantInvitationNotification($tenant->name, $request->user()->name, $token));
        $audit->record('invitation.created', $invitation, ['email' => $email, 'roles' => $invitation->roles]);

        return new JsonResponse(['data' => [
            'id' => $invitation->id,
            'email' => $invitation->email,
            'roles' => $invitation->roles,
            'expires_at' => $invitation->expires_at->toIso8601String(),
        ]], 201);
    }

    public function destroy(TenantInvitation $invitation, AuditLogger $audit): JsonResponse
    {
        $invitation->update(['revoked_at' => now()]);
        $audit->record('invitation.revoked', $invitation);

        return new JsonResponse(null, 204);
    }
}
