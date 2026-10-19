<?php

namespace App\Modules\Subscriptions\Gateway;

/**
 * Provider-agnostic contract for collecting SaaS subscription payments through a
 * hosted checkout. Implementations must only call officially documented APIs.
 *
 * Trust model: the browser return (callback) is only a hint that something happened.
 * A payment is considered successful only from execute()/query() responses obtained
 * server-to-server and verified by PaymentVerificationService.
 */
interface SubscriptionPaymentGateway
{
    public function name(): string;

    /** "sandbox" | "production" | "simulator" */
    public function environment(): string;

    public function isAvailable(): bool;

    /**
     * @throws GatewayException
     */
    public function createCheckout(CheckoutRequest $request): CheckoutSession;

    /**
     * Confirm a customer-authorised payment. Must not throw for gateway-level outcomes;
     * network/timeout failures return an Unknown state.
     */
    public function execute(string $paymentId): GatewayPaymentState;

    /**
     * Read the authoritative status of a payment. Unknown on network failure.
     */
    public function query(string $paymentId): GatewayPaymentState;

    /**
     * @throws GatewayException
     */
    public function refund(string $paymentId, string $transactionId, int $amountMinor, string $reason): RefundResult;
}
