<?php

namespace App\Modules\Subscriptions\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Subscriptions\Enums\OrderStatus;
use App\Modules\Subscriptions\Enums\PaymentStatus;
use App\Modules\Subscriptions\Enums\PeriodSource;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\Subscription;
use App\Modules\Subscriptions\Models\SubscriptionOrder;
use App\Modules\Subscriptions\Models\SubscriptionPayment;
use App\Modules\Subscriptions\Models\SubscriptionPeriod;
use App\Modules\Subscriptions\Notifications\SubscriptionActivatedNotification;
use App\Modules\Tenancy\Enums\Role;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantMembership;
use App\Support\Audit\AuditLogger;
use App\Support\Money\Money;
use App\Support\Time\BusinessClock;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The only code path that extends a subscription's validity.
 *
 * Every extension runs in a transaction holding row locks on the payment (when
 * applicable) and the tenant's subscription row, and is recorded in
 * subscription_periods. The unique index on subscription_periods.payment_id and the
 * applied_at marker make applying the same payment twice impossible.
 */
final class SubscriptionActivationService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function calendar(): CalendarPeriod
    {
        return new CalendarPeriod(BusinessClock::timezone());
    }

    /**
     * Create the tenant's subscription record at registration, with an optional trial.
     */
    public function initialize(Tenant $tenant, int $trialDays): Subscription
    {
        return DB::transaction(function () use ($tenant, $trialDays) {
            $subscription = Subscription::create([
                'tenant_id' => $tenant->id,
                'status' => SubscriptionStatus::PendingPayment,
                'currency' => 'BDT',
            ]);

            if ($trialDays > 0) {
                $now = BusinessClock::now();
                $window = $this->calendar()->extendByDays($now, null, $trialDays);

                SubscriptionPeriod::create([
                    'tenant_id' => $tenant->id,
                    'subscription_id' => $subscription->id,
                    'source' => PeriodSource::Trial,
                    'days' => $trialDays,
                    'period_start' => $now,
                    'period_end' => $window['expires_at'],
                    'reason' => 'Registration trial',
                ]);

                $subscription->update([
                    'status' => SubscriptionStatus::Active,
                    'is_trial' => true,
                    'starts_at' => $now,
                    'expires_at' => $window['expires_at'],
                    'activated_at' => $now,
                ]);

                $this->audit->record('subscription.trial_started', $subscription, [
                    'days' => $trialDays,
                    'expires_at' => $window['expires_at']->toIso8601String(),
                ], tenantId: $tenant->id);
            }

            return $subscription;
        });
    }

    /**
     * Extend the subscription from a payment that PaymentProcessor has already verified.
     * Idempotent: returns false when the payment was already applied.
     */
    public function applyVerifiedPayment(string $paymentId): bool
    {
        $applied = DB::transaction(function () use ($paymentId) {
            $payment = SubscriptionPayment::acrossTenants()->lockForUpdate()->findOrFail($paymentId);

            if ($payment->applied_at !== null) {
                return false;
            }

            if ($payment->status !== PaymentStatus::Completed || $payment->verified_trx_id === null || $payment->requires_review) {
                throw new LogicException('Only verified, completed payments can extend a subscription.');
            }

            $order = SubscriptionOrder::acrossTenants()->lockForUpdate()->findOrFail($payment->order_id);
            if ($payment->verified_amount_minor !== $order->amount_minor) {
                throw new LogicException('Verified amount does not match the order.');
            }

            $subscription = Subscription::query()->where('tenant_id', $payment->tenant_id)->lockForUpdate()->firstOrFail();

            $now = BusinessClock::now();
            $window = $this->calendar()->extendByMonths(
                $now,
                $subscription->status === SubscriptionStatus::Cancelled ? null : $subscription->expires_at,
                $subscription->anchor_day,
                $order->duration_months,
            );
            $isRenewal = $subscription->periods()->where('source', PeriodSource::Payment)->whereNull('revoked_at')->exists();
            $wasValid = $window['period_start']->notEqualTo($now);

            SubscriptionPeriod::create([
                'tenant_id' => $payment->tenant_id,
                'subscription_id' => $subscription->id,
                'source' => PeriodSource::Payment,
                'payment_id' => $payment->id,
                'plan_id' => $order->plan_id,
                'months' => $order->duration_months,
                'period_start' => $window['period_start'],
                'period_end' => $window['expires_at'],
            ]);

            $previousExpiry = $subscription->expires_at;
            $subscription->fill([
                'plan_id' => $order->plan_id,
                'status' => $subscription->status === SubscriptionStatus::Suspended ? SubscriptionStatus::Suspended : SubscriptionStatus::Active,
                'is_trial' => false,
                'expires_at' => $window['expires_at'],
                'anchor_day' => $window['anchor_day'],
                'amount_minor' => $order->amount_minor,
                'currency' => $order->currency,
                'payment_id' => $payment->id,
                'verified_transaction_id' => $payment->verified_trx_id,
                'cancelled_at' => null,
            ]);
            if (! $wasValid) {
                $subscription->starts_at = $now;
                $subscription->activated_at = $now;
            }
            if ($isRenewal) {
                $subscription->renewed_at = $now;
            }
            $subscription->save();

            $payment->forceFill(['applied_at' => $now])->save();
            $order->forceFill(['status' => OrderStatus::Paid, 'paid_at' => $now])->save();

            $this->audit->record('subscription.extended_by_payment', $subscription, [
                'payment_id' => $payment->id,
                'trx_id' => $payment->verified_trx_id,
                'order_id' => $order->id,
                'plan_code' => $order->plan_code,
                'months' => $order->duration_months,
                'previous_expires_at' => $previousExpiry?->toIso8601String(),
                'expires_at' => $window['expires_at']->toIso8601String(),
                'mode' => $wasValid ? 'extended_from_expiry' : 'started_from_activation',
            ], tenantId: $payment->tenant_id, actorType: 'system');

            return true;
        });

        if ($applied) {
            $this->notifyOwners($paymentId);
        }

        return $applied;
    }

    /**
     * Audited complimentary extension granted by a platform super admin. Recorded
     * separately from verified gateway payments (source = complimentary, no payment).
     */
    public function grantComplimentary(Tenant $tenant, User $admin, ?int $months, ?int $days, string $reason): SubscriptionPeriod
    {
        if (! $admin->isSuperAdmin()) {
            throw new LogicException('Only a super admin can grant complimentary extensions.');
        }
        if (($months === null) === ($days === null)) {
            throw new \InvalidArgumentException('Provide either months or days.');
        }

        return DB::transaction(function () use ($tenant, $admin, $months, $days, $reason) {
            $subscription = Subscription::query()->where('tenant_id', $tenant->id)->lockForUpdate()->firstOrFail();
            $now = BusinessClock::now();
            $current = $subscription->status === SubscriptionStatus::Cancelled ? null : $subscription->expires_at;

            $window = $months !== null
                ? $this->calendar()->extendByMonths($now, $current, $subscription->anchor_day, $months)
                : $this->calendar()->extendByDays($now, $current, $days);

            $period = SubscriptionPeriod::create([
                'tenant_id' => $tenant->id,
                'subscription_id' => $subscription->id,
                'source' => PeriodSource::Complimentary,
                'months' => $months,
                'days' => $days,
                'period_start' => $window['period_start'],
                'period_end' => $window['expires_at'],
                'granted_by_user_id' => $admin->id,
                'reason' => $reason,
            ]);

            $previous = $subscription->expires_at;
            $subscription->fill([
                'status' => $subscription->status === SubscriptionStatus::Suspended ? SubscriptionStatus::Suspended : SubscriptionStatus::Active,
                'expires_at' => $window['expires_at'],
                'anchor_day' => $window['anchor_day'] ?? $subscription->anchor_day,
                'cancelled_at' => null,
            ]);
            if ($current === null || $current->lessThanOrEqualTo($now)) {
                $subscription->starts_at = $now;
                $subscription->activated_at = $now;
            }
            $subscription->save();

            $this->audit->record('subscription.complimentary_extension', $subscription, [
                'months' => $months,
                'days' => $days,
                'reason' => $reason,
                'previous_expires_at' => $previous?->toIso8601String(),
                'expires_at' => $window['expires_at']->toIso8601String(),
            ], tenantId: $tenant->id, actorUserId: $admin->id, actorType: 'platform');

            return $period;
        });
    }

    /**
     * Revoke the validity granted by a refunded payment. Automatic only when that payment's
     * period is the latest active one; otherwise the payment is flagged for manual review.
     */
    public function revokePaymentPeriod(SubscriptionPayment $payment, string $reason): bool
    {
        return DB::transaction(function () use ($payment, $reason) {
            $subscription = Subscription::query()->where('tenant_id', $payment->tenant_id)->lockForUpdate()->firstOrFail();
            $period = SubscriptionPeriod::query()->where('payment_id', $payment->id)->whereNull('revoked_at')->first();

            if ($period === null) {
                return false;
            }

            $latest = $subscription->periods()->whereNull('revoked_at')->orderByDesc('period_end')->first();
            if ($latest?->id !== $period->id || ! $subscription->expires_at?->equalTo($period->period_end)) {
                SubscriptionPayment::acrossTenants()->whereKey($payment->id)->update(['requires_review' => true]);

                return false;
            }

            $period->forceFill(['revoked_at' => BusinessClock::now(), 'revoked_reason' => $reason])->save();
            $previous = $subscription->expires_at;
            $subscription->forceFill(['expires_at' => $period->period_start])->save();

            $this->audit->record('subscription.period_revoked', $subscription, [
                'payment_id' => $payment->id,
                'reason' => $reason,
                'previous_expires_at' => $previous?->toIso8601String(),
                'expires_at' => $period->period_start->toIso8601String(),
            ], tenantId: $payment->tenant_id, actorType: 'platform');

            return true;
        });
    }

    private function notifyOwners(string $paymentId): void
    {
        // Runs from callbacks and the reconciler, i.e. without a tenant context: no scoped relations.
        $payment = SubscriptionPayment::acrossTenants()->findOrFail($paymentId);
        $order = SubscriptionOrder::acrossTenants()->findOrFail($payment->order_id);
        $subscription = Subscription::forTenant($payment->tenant_id);

        $notification = new SubscriptionActivatedNotification(
            planCode: $order->plan_code,
            amount: Money::toDecimalString($payment->verified_amount_minor ?? $payment->amount_minor),
            transactionId: (string) $payment->verified_trx_id,
            orderReference: $order->merchant_invoice_number,
            validThrough: $this->calendar()->validThrough($subscription->expires_at)->format('j F Y'),
        );

        $owners = TenantMembership::query()
            ->where('tenant_id', $payment->tenant_id)
            ->where('status', 'active')
            ->whereHas('roleRows', fn ($q) => $q->where('role', Role::Owner->value))
            ->with('user')
            ->get()
            ->pluck('user');

        foreach ($owners as $owner) {
            $owner->notify($notification);
        }
    }
}
