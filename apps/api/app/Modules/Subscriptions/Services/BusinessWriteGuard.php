<?php

namespace App\Modules\Subscriptions\Services;

use App\Modules\Tenancy\Models\Tenant;

/**
 * Defence in depth below the HTTP layer: business models refuse to be created, updated
 * or deleted for a tenant without write entitlement, whatever the entry point
 * (controller, job, command, import). See GuardsBusinessWrites.
 */
final class BusinessWriteGuard
{
    private int $bypassDepth = 0;

    public function __construct(private readonly SubscriptionEntitlementService $entitlements) {}

    public function assertTenantCanWrite(string $tenantId): void
    {
        if ($this->bypassDepth > 0) {
            return;
        }

        $tenant = Tenant::query()->findOrFail($tenantId);
        $this->entitlements->checkSubscription($tenant)->throwIfDenied();
    }

    /**
     * Only for seeders, data migrations and platform maintenance that are themselves
     * authorised and audited. Never call from tenant-facing code paths.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function bypass(callable $callback): mixed
    {
        $this->bypassDepth++;

        try {
            return $callback();
        } finally {
            $this->bypassDepth--;
        }
    }
}
