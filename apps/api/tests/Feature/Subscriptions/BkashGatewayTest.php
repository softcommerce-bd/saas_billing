<?php

namespace Tests\Feature\Subscriptions;

use App\Modules\Subscriptions\Gateway\Bkash\BkashGateway;
use App\Modules\Subscriptions\Gateway\CheckoutRequest;
use App\Modules\Subscriptions\Gateway\GatewayException;
use App\Modules\Subscriptions\Gateway\GatewayPaymentStatus;
use App\Modules\Subscriptions\Gateway\Simulator\SimulatorGateway;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * HTTP-level tests of the bKash adapter against a faked transport. These verify our
 * request construction and response handling; they do NOT prove compatibility with the
 * real bKash API, which still requires sandbox testing with merchant credentials.
 */
class BkashGatewayTest extends TestCase
{
    private const BASE = 'https://bkash.test/v1.2.0-beta';

    private function gateway(): BkashGateway
    {
        return new BkashGateway(app(Factory::class), Cache::store(), array_merge(config('bkash'), [
            'enabled' => true,
            'base_url' => self::BASE,
            'app_key' => 'test-app-key',
            'app_secret' => 'test-app-secret',
            'username' => 'test-user',
            'password' => 'test-pass',
        ]));
    }

    private function fakeToken(): array
    {
        return [self::BASE.'/tokenized/checkout/token/grant' => Http::response(['statusCode' => '0000', 'id_token' => 'ID-TOKEN-1', 'expires_in' => 3600, 'refresh_token' => 'R'])];
    }

    public function test_is_unavailable_without_credentials(): void
    {
        $gateway = new BkashGateway(app(Factory::class), Cache::store(), config('bkash'));

        $this->assertFalse($gateway->isAvailable());
    }

    public function test_create_checkout_sends_documented_fields_and_caches_token(): void
    {
        Http::fake($this->fakeToken() + [
            self::BASE.'/tokenized/checkout/create' => Http::response([
                'statusCode' => '0000', 'paymentID' => 'PAY123', 'bkashURL' => 'https://sandbox.bka.sh/checkout/PAY123', 'transactionStatus' => 'Initiated',
            ]),
        ]);
        $gateway = $this->gateway();

        $session = $gateway->createCheckout(new CheckoutRequest(285000, 'BDT', 'SUB-ORDER1', 'payer1', 'https://api.test/callback'));
        $gateway->createCheckout(new CheckoutRequest(100000, 'BDT', 'SUB-ORDER2', 'payer1', 'https://api.test/callback'));

        $this->assertSame('PAY123', $session->paymentId);
        $this->assertSame('https://sandbox.bka.sh/checkout/PAY123', $session->redirectUrl);

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/token/grant')
            && $r->header('username')[0] === 'test-user'
            && $r['app_key'] === 'test-app-key');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/checkout/create')
            && $r->header('Authorization')[0] === 'ID-TOKEN-1'
            && $r->header('X-App-Key')[0] === 'test-app-key'
            && $r['amount'] === '2850.00'
            && $r['currency'] === 'BDT'
            && $r['intent'] === 'sale'
            && $r['merchantInvoiceNumber'] === 'SUB-ORDER1'
            && $r['callbackURL'] === 'https://api.test/callback');
        // Token fetched once and reused.
        Http::assertSentCount(3);
    }

    public function test_create_checkout_failure_throws(): void
    {
        Http::fake($this->fakeToken() + [
            self::BASE.'/tokenized/checkout/create' => Http::response(['statusCode' => '2001', 'statusMessage' => 'Invalid App Key']),
        ]);

        $this->expectException(GatewayException::class);
        $this->gateway()->createCheckout(new CheckoutRequest(1000, 'BDT', 'SUB-1', 'p', 'https://x.test'));
    }

    public function test_execute_parses_completed_payment_and_masks_wallet(): void
    {
        Http::fake($this->fakeToken() + [
            self::BASE.'/tokenized/checkout/execute' => Http::response([
                'statusCode' => '0000', 'paymentID' => 'PAY123', 'trxID' => 'TRX9', 'transactionStatus' => 'Completed',
                'amount' => '2850.00', 'currency' => 'BDT', 'merchantInvoiceNumber' => 'SUB-ORDER1', 'customerMsisdn' => '01770618575',
            ]),
        ]);

        $state = $this->gateway()->execute('PAY123');

        $this->assertSame(GatewayPaymentStatus::Completed, $state->status);
        $this->assertSame('TRX9', $state->transactionId);
        $this->assertSame(285000, $state->amountMinor);
        $this->assertSame('SUB-ORDER1', $state->merchantReference);
        $this->assertSame('********575', $state->raw['customerMsisdn']);
    }

    public function test_error_status_code_is_unknown_not_failed(): void
    {
        Http::fake($this->fakeToken() + [
            self::BASE.'/tokenized/checkout/execute' => Http::response(['statusCode' => '2117', 'statusMessage' => 'Payment execution already been called before']),
        ]);

        $state = $this->gateway()->execute('PAY123');

        $this->assertSame(GatewayPaymentStatus::Unknown, $state->status);
        $this->assertSame('2117', $state->errorCode);
    }

    public function test_timeout_is_unknown(): void
    {
        Http::fake($this->fakeToken() + [
            self::BASE.'/tokenized/checkout/payment/status' => fn () => throw new ConnectionException('timed out'),
        ]);

        $state = $this->gateway()->query('PAY123');

        $this->assertSame(GatewayPaymentStatus::Unknown, $state->status);
        $this->assertSame('TIMEOUT', $state->errorCode);
    }

    public function test_query_maps_statuses(): void
    {
        $map = ['Initiated' => GatewayPaymentStatus::Pending, 'Cancelled' => GatewayPaymentStatus::Cancelled, 'Failed' => GatewayPaymentStatus::Failed, 'Weird' => GatewayPaymentStatus::Unknown];
        $sequence = Http::sequence();
        foreach (array_keys($map) as $remote) {
            $sequence->push(['statusCode' => '0000', 'paymentID' => 'P', 'transactionStatus' => $remote]);
        }
        Http::fake($this->fakeToken() + [self::BASE.'/tokenized/checkout/payment/status' => $sequence]);
        $gateway = $this->gateway();

        foreach ($map as $remote => $expected) {
            $this->assertSame($expected, $gateway->query('P')->status, $remote);
        }
    }

    public function test_expired_token_is_refreshed_once_on_401(): void
    {
        Http::fake([
            self::BASE.'/tokenized/checkout/token/grant' => Http::sequence()
                ->push(['statusCode' => '0000', 'id_token' => 'OLD', 'expires_in' => 3600])
                ->push(['statusCode' => '0000', 'id_token' => 'NEW', 'expires_in' => 3600]),
            self::BASE.'/tokenized/checkout/payment/status' => Http::sequence()
                ->push(['message' => 'Unauthorized'], 401)
                ->push(['statusCode' => '0000', 'paymentID' => 'P', 'transactionStatus' => 'Initiated']),
        ]);

        $this->assertSame(GatewayPaymentStatus::Pending, $this->gateway()->query('P')->status);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/payment/status') && $r->header('Authorization')[0] === 'NEW');
    }

    public function test_refund_success(): void
    {
        Http::fake($this->fakeToken() + [
            self::BASE.'/tokenized/checkout/payment/refund' => Http::response(['statusCode' => '0000', 'transactionStatus' => 'Completed', 'refundTrxID' => 'RF1', 'amount' => '100.00']),
        ]);

        $result = $this->gateway()->refund('P', 'TRX', 10000, 'reason');

        $this->assertTrue($result->succeeded);
        $this->assertSame('RF1', $result->refundTransactionId);
        $this->assertSame(10000, $result->amountMinor);
    }

    public function test_simulator_cannot_be_constructed_in_production(): void
    {
        $this->expectException(\LogicException::class);
        new SimulatorGateway(Cache::store(), 'production');
    }
}
