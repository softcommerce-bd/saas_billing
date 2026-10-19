<?php

namespace App\Modules\Subscriptions\Services;

use App\Modules\Subscriptions\Models\EntitlementProfile;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantInvitation;
use App\Support\Http\ApiException;
use App\Support\Http\ErrorCode;

/**
 * Feature quotas from the tenant's entitlement profile (independent of plan duration).
 * Tenants without a paid plan (trial) use the profile marked as default.
 */
final class QuotaService
{
    public const DEFAULT_PROFILE_CODE = 'standard';

    public function profileFor(Tenant $tenant): ?EntitlementProfile
    {
        $subscription = Subscription::forTenant($tenant->id);

        return $subscription?->plan?->entitlementProfile
            ?? EntitlementProfile::query()->where('code', self::DEFAULT_PROFILE_CODE)->first();
    }

    public function assertCanAddUser(Tenant $tenant): void
    {
        $limit = $this->profileFor($tenant)?->max_users;
        if ($limit === null) {
            return;
        }

        $members = $tenant->memberships()->where('status', 'active')->count();
        $pending = TenantInvitation::acrossTenants()
            ->where('tenant_id', $tenant->id)
            ->whereNull('accepted_at')->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->count();

        if ($members + $pending >= $limit) {
            throw new ApiException(ErrorCode::QuotaExceeded, "Your package allows up to {$limit} users.", 422, ['limit' => $limit]);
        }
    }
}
