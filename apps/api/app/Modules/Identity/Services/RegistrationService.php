<?php

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Subscriptions\Services\SubscriptionActivationService;
use App\Modules\Tenancy\Enums\Role;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantMembership;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Company sign-up: creates the user, the tenant, the owner membership and the
 * subscription record (with the configured trial) in one transaction.
 */
final class RegistrationService
{
    public function __construct(
        private readonly SubscriptionActivationService $subscriptions,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{name: string, email: string, password: string, company_name: string, phone?: string|null}  $data
     * @return array{user: User, tenant: Tenant}
     */
    public function register(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
            ]);

            $tenant = Tenant::create([
                'name' => $data['company_name'],
                'slug' => $this->uniqueSlug($data['company_name']),
                'email' => $user->email,
                'phone' => $data['phone'] ?? null,
            ]);

            $membership = TenantMembership::create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'status' => 'active',
                'joined_at' => now(),
            ]);
            $membership->syncRoles([Role::Owner]);

            $this->subscriptions->initialize($tenant, (int) config('subscriptions.trial_days'));

            $this->audit->record('tenant.registered', $tenant, [], tenantId: $tenant->id, actorUserId: $user->id);

            return ['user' => $user, 'tenant' => $tenant];
        });
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'company';
        $base = substr($base, 0, 60);
        $slug = $base;

        while (Tenant::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.Str::lower(Str::random(5));
        }

        return $slug;
    }
}
