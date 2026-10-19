<?php

namespace Database\Seeders;

use App\Modules\Subscriptions\Enums\PlanCode;
use App\Modules\Subscriptions\Models\EntitlementProfile;
use App\Modules\Subscriptions\Models\Plan;
use App\Modules\Subscriptions\Services\QuotaService;
use Illuminate\Database\Seeder;

/**
 * Required reference data: the default entitlement profile and the four packages.
 * Prices are PLACEHOLDERS so the platform is usable; the super admin must set real
 * prices in the admin panel before launch. Existing plans are never overwritten.
 */
class PlatformSeeder extends Seeder
{
    public function run(): void
    {
        $profile = EntitlementProfile::firstOrCreate(
            ['code' => QuotaService::DEFAULT_PROFILE_CODE],
            [
                'name' => 'Standard',
                'max_users' => 10,
                'max_invoices_per_month' => null,
                'sms_monthly_allowance' => 200,
                'storage_mb' => 1024,
                'modules' => ['customers', 'catalog', 'invoices', 'collections', 'reminders', 'reports'],
            ],
        );

        $placeholders = [
            PlanCode::Monthly->value => ['Monthly', 100000],
            PlanCode::Quarterly->value => ['Quarterly', 285000],
            PlanCode::HalfYearly->value => ['Half-Yearly', 540000],
            PlanCode::Yearly->value => ['Yearly', 1020000],
        ];

        $order = 0;
        foreach (PlanCode::cases() as $code) {
            [$name, $price] = $placeholders[$code->value];
            Plan::firstOrCreate(['code' => $code->value], [
                'name' => $name,
                'duration_months' => $code->months(),
                'price_minor' => $price,
                'currency' => 'BDT',
                'entitlement_profile_id' => $profile->id,
                'is_active' => true,
                'sort_order' => $order++,
            ]);
        }
    }
}
