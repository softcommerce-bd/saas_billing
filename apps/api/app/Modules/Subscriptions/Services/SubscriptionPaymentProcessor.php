<?php

namespace App\Modules\Subscriptions\Services;

use App\Modules\Subscriptions\Enums\OrderStatus;
use App\Modules\Subscriptions\Enums\PaymentStatus;
use App\Modules\Subscriptions\Gateway\GatewayPaymentState;
use App\Modules\Subscriptions\Gateway\GatewayPaymentStatus;
use App\Modules\Subscriptions\Gateway\SubscriptionPaymentGateway;
use App\Modules\Subscriptions\Models\SubscriptionOrder;
use App\Modules\Subscriptions\Models\SubscriptionPayment;
use App\Support\Audit\AuditLogger;
use App\Support\Time\BusinessClock;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Resolves the outcome of subscription payments using server-to-server gateway calls
 * and verifies every success before the subscription is extended.
 *
 *  - Browser callbacks are hints: "success" triggers execute(), anything else triggers
 *    query(). The callback parameters are never used as evidence of payment.
 *  - A completed payment is accepted only if payment id, merchant reference, currency
 *    and amount all match the server-side order.
 *  - Processing of a payment is serialised with a distributed lock, and activation is
 *    idempotent (see SubscriptionActivationService).
 */
final class SubscriptionPaymentProcessor
{
    public function __construct(
        private readonly SubscriptionPaymentGateway $gateway,
        private readonly SubscriptionActivationService $activation,
        private readonly AuditLogger $audit,
        private readonly Cache $cache,
    ) {}

    /**
     * Handle the customer's return from the hosted checkout.
     */
    public function handleReturn(SubscriptionPayment $payment, string $statusHint): SubscriptionPayment
    {
        return $this->locked($payment, function (SubscriptionPayment $payment) use ($statusHint) {
            if ($statusHint === 'success') {
                $state = $this->gateway->execute($payment->gateway_payment_id);
                $payment = $this->apply($payment, $state, 'execute');

                // Execute may time out or report an error although the payment went through.
                if ($payment->status->isOpen()) {
                    $payment = $this->apply($payment, $this->gateway->query($payment->gateway_payment_id), 'query_after_execute');
                }

                return $payment;
            }

            return $this->apply($payment, $this->gateway->query($payment->gateway_payment_id), 'query_on_'.$this->safeHint($statusHint));
        });
    }

    /**
     * Re-check an unresolved payment (scheduled reconciliation or admin action).
     */
    public function reconcile(SubscriptionPayment $payment): SubscriptionPayment
    {
        return $this->locked($payment, function (SubscriptionPayment $payment) {
            if ($payment->gateway_payment_id === null) {
                return $this->markAbandonedIfStale($payment);
            }

            $payment = $this->apply($payment, $this->gateway->query($payment->gateway_payment_id), 'reconcile');

            return $payment->status->isOpen() ? $this->markAbandonedIfStale($payment) : $payment;
        });
    }

    private function apply(SubscriptionPayment $payment, GatewayPaymentState $state, string $stage): SubscriptionPayment
    {
        $payment->appendGatewayPayload($stage, $state->raw + ['_normalized_status' => $state->status->value]);
        $payment->last_checked_at = BusinessClock::now();
        $payment->check_attempts++;

        if ($state->paymentId !== '' && $state->paymentId !== $payment->gateway_payment_id) {
            return $this->flag($payment, PaymentStatus::Unknown, 'PAYMENT_ID_MISMATCH', 'Gateway returned a different payment id.');
        }

        switch ($state->status) {
            case GatewayPaymentStatus::Completed:
                return $this->acceptCompleted($payment, $state);

            case GatewayPaymentStatus::Pending:
                $payment->save();

                return $payment;

            case GatewayPaymentStatus::Failed:
            case GatewayPaymentStatus::Cancelled:
                $payment->status = $state->status === GatewayPaymentStatus::Failed ? PaymentStatus::Failed : PaymentStatus::Cancelled;
                $payment->failure_code = $state->errorCode ?? strtoupper($state->status->value);
                $payment->failure_message = $state->errorMessage;
                $payment->save();
                $this->audit->record('subscription_payment.'.$payment->status->value, $payment, ['stage' => $stage], actorType: 'system');

                return $payment;

            case GatewayPaymentStatus::Unknown:
            default:
                $payment->status = PaymentStatus::Unknown;
                $payment->failure_code = $state->errorCode;
                $payment->failure_message = $state->errorMessage;
                $payment->save();

                return $payment;
        }
    }

    private function acceptCompleted(SubscriptionPayment $payment, GatewayPaymentState $state): SubscriptionPayment
    {
        $order = SubscriptionOrder::acrossTenants()->findOrFail($payment->order_id);

        $mismatches = [];
        if ($state->transactionId === null) {
            $mismatches[] = 'missing_transaction_id';
        }
        if ($state->amountMinor !== $order->amount_minor) {
            $mismatches[] = 'amount';
        }
        if ($state->currency !== $order->currency) {
            $mismatches[] = 'currency';
        }
        if ($state->merchantReference !== $order->merchant_invoice_number) {
            $mismatches[] = 'merchant_reference';
        }

        $payment->status = PaymentStatus::Completed;
        $payment->verified_amount_minor = $state->amountMinor;
        $payment->verified_at = BusinessClock::now();

        if ($mismatches !== []) {
            // Money may have moved, but it does not match what we sold: never activate.
            $payment->appendGatewayPayload('verification_failed', ['mismatches' => $mismatches, 'trx_id' => $state->transactionId]);

            return $this->flag($payment, PaymentStatus::Completed, 'VERIFICATION_MISMATCH', 'Mismatch: '.implode(', ', $mismatches));
        }

        if ($order->status === OrderStatus::Paid) {
            $payment->appendGatewayPayload('duplicate_payment', ['trx_id' => $state->transactionId]);

            return $this->flag($payment, PaymentStatus::Completed, 'DUPLICATE_PAYMENT_FOR_ORDER', 'Order was already paid; refund required.');
        }

        $payment->verified_trx_id = $state->transactionId;
        $payment->failure_code = null;
        $payment->failure_message = null;

        try {
            DB::transaction(fn () => $payment->save());
        } catch (UniqueConstraintViolationException) {
            // The same transaction id is already attached to another payment.
            $payment->verified_trx_id = null;
            $payment->appendGatewayPayload('duplicate_transaction', ['trx_id' => $state->transactionId]);

            return $this->flag($payment, PaymentStatus::Completed, 'DUPLICATE_TRANSACTION', 'Transaction id already recorded.');
        }

        $this->audit->record('subscription_payment.verified', $payment, [
            'trx_id' => $payment->verified_trx_id,
            'amount_minor' => $payment->verified_amount_minor,
        ], actorType: 'system');

        $this->activation->applyVerifiedPayment($payment->id);

        return $payment->refresh();
    }

    private function flag(SubscriptionPayment $payment, PaymentStatus $status, string $code, string $message): SubscriptionPayment
    {
        $payment->status = $status;
        $payment->requires_review = true;
        $payment->failure_code = $code;
        $payment->failure_message = $message;
        $payment->save();

        $this->audit->record('subscription_payment.requires_review', $payment, ['code' => $code, 'message' => $message], actorType: 'system');

        return $payment;
    }

    private function markAbandonedIfStale(SubscriptionPayment $payment): SubscriptionPayment
    {
        $hours = (int) config('subscriptions.reconcile.abandon_after_hours', 24);

        if ($payment->status->isOpen() && $payment->created_at->lt(now()->subHours($hours))) {
            $payment->status = PaymentStatus::Expired;
            $payment->failure_code = 'ABANDONED';
            $payment->save();
            $this->audit->record('subscription_payment.expired', $payment, [], actorType: 'system');
        }

        return $payment;
    }

    /**
     * @param  callable(SubscriptionPayment): SubscriptionPayment  $callback
     */
    private function locked(SubscriptionPayment $payment, callable $callback): SubscriptionPayment
    {
        return $this->cache->lock('subscription-payment:'.$payment->id, 120)->block(30, function () use ($payment, $callback) {
            $fresh = SubscriptionPayment::acrossTenants()->findOrFail($payment->id);

            // Final states are immutable here; repeated callbacks are harmless no-ops.
            if (! $fresh->status->isOpen()) {
                return $fresh;
            }

            return $callback($fresh);
        });
    }

    private function safeHint(string $hint): string
    {
        return in_array($hint, ['failure', 'cancel'], true) ? $hint : 'other';
    }
}
