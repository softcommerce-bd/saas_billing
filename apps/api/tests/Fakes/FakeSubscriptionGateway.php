<?php

namespace Tests\Fakes;

use App\Modules\Subscriptions\Gateway\CheckoutRequest;
use App\Modules\Subscriptions\Gateway\CheckoutSession;
use App\Modules\Subscriptions\Gateway\GatewayException;
use App\Modules\Subscriptions\Gateway\GatewayPaymentState;
use App\Modules\Subscriptions\Gateway\GatewayPaymentStatus;
use App\Modules\Subscriptions\Gateway\RefundResult;
use App\Modules\Subscriptions\Gateway\SubscriptionPaymentGateway;

/**
 * Scriptable gateway for tests. By default it behaves like an honest gateway whose
 * customer did NOT authorise the payment (execute/query report Pending), so tests
 * must explicitly script a successful payment.
 */
final class FakeSubscriptionGateway implements SubscriptionPaymentGateway
{
    public bool $available = true;

    public bool $failCreate = false;

    /** @var array<string, CheckoutRequest> */
    public array $checkouts = [];

    /** @var array<string, list<GatewayPaymentState>> scripted execute responses by payment id */
    public array $executeQueue = [];

    /** @var array<string, list<GatewayPaymentState>> scripted query responses by payment id */
    public array $queryQueue = [];

    public int $executeCalls = 0;

    public int $queryCalls = 0;

    public ?RefundResult $refundResult = null;

    private int $sequence = 0;

    public function name(): string
    {
        return 'bkash';
    }

    public function environment(): string
    {
        return 'sandbox';
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function createCheckout(CheckoutRequest $request): CheckoutSession
    {
        if ($this->failCreate) {
            throw new GatewayException('create failed', '2001');
        }

        $paymentId = 'TESTPAY'.(++$this->sequence);
        $this->checkouts[$paymentId] = $request;

        return new CheckoutSession($paymentId, 'https://gateway.test/checkout/'.$paymentId, ['paymentID' => $paymentId]);
    }

    public function execute(string $paymentId): GatewayPaymentState
    {
        $this->executeCalls++;

        $this->executeQueue[$paymentId] ??= [];

        return array_shift($this->executeQueue[$paymentId]) ?? $this->pending($paymentId);
    }

    public function query(string $paymentId): GatewayPaymentState
    {
        $this->queryCalls++;

        $this->queryQueue[$paymentId] ??= [];

        return array_shift($this->queryQueue[$paymentId]) ?? $this->pending($paymentId);
    }

    public function refund(string $paymentId, string $transactionId, int $amountMinor, string $reason): RefundResult
    {
        return $this->refundResult ?? new RefundResult(true, 'REFUND1', $amountMinor);
    }

    // ---- helpers ---------------------------------------------------------------

    public function lastPaymentId(): string
    {
        return array_key_last($this->checkouts);
    }

    /**
     * A genuine completed payment matching the checkout that created it.
     */
    public function completed(string $paymentId, array $overrides = []): GatewayPaymentState
    {
        $checkout = $this->checkouts[$paymentId];

        return new GatewayPaymentState(
            status: GatewayPaymentStatus::Completed,
            paymentId: $overrides['paymentId'] ?? $paymentId,
            transactionId: array_key_exists('transactionId', $overrides) ? $overrides['transactionId'] : 'TRX'.$paymentId,
            amountMinor: $overrides['amountMinor'] ?? $checkout->amountMinor,
            currency: $overrides['currency'] ?? $checkout->currency,
            merchantReference: $overrides['merchantReference'] ?? $checkout->merchantReference,
            raw: ['transactionStatus' => 'Completed'],
        );
    }

    public function status(string $paymentId, GatewayPaymentStatus $status): GatewayPaymentState
    {
        return new GatewayPaymentState($status, $paymentId, raw: ['transactionStatus' => $status->value]);
    }

    public function pending(string $paymentId): GatewayPaymentState
    {
        return $this->status($paymentId, GatewayPaymentStatus::Pending);
    }

    public function timeout(string $paymentId): GatewayPaymentState
    {
        return GatewayPaymentState::unknown($paymentId, 'TIMEOUT', 'timed out');
    }
}
