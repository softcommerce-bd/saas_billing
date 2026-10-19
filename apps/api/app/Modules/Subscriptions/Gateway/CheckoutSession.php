<?php

namespace App\Modules\Subscriptions\Gateway;

final readonly class CheckoutSession
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $paymentId,
        public string $redirectUrl,
        public array $raw = [],
    ) {}
}
