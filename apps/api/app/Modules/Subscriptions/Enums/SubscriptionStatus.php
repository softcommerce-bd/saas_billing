<?php

namespace App\Modules\Subscriptions\Enums;

/**
 * Stored status. Informational only: effective write access is always computed from
 * expires_at, suspension and overrides by SubscriptionEntitlementService.
 */
enum SubscriptionStatus: string
{
    case PendingPayment = 'pending_payment';
    case Active = 'active';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
    case Suspended = 'suspended';
}
