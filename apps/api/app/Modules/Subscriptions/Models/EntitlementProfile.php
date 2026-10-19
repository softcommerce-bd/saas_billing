<?php

namespace App\Modules\Subscriptions\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Feature entitlements and quotas, separate from the billing duration of a plan.
 */
class EntitlementProfile extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'modules' => 'array',
            'max_users' => 'integer',
            'max_invoices_per_month' => 'integer',
            'sms_monthly_allowance' => 'integer',
            'storage_mb' => 'integer',
        ];
    }
}
