<?php

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Identity\Models\User;
use App\Modules\Subscriptions\Services\SubscriptionEntitlementService;
use App\Modules\Tenancy\Models\TenantMembership;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class MeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $entitlements = app(SubscriptionEntitlementService::class);

        $memberships = $this->memberships()
            ->where('status', 'active')
            ->whereHas('tenant', fn ($q) => $q->whereNull('closed_at'))
            ->with(['tenant', 'roleRows'])
            ->get();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'email_verified' => $this->hasVerifiedEmail(),
            'locale' => $this->locale,
            'platform_role' => $this->platform_role,
            'memberships' => $memberships->map(fn (TenantMembership $m) => [
                'tenant' => [
                    'id' => $m->tenant->id,
                    'name' => $m->tenant->name,
                    'slug' => $m->tenant->slug,
                    'currency' => $m->tenant->currency,
                    'timezone' => $m->tenant->timezone,
                    'onboarding_completed' => $m->tenant->onboarding_completed_at !== null,
                ],
                'roles' => array_map(fn ($r) => $r->value, $m->roles()),
                'permissions' => $m->permissions(),
                'subscription' => $entitlements->stateFor($m->tenant)->toArray(),
            ])->values(),
        ];
    }
}
