<?php

namespace App\Modules\Subscriptions\Gateway;

final readonly class RefundResult
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public bool $succeeded,
        public ?string $refundTransactionId,
        public ?int $amountMinor,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
        public array $raw = [],
    ) {}
}
