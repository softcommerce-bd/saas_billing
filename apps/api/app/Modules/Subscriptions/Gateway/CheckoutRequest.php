<?php

namespace App\Modules\Subscriptions\Gateway;

final readonly class CheckoutRequest
{
    public function __construct(
        public int $amountMinor,
        public string $currency,
        public string $merchantReference,
        public string $payerReference,
        public string $callbackUrl,
    ) {}
}
