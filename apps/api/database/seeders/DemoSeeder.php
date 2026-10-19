<?php

namespace Database\Seeders;

use App\Modules\Catalog\Models\CatalogItem;
use App\Modules\Customers\Models\Customer;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\RegistrationService;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * FAKE demo data for local development only. Refuses to run in production.
 */
class DemoSeeder extends Seeder
{
    public function run(RegistrationService $registration, TenantContext $context): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('DemoSeeder must not run in production.');
        }

        if (User::query()->where('email', 'owner@demo.test')->exists()) {
            return;
        }

        ['user' => $owner, 'tenant' => $tenant] = $registration->register([
            'name' => 'Demo Owner',
            'email' => 'owner@demo.test',
            'password' => 'password123',
            'company_name' => 'Demo Traders Ltd',
        ]);
        $owner->forceFill(['email_verified_at' => now()])->save();

        $admin = User::create(['name' => 'Platform Admin', 'email' => 'admin@demo.test', 'password' => 'password123']);
        $admin->forceFill(['email_verified_at' => now(), 'platform_role' => User::PLATFORM_SUPER_ADMIN])->save();

        $context->runAs($tenant, function () {
            Customer::factory()->count(25)->create();
            CatalogItem::factory()->count(10)->create();
        });
    }
}
