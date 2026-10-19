<?php

namespace App\Modules\Subscriptions\Http\Resources;

use App\Modules\Subscriptions\Models\Plan;
use App\Support\Money\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Plan */
class PlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $profile = $this->entitlementProfile;

        return [
            'id' => $this->id,
            'code' => $this->code->value,
            'name' => $this->name,
            'description' => $this->description,
            'duration_months' => $this->duration_months,
            'price_minor' => $this->price_minor,
            'price' => Money::toDecimalString($this->price_minor),
            'monthly_equivalent_minor' => $this->monthlyEquivalentMinor(),
            'currency' => $this->currency,
            'is_active' => $this->is_active,
            'entitlements' => $profile ? [
                'max_users' => $profile->max_users,
                'max_invoices_per_month' => $profile->max_invoices_per_month,
                'sms_monthly_allowance' => $profile->sms_monthly_allowance,
                'storage_mb' => $profile->storage_mb,
                'modules' => $profile->modules,
            ] : null,
        ];
    }
}
