<?php

namespace App\Modules\Subscriptions\Jobs\Middleware;

use App\Modules\Subscriptions\Services\SubscriptionEntitlementService;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Audit\AuditLogger;
use Closure;
use Illuminate\Support\Facades\Log;

/**
 * Queue job middleware for business write jobs (recurring invoices, business reminders,
 * imports...). The entitlement is checked when the job RUNS, not when it was queued,
 * so jobs scheduled before expiry do not execute afterwards. Skipped jobs are deleted,
 * logged and audited; they are not retried or caught up automatically.
 *
 * The job must expose a public string $tenantId property.
 */
final class RequiresBusinessWriteEntitlement
{
    public function handle(object $job, Closure $next): void
    {
        $tenant = Tenant::query()->find($job->tenantId);
        $decision = $tenant ? app(SubscriptionEntitlementService::class)->checkSubscription($tenant) : null;

        if ($decision === null || ! $decision->allowed) {
            Log::info('Skipped business job: tenant has no write entitlement.', [
                'job' => $job::class,
                'tenant_id' => $job->tenantId,
                'code' => $decision?->code?->value ?? 'TENANT_NOT_FOUND',
            ]);

            if ($tenant) {
                app(AuditLogger::class)->record('business_job.skipped_no_entitlement', null, [
                    'job' => class_basename($job),
                    'code' => $decision->code?->value,
                ], tenantId: $tenant->id, actorType: 'system');
            }

            if (method_exists($job, 'delete')) {
                $job->delete();
            }

            return;
        }

        $next($job);
    }
}
