<?php

namespace App\Modules\Tenancy\Support;

use RuntimeException;

/**
 * Thrown when tenant-owned data is accessed without a tenant context.
 * Tenant scoping fails closed: no context means no data.
 */
final class MissingTenantContext extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Tenant-owned data accessed without a tenant context.');
    }
}
