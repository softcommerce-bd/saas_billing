<?php

namespace App\Modules\Tenancy\Concerns;

use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * For company-owned models. Every query is constrained to the current tenant and new
 * rows are stamped with it. Accessing these models without a tenant context throws.
 *
 * Platform-level code that must read across tenants calls ::acrossTenants() explicitly,
 * which keeps cross-tenant access greppable and reviewable.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model) {
            $context = app(TenantContext::class);

            if ($model->tenant_id === null) {
                $model->tenant_id = $context->tenantId();
            } elseif ($context->has() && $model->tenant_id !== $context->tenantId()) {
                throw new LogicException('Cannot create a record for a different tenant than the current context.');
            }
        });

        static::updating(function ($model) {
            if ($model->isDirty('tenant_id')) {
                throw new LogicException('tenant_id is immutable.');
            }
        });
    }

    public static function acrossTenants(): Builder
    {
        return static::withoutGlobalScope(TenantScope::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
