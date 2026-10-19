<?php

namespace App\Modules\Subscriptions\Enums;

/**
 * Effective access for a tenant at a point in time.
 */
enum AccessMode: string
{
    // Valid subscription: authorised business operations allowed.
    case Full = 'full';
    // Expired / never paid / cancelled: view-only, renewal allowed.
    case ReadOnly = 'read_only';
    // Suspended by the platform: view-only, renewal not allowed.
    case Suspended = 'suspended';
}
