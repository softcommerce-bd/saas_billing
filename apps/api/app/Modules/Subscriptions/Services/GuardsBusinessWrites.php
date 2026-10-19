<?php

namespace App\Modules\Subscriptions\Services;

/**
 * Apply to every tenant business-data model (customers, catalog, invoices, payments...).
 */
trait GuardsBusinessWrites
{
    public static function bootGuardsBusinessWrites(): void
    {
        $guard = fn ($model) => app(BusinessWriteGuard::class)->assertTenantCanWrite($model->tenant_id);

        static::creating($guard);
        static::updating($guard);
        static::deleting($guard);

        if (method_exists(static::class, 'restoring')) {
            static::restoring($guard);
        }
    }
}
