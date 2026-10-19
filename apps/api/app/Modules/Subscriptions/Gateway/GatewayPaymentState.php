<?php

namespace App\Modules\Subscriptions\Gateway;

/**
 * Normalised result of a server-side gateway call (execute or query).
 */
final readonly class GatewayPaymentState
{
    /**
     * @param  array<string, mixed>  $raw  Sanitised gateway response for the payment history.
     */
    public function __construct(
        public GatewayPaymentStatus $status,
        public string $paymentId,
        public ?string $transactionId = null,
        public ?int $amountMinor = null,
        public ?string $currency = null,
        public ?string $merchantReference = null,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
        public array $raw = [],
    ) {}

    public static function unknown(string $paymentId, string $errorCode, string $errorMessage, array $raw = []): self
    {
        return new self(GatewayPaymentStatus::Unknown, $paymentId, errorCode: $errorCode, errorMessage: $errorMessage, raw: $raw);
    }
}
