<?php

namespace App\Modules\Subscriptions\Gateway;

use RuntimeException;

/**
 * The gateway rejected a request or could not be reached. For create-payment this
 * simply fails the checkout; for execute/query callers treat the outcome as unknown.
 */
class GatewayException extends RuntimeException
{
    public function __construct(string $message, public readonly ?string $gatewayCode = null, public readonly bool $isTimeout = false)
    {
        parent::__construct($message);
    }
}
