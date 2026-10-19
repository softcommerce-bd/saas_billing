<?php

namespace App\Modules\Subscriptions\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Subscriptions\Models\EntitlementOverride;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Audit\AuditLogger;
use App\Support\Http\ApiException;
use App\Support\Http\ErrorCode;
use App\Support\Time\BusinessClock;

/**
 * Temporary platform override of read-only mode (e.g. to let a tenant record payments
 * while a gateway outage is resolved). Restricted to super admins, requires a reason,
 * is time-limited and fully audited. It never bypasses a suspension.
 */
final class EntitlementOverrideService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function grant(Tenant $tenant, User $admin, int $hours, string $reason): EntitlementOverride
    {
        if (! $admin->isSuperAdmin()) {
            throw ApiException::forbidden();
        }

        $maxHours = (int) config('subscriptions.override_max_days', 7) * 24;
        if ($hours < 1 || $hours > $maxHours) {
            throw new ApiException(ErrorCode::ValidationFailed, "Override length must be between 1 and {$maxHours} hours.", 422);
        }

        $now = BusinessClock::now();
        $override = EntitlementOverride::create([
            'tenant_id' => $tenant->id,
            'granted_by_user_id' => $admin->id,
            'reason' => $reason,
            'starts_at' => $now,
            'expires_at' => $now->addHours($hours),
        ]);

        $this->audit->record('entitlement_override.granted', $override, [
            'hours' => $hours, 'reason' => $reason,
        ], tenantId: $tenant->id, actorUserId: $admin->id, actorType: 'platform');

        return $override;
    }

    public function revoke(EntitlementOverride $override, User $admin): void
    {
        if (! $admin->isSuperAdmin()) {
            throw ApiException::forbidden();
        }

        $override->forceFill(['revoked_at' => BusinessClock::now(), 'revoked_by_user_id' => $admin->id])->save();

        $this->audit->record('entitlement_override.revoked', $override, [], tenantId: $override->tenant_id, actorUserId: $admin->id, actorType: 'platform');
    }
}
