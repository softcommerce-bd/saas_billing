<?php

namespace App\Modules\Subscriptions\Enums;

enum PaymentStatus: string
{
    case Initiated = 'initiated';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    // Outcome could not be determined (timeout, gateway error); the reconciler re-queries it.
    case Unknown = 'unknown';
    case Refunded = 'refunded';
    case PartiallyRefunded = 'partially_refunded';

    public function isOpen(): bool
    {
        return in_array($this, [self::Initiated, self::Unknown], true);
    }
}
