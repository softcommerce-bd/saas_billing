<?php

namespace App\Modules\Subscriptions\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Subscriptions\Enums\PaymentStatus;
use App\Modules\Subscriptions\Gateway\GatewayException;
use App\Modules\Subscriptions\Gateway\SubscriptionPaymentGateway;
use App\Modules\Subscriptions\Models\SubscriptionPayment;
use App\Support\Audit\AuditLogger;
use App\Support\Http\ApiException;
use App\Support\Http\ErrorCode;
use App\Support\Time\BusinessClock;
use Illuminate\Support\Facades\DB;

/**
 * Platform-side refunds and disputes for subscription payments.
 *
 * Policy (docs/subscriptions/subscription-lifecycle.md):
 *  - Refunds are issued through the gateway; the local record changes only after the
 *    gateway confirms the refund.
 *  - A FULL refund of an applied payment revokes the validity it granted, automatically
 *    when it is the latest period, otherwise the payment is flagged for manual review.
 *  - Partial refunds and disputes never change validity automatically.
 */
final class SubscriptionRefundService
{
    public function __construct(
        private readonly SubscriptionPaymentGateway $gateway,
        private readonly SubscriptionActivationService $activation,
        private readonly AuditLogger $audit,
    ) {}

    public function refund(SubscriptionPayment $payment, User $admin, int $amountMinor, string $reason): SubscriptionPayment
    {
        $this->assertSuperAdmin($admin);

        if (! in_array($payment->status, [PaymentStatus::Completed, PaymentStatus::PartiallyRefunded], true)) {
            throw new ApiException(ErrorCode::Conflict, 'Only completed payments can be refunded.', 409);
        }

        $trxId = $payment->verified_trx_id ?? $this->unverifiedTrxId($payment);
        $refundable = ($payment->verified_amount_minor ?? $payment->amount_minor) - $payment->refunded_amount_minor;

        if ($trxId === null || $amountMinor < 1 || $amountMinor > $refundable) {
            throw new ApiException(ErrorCode::ValidationFailed, 'Invalid refund amount.', 422, ['refundable_minor' => $refundable]);
        }

        if ($payment->gateway !== $this->gateway->name()) {
            throw new ApiException(ErrorCode::PaymentGatewayUnavailable, 'The original payment gateway is not configured.', 503);
        }

        try {
            $result = $this->gateway->refund($payment->gateway_payment_id, $trxId, $amountMinor, $reason);
        } catch (GatewayException $e) {
            throw new ApiException(ErrorCode::PaymentGatewayUnavailable, 'Refund request failed: '.$e->getMessage(), 502);
        }

        if (! $result->succeeded) {
            $this->audit->record('subscription_payment.refund_failed', $payment, [
                'amount_minor' => $amountMinor, 'code' => $result->errorCode, 'message' => $result->errorMessage,
            ], actorUserId: $admin->id, actorType: 'platform');

            throw new ApiException(ErrorCode::Conflict, 'The gateway did not confirm the refund: '.($result->errorMessage ?? 'unknown error'), 409);
        }

        $payment = DB::transaction(function () use ($payment, $amountMinor, $result, $reason, $admin) {
            $locked = SubscriptionPayment::acrossTenants()->lockForUpdate()->findOrFail($payment->id);
            $locked->refunded_amount_minor += $amountMinor;
            $locked->refunded_at = BusinessClock::now();
            $locked->status = $locked->refunded_amount_minor >= ($locked->verified_amount_minor ?? $locked->amount_minor)
                ? PaymentStatus::Refunded
                : PaymentStatus::PartiallyRefunded;
            $locked->appendGatewayPayload('refund', $result->raw);
            $locked->save();

            $this->audit->record('subscription_payment.refunded', $locked, [
                'amount_minor' => $amountMinor,
                'refund_trx_id' => $result->refundTransactionId,
                'reason' => $reason,
            ], actorUserId: $admin->id, actorType: 'platform');

            return $locked;
        });

        if ($payment->status === PaymentStatus::Refunded && $payment->applied_at !== null) {
            $this->activation->revokePaymentPeriod($payment, 'Refunded: '.$reason);
        } elseif ($payment->status === PaymentStatus::PartiallyRefunded) {
            SubscriptionPayment::acrossTenants()->whereKey($payment->id)->update(['requires_review' => true]);
        }

        return $payment->refresh();
    }

    public function markDisputed(SubscriptionPayment $payment, User $admin, string $note): SubscriptionPayment
    {
        $this->assertSuperAdmin($admin);

        $payment->forceFill([
            'disputed_at' => BusinessClock::now(),
            'dispute_note' => $note,
            'requires_review' => true,
        ])->save();

        $this->audit->record('subscription_payment.disputed', $payment, ['note' => $note], actorUserId: $admin->id, actorType: 'platform');

        return $payment;
    }

    private function unverifiedTrxId(SubscriptionPayment $payment): ?string
    {
        // Mismatched/duplicate payments keep their gateway trx id in the payload history only.
        foreach (array_reverse($payment->gateway_payload ?? []) as $entry) {
            if (! empty($entry['response']['trx_id'])) {
                return (string) $entry['response']['trx_id'];
            }
            if (! empty($entry['response']['trxID'])) {
                return (string) $entry['response']['trxID'];
            }
        }

        return null;
    }

    private function assertSuperAdmin(User $admin): void
    {
        if (! $admin->isSuperAdmin()) {
            throw ApiException::forbidden();
        }
    }
}
