<?php

namespace App\Modules\Tenancy\Http\Resources;

use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Tenant */
class TenantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'legal_name' => $this->legal_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'address' => $this->address,
            'district' => $this->district,
            'bin' => $this->bin,
            'tin' => $this->tin,
            'currency' => $this->currency,
            'timezone' => $this->timezone,
            'locale' => $this->locale,
            'invoice_prefix' => $this->invoice_prefix,
            'onboarding_completed' => $this->onboarding_completed_at !== null,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
