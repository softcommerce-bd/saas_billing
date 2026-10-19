<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Tenancy\Enums\Role;
use App\Modules\Tenancy\Models\TenantMembership;
use App\Modules\Tenancy\Support\TenantContext;
use App\Support\Audit\AuditLogger;
use App\Support\Http\ApiException;
use App\Support\Http\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class MemberController extends Controller
{
    public function index(TenantContext $context): JsonResponse
    {
        $members = TenantMembership::query()
            ->where('tenant_id', $context->tenantId())
            ->with(['user', 'roleRows'])
            ->orderBy('created_at')
            ->get();

        return new JsonResponse(['data' => $members->map(fn (TenantMembership $m) => $this->present($m))]);
    }

    public function update(Request $request, string $membership, TenantContext $context, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'roles' => ['sometimes', 'array', 'min:1'],
            'roles.*' => ['string', Rule::enum(Role::class)],
            'status' => ['sometimes', 'in:active,disabled'],
        ]);

        $member = $this->find($membership, $context);
        $actor = $context->membership();
        $roles = isset($data['roles']) ? array_map(fn ($r) => Role::from($r), $data['roles']) : null;

        // Only owners may grant, remove or modify the owner role.
        $touchesOwner = $member->hasRole(Role::Owner) || ($roles !== null && in_array(Role::Owner, $roles, true));
        if ($touchesOwner && ! $actor->hasRole(Role::Owner)) {
            throw ApiException::forbidden('Only a business owner can change owner access.');
        }

        DB::transaction(function () use ($member, $roles, $data, $context) {
            if ($roles !== null) {
                $member->syncRoles($roles);
            }
            if (isset($data['status'])) {
                $member->update(['status' => $data['status']]);
            }
            $this->assertOwnerRemains($context->tenantId());
        });

        $audit->record('membership.updated', $member, [
            'roles' => $data['roles'] ?? null,
            'status' => $data['status'] ?? null,
        ], tenantId: $context->tenantId());

        return new JsonResponse(['data' => $this->present($member->fresh(['user', 'roleRows']))]);
    }

    public function destroy(string $membership, TenantContext $context, AuditLogger $audit): JsonResponse
    {
        $member = $this->find($membership, $context);

        if ($member->hasRole(Role::Owner) && ! $context->membership()->hasRole(Role::Owner)) {
            throw ApiException::forbidden('Only a business owner can remove an owner.');
        }

        DB::transaction(function () use ($member, $context) {
            $member->delete();
            $this->assertOwnerRemains($context->tenantId());
        });

        $audit->record('membership.removed', $member, ['user_id' => $member->user_id], tenantId: $context->tenantId());

        return new JsonResponse(null, 204);
    }

    private function find(string $id, TenantContext $context): TenantMembership
    {
        return TenantMembership::query()
            ->where('tenant_id', $context->tenantId())
            ->with(['user', 'roleRows'])
            ->findOrFail($id);
    }

    private function assertOwnerRemains(string $tenantId): void
    {
        $owners = TenantMembership::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->whereHas('roleRows', fn ($q) => $q->where('role', Role::Owner->value))
            ->count();

        if ($owners === 0) {
            throw new ApiException(ErrorCode::Conflict, 'A company must keep at least one active business owner.', 409);
        }
    }

    private function present(TenantMembership $m): array
    {
        return [
            'id' => $m->id,
            'user' => ['id' => $m->user->id, 'name' => $m->user->name, 'email' => $m->user->email],
            'roles' => array_map(fn (Role $r) => $r->value, $m->roles()),
            'status' => $m->status,
            'joined_at' => $m->joined_at?->toIso8601String(),
        ];
    }
}
