<?php

namespace App\Modules\Subscriptions\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Subscriptions\Enums\AccessMode;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\EntitlementOverride;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Tenancy\Enums\Permission;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantMembership;
use App\Support\Http\ErrorCode;
use App\Support\Time\BusinessClock;
use Carbon\CarbonImmutable;

/**
 * Single source of truth for "may this tenant change its business data right now?".
 *
 * Access is derived from the authoritative expires_at timestamp, platform suspension
 * and active overrides at the moment of the check. The stored subscription status is
 * NOT trusted for enforcement, so access ends exactly at expiry even if no scheduled
 * job has run.
 */
final class SubscriptionEntitlementService
{
    public const EXPIRED_MESSAGE = 'Renew your subscription to continue managing your business.';

    public function stateFor(Tenant $tenant, ?CarbonImmutable $at = null): EntitlementState
    {
        $at ??= BusinessClock::now();
        $subscription = Subscription::forTenant($tenant->id);
        $calendar = new CalendarPeriod(BusinessClock::timezone());

        $expiresAt = $subscription?->expires_at;
        $validThrough = $expiresAt ? $calendar->validThrough($expiresAt) : null;
        $planCode = $subscription?->plan?->code?->value;
        $isTrial = (bool) $subscription?->is_trial;

        $override = EntitlementOverride::query()
            ->where('tenant_id', $tenant->id)
            ->whereNull('revoked_at')
            ->where('starts_at', '<=', $at)
            ->where('expires_at', '>', $at)
            ->orderByDesc('expires_at')
            ->first();

        $make = fn (AccessMode $mode, ?string $reason, bool $canRenew) => new EntitlementState(
            mode: $mode,
            reason: $reason,
            expiresAt: $expiresAt,
            validThrough: $validThrough,
            isTrial: $isTrial,
            planCode: $planCode,
            overrideActive: $override !== null && $mode === AccessMode::Full,
            overrideExpiresAt: $mode === AccessMode::Full ? $override?->expires_at : null,
            canRenew: $canRenew,
        );

        if ($tenant->isSuspended() || $tenant->isClosed() || $subscription?->status === SubscriptionStatus::Suspended) {
            return $make(AccessMode::Suspended, 'suspended', false);
        }

        $subscriptionValid = $subscription !== null
            && $subscription->status !== SubscriptionStatus::Cancelled
            && $expiresAt !== null
            && $expiresAt->greaterThan($at);

        if ($subscriptionValid) {
            return $make(AccessMode::Full, null, true);
        }

        if ($override !== null) {
            return $make(AccessMode::Full, null, true);
        }

        $reason = match (true) {
            $subscription === null, $subscription->status === SubscriptionStatus::PendingPayment && $expiresAt === null => 'pending_payment',
            $subscription->status === SubscriptionStatus::Cancelled => 'cancelled',
            default => 'expired',
        };

        return $make(AccessMode::ReadOnly, $reason, true);
    }

    /**
     * Full check for a business mutation: user, membership, subscription and role permission.
     * $user is null for system actors (queued jobs, scheduler), which skip the membership checks.
     */
    public function canPerformBusinessWrite(Tenant $tenant, ?User $user, Permission $action, ?TenantMembership $membership = null): WriteDecision
    {
        if ($user !== null) {
            if (! $user->is_active) {
                return WriteDecision::deny(ErrorCode::AccountDisabled, 'Your account is disabled.');
            }

            $membership ??= TenantMembership::query()
                ->where('tenant_id', $tenant->id)
                ->where('user_id', $user->id)
                ->with('roleRows')
                ->first();

            if ($membership === null || ! $membership->isActive()) {
                return WriteDecision::deny(ErrorCode::TenantNotFound, 'Company not found.');
            }
        }

        $decision = $this->checkSubscription($tenant);
        if (! $decision->allowed) {
            return $decision;
        }

        if ($membership !== null && ! $membership->can($action)) {
            return WriteDecision::deny(ErrorCode::Forbidden, 'You are not allowed to perform this action.', $decision->state);
        }

        return $decision;
    }

    /**
     * Tenant-wide subscription gate used by HTTP middleware and queued jobs.
     */
    public function checkSubscription(Tenant $tenant): WriteDecision
    {
        $state = $this->stateFor($tenant);

        return match ($state->mode) {
            AccessMode::Full => WriteDecision::allow($state),
            AccessMode::Suspended => WriteDecision::deny(
                ErrorCode::TenantSuspended,
                'This company account is suspended. Please contact support.',
                $state,
            ),
            AccessMode::ReadOnly => WriteDecision::deny(ErrorCode::SubscriptionExpired, self::EXPIRED_MESSAGE, $state),
        };
    }
}
