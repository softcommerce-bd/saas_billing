<?php

namespace App\Modules\Subscriptions\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Subscriptions\Gateway\Simulator\SimulatorGateway;
use App\Modules\Subscriptions\Gateway\SubscriptionPaymentGateway;
use App\Support\Money\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

/**
 * DEVELOPMENT-ONLY hosted-checkout stand-in. Routes exist only in local/testing.
 */
class PaymentSimulatorController extends Controller
{
    public function show(string $paymentId, SubscriptionPaymentGateway $gateway): Response
    {
        abort_unless($gateway instanceof SimulatorGateway, 404);
        $details = $gateway->details($paymentId);
        abort_if($details === null, 404);

        $amount = e(Money::toDecimalString($details['amount_minor']));
        $reference = e($details['merchant_reference']);
        $action = fn (string $d) => e(route('dev.payment-simulator.decide', ['paymentId' => $paymentId, 'decision' => $d]));
        $csrf = csrf_token();

        return response(<<<HTML
            <!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
            <title>Payment simulator (development)</title>
            <style>body{font-family:system-ui;max-width:420px;margin:40px auto;padding:0 16px}
            .warn{background:#fff3cd;border:1px solid #e0b400;padding:12px;border-radius:8px}
            form{display:inline}button{padding:10px 14px;margin:6px 4px 0 0;border-radius:6px;border:1px solid #888;cursor:pointer}</style></head>
            <body><p class="warn"><strong>DEVELOPMENT SIMULATOR — this is not bKash.</strong> No money moves.</p>
            <p>Reference: <code>{$reference}</code><br>Amount: BDT {$amount}</p>
            <form method="post" action="{$action('approve')}"><input type="hidden" name="_token" value="{$csrf}"><button>Approve payment</button></form>
            <form method="post" action="{$action('cancel')}"><input type="hidden" name="_token" value="{$csrf}"><button>Cancel</button></form>
            <form method="post" action="{$action('fail')}"><input type="hidden" name="_token" value="{$csrf}"><button>Fail</button></form>
            </body></html>
            HTML);
    }

    public function decide(string $paymentId, string $decision, SubscriptionPaymentGateway $gateway): RedirectResponse
    {
        abort_unless($gateway instanceof SimulatorGateway, 404);
        abort_unless(in_array($decision, ['approve', 'cancel', 'fail'], true), 404);

        $redirect = $gateway->decide($paymentId, $decision);
        abort_if($redirect === null, 404);

        return redirect()->away($redirect);
    }
}
