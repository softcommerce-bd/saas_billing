<?php

namespace App\Modules\Tenancy\Support;

use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantMembership;

/**
 * The tenant on whose behalf the current request or job is running.
 * Registered as a scoped singleton so it is reset for every request and queued job.
 */
final class TenantContext
{
    private ?Tenant $tenant = null;

    private ?TenantMembership $membership = null;

    public function set(Tenant $tenant, ?TenantMembership $membership = null): void
    {
        if ($membership !== null && $membership->tenant_id !== $tenant->id) {
            throw new \LogicException('Membership does not belong to the tenant.');
        }

        $this->tenant = $tenant;
        $this->membership = $membership;
    }

    public function clear(): void
    {
        $this->tenant = null;
        $this->membership = null;
    }

    public function has(): bool
    {
        return $this->tenant !== null;
    }

    public function tenant(): Tenant
    {
        return $this->tenant ?? throw new MissingTenantContext;
    }

    public function tenantId(): string
    {
        return $this->tenant()->id;
    }

    public function tenantIdOrNull(): ?string
    {
        return $this->tenant?->id;
    }

    public function membership(): ?TenantMembership
    {
        return $this->membership;
    }

    /**
     * Run a callback with a different tenant context, restoring the previous one afterwards.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function runAs(Tenant $tenant, callable $callback): mixed
    {
        [$previousTenant, $previousMembership] = [$this->tenant, $this->membership];
        $this->tenant = $tenant;
        $this->membership = null;

        try {
            return $callback();
        } finally {
            $this->tenant = $previousTenant;
            $this->membership = $previousMembership;
        }
    }
}
