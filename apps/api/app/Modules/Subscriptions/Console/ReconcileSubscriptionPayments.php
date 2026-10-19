<?php

namespace App\Modules\Subscriptions\Console;

use App\Modules\Subscriptions\Enums\PaymentStatus;
use App\Modules\Subscriptions\Models\SubscriptionPayment;
use App\Modules\Subscriptions\Services\SubscriptionActivationService;
use App\Modules\Subscriptions\Services\SubscriptionPaymentProcessor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Resolves subscription payments whose outcome is still open (lost callbacks, execute
 * timeouts) by querying the gateway, and finishes activations that were verified but
 * not yet applied (e.g. a crash between verification and activation).
 */
class ReconcileSubscriptionPayments extends Command
{
    protected $signature = 'subscriptions:reconcile-payments {--limit=100}';

    protected $description = 'Re-query open subscription payments and complete pending activations';

    public function handle(SubscriptionPaymentProcessor $processor, SubscriptionActivationService $activation): int
    {
        $minAge = (int) config('subscriptions.reconcile.min_age_minutes', 3);
        $maxAttempts = (int) config('subscriptions.reconcile.max_attempts', 30);

        $open = SubscriptionPayment::acrossTenants()
            ->whereIn('status', [PaymentStatus::Initiated, PaymentStatus::Unknown])
            ->where('created_at', '<=', now()->subMinutes($minAge))
            ->where('check_attempts', '<', $maxAttempts)
            ->orderBy('last_checked_at')
            ->limit((int) $this->option('limit'))
            ->get();

        foreach ($open as $payment) {
            try {
                $processor->reconcile($payment);
            } catch (\Throwable $e) {
                Log::error('Subscription payment reconciliation failed.', ['payment_id' => $payment->id, 'error' => $e->getMessage()]);
            }
        }

        $unapplied = SubscriptionPayment::acrossTenants()
            ->where('status', PaymentStatus::Completed)
            ->whereNotNull('verified_trx_id')
            ->whereNull('applied_at')
            ->where('requires_review', false)
            ->pluck('id');

        foreach ($unapplied as $paymentId) {
            $activation->applyVerifiedPayment($paymentId);
        }

        $this->info("Checked {$open->count()} open payments; completed {$unapplied->count()} pending activations.");

        return self::SUCCESS;
    }
}
