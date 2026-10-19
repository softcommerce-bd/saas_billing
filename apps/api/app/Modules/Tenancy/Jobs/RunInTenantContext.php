<?php

namespace App\Modules\Tenancy\Jobs;

use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use Closure;

/**
 * Queue job middleware: restores the tenant context from the job's public $tenantId
 * so tenant-scoped models work inside the job. Missing tenants drop the job.
 */
final class RunInTenantContext
{
    public function handle(object $job, Closure $next): void
    {
        $tenant = Tenant::query()->find($job->tenantId);

        if ($tenant === null) {
            if (method_exists($job, 'delete')) {
                $job->delete();
            }

            return;
        }

        app(TenantContext::class)->runAs($tenant, fn () => $next($job));
    }
}
