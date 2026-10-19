<?php

namespace App\Modules\Customers\Http\Resources;

use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Support\BangladeshPhone;
use App\Support\Money\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Customer */
class CustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'name' => $this->name,
            'company_name' => $this->company_name,
            'mobile' => $this->mobile,
            'mobile_display' => BangladeshPhone::display($this->mobile),
            'secondary_phone' => $this->secondary_phone,
            'email' => $this->email,
            'billing_address' => $this->billing_address,
            'shipping_address' => $this->shipping_address,
            'district' => $this->district,
            'business_info' => $this->business_info,
            'bin' => $this->bin,
            'tin' => $this->tin,
            'opening_balance_minor' => $this->opening_balance_minor,
            'opening_balance' => Money::toDecimalString($this->opening_balance_minor),
            'credit_limit_minor' => $this->credit_limit_minor,
            'payment_terms_days' => $this->payment_terms_days,
            'notes' => $this->notes,
            'status' => $this->status,
            'preferred_language' => $this->preferred_language,
            'sms_opt_in' => $this->sms_opt_in,
            'email_opt_in' => $this->email_opt_in,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
