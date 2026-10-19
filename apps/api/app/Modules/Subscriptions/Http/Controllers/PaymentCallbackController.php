<?php

namespace App\Modules\Subscriptions\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Subscriptions\Gateway\SubscriptionPaymentGateway;
use App\Modules\Subscriptions\Models\SubscriptionPayment;
use App\Modules\Subscriptions\Services\SubscriptionPaymentProcessor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Browser return URL from the hosted checkout. Unauthenticated by necessity, so it is
 * treated purely as a trigger: the payment is looked up by its gateway id and its real
 * outcome is fetched server-to-server. Nothing in the query string is trusted.
 */
class PaymentCallbackController extends Controller
{
    public function __invoke(
        Request $request,
        string $gateway,
        SubscriptionPaymentGateway $paymentGateway,
        SubscriptionPaymentProcessor $processor,
    ): RedirectResponse {
        $resultUrl = config('app.frontend_url').'/app/subscription/payment-result';
        $paymentId = (string) $request->query('paymentID', '');

        if ($gateway !== $paymentGateway->name() || $paymentId === '' || strlen($paymentId) > 64) {
            return redirect()->away($resultUrl.'?error=invalid');
        }

        $payment = SubscriptionPayment::acrossTenants()
            ->where('gateway', $paymentGateway->name())
            ->where('gateway_payment_id', $paymentId)
            ->first();

        if ($payment === null) {
            Log::notice('Payment callback for unknown payment id.', ['gateway' => $gateway]);

            return redirect()->away($resultUrl.'?error=invalid');
        }

        try {
            $payment = $processor->handleReturn($payment, (string) $request->query('status', ''));
        } catch (\Throwable $e) {
            // The reconciler will resolve it; never show a false success.
            Log::error('Payment callback processing failed.', ['payment_id' => $payment->id, 'error' => $e->getMessage()]);
        }

        return redirect()->away($resultUrl.'?'.http_build_query(['payment' => $payment->id]));
    }
}
