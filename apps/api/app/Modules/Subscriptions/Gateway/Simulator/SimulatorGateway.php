<?php

namespace App\Modules\Subscriptions\Gateway\Simulator;

use App\Modules\Subscriptions\Gateway\CheckoutRequest;
use App\Modules\Subscriptions\Gateway\CheckoutSession;
use App\Modules\Subscriptions\Gateway\GatewayPaymentState;
use App\Modules\Subscriptions\Gateway\GatewayPaymentStatus;
use App\Modules\Subscriptions\Gateway\RefundResult;
use App\Modules\Subscriptions\Gateway\SubscriptionPaymentGateway;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Str;
use LogicException;

/**
 * DEVELOPMENT-ONLY payment simulator. It is NOT bKash and moves no money.
 * It lets developers exercise the full checkout -> callback -> execute -> activation flow
 * locally. The service provider refuses to register it outside local/testing.
 */
final class SimulatorGateway implements SubscriptionPaymentGateway
{
    public function __construct(private readonly Cache $cache, string $environment)
    {
        if (! in_array($environment, ['local', 'testing'], true)) {
            throw new LogicException('The payment simulator must never run outside local/testing.');
        }
    }

    public function name(): string
    {
        return 'simulator';
    }

    public function environment(): string
    {
        return 'simulator';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function createCheckout(CheckoutRequest $request): CheckoutSession
    {
        $paymentId = 'SIM'.Str::upper(Str::random(20));

        $this->put($paymentId, [
            'state' => 'Initiated',
            'amount_minor' => $request->amountMinor,
            'currency' => $request->currency,
            'merchant_reference' => $request->merchantReference,
            'callback_url' => $request->callbackUrl,
        ]);

        return new CheckoutSession($paymentId, route('dev.payment-simulator.show', ['paymentId' => $paymentId]));
    }

    /**
     * Called by the simulator page: the "customer" approves, cancels or fails the payment.
     */
    public function decide(string $paymentId, string $decision): ?string
    {
        $record = $this->get($paymentId);
        if ($record === null || $record['state'] !== 'Initiated') {
            return null;
        }

        $record['state'] = match ($decision) {
            'approve' => 'Authorized',
            'cancel' => 'Cancelled',
            default => 'Failed',
        };
        $this->put($paymentId, $record);

        $status = ['approve' => 'success', 'cancel' => 'cancel'][$decision] ?? 'failure';

        return $record['callback_url'].(str_contains($record['callback_url'], '?') ? '&' : '?')
            .http_build_query(['paymentID' => $paymentId, 'status' => $status]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function details(string $paymentId): ?array
    {
        return $this->get($paymentId);
    }

    public function execute(string $paymentId): GatewayPaymentState
    {
        $record = $this->get($paymentId);
        if ($record === null) {
            return GatewayPaymentState::unknown($paymentId, 'NOT_FOUND', 'Unknown payment.');
        }

        if ($record['state'] === 'Authorized') {
            $record['state'] = 'Completed';
            $record['trx_id'] = 'SIMTRX'.Str::upper(Str::random(10));
            $this->put($paymentId, $record);
        }

        return $this->query($paymentId);
    }

    public function query(string $paymentId): GatewayPaymentState
    {
        $record = $this->get($paymentId);
        if ($record === null) {
            return GatewayPaymentState::unknown($paymentId, 'NOT_FOUND', 'Unknown payment.');
        }

        $status = match ($record['state']) {
            'Completed' => GatewayPaymentStatus::Completed,
            'Cancelled' => GatewayPaymentStatus::Cancelled,
            'Failed' => GatewayPaymentStatus::Failed,
            default => GatewayPaymentStatus::Pending,
        };

        return new GatewayPaymentState(
            status: $status,
            paymentId: $paymentId,
            transactionId: $record['trx_id'] ?? null,
            amountMinor: $record['amount_minor'],
            currency: $record['currency'],
            merchantReference: $record['merchant_reference'],
            raw: ['simulator' => true, 'state' => $record['state']],
        );
    }

    public function refund(string $paymentId, string $transactionId, int $amountMinor, string $reason): RefundResult
    {
        return new RefundResult(true, 'SIMREF'.Str::upper(Str::random(10)), $amountMinor, raw: ['simulator' => true]);
    }

    private function get(string $paymentId): ?array
    {
        return $this->cache->get('dev:payment-simulator:'.$paymentId);
    }

    private function put(string $paymentId, array $record): void
    {
        $this->cache->put('dev:payment-simulator:'.$paymentId, $record, now()->addDay());
    }
}
