<?php

namespace App\Modules\Subscriptions\Gateway;

enum GatewayPaymentStatus: string
{
    case Completed = 'completed';
    // Created but not yet authorised/executed by the customer.
    case Pending = 'pending';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    // The gateway could not tell us (error, timeout, unrecognised status).
    case Unknown = 'unknown';
}
