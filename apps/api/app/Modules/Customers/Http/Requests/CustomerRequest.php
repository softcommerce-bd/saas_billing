<?php

namespace App\Modules\Customers\Http\Requests;

use App\Modules\Customers\Support\BangladeshPhone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CustomerRequest extends FormRequest
{
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';
        $money = ['nullable', 'string', 'regex:/^\d{1,13}(\.\d{1,2})?$/'];

        return [
            'type' => [$required, 'in:individual,business'],
            'name' => [$required, 'string', 'min:1', 'max:160'],
            'company_name' => ['sometimes', 'nullable', 'string', 'max:160'],
            'mobile' => ['sometimes', 'nullable', 'string', 'max:20'],
            'secondary_phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'email' => ['sometimes', 'nullable', 'email:rfc', 'max:254'],
            'billing_address' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'shipping_address' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'district' => ['sometimes', 'nullable', 'string', 'max:60'],
            'business_info' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'bin' => ['sometimes', 'nullable', 'string', 'max:32'],
            'tin' => ['sometimes', 'nullable', 'string', 'max:32'],
            'opening_balance' => ['sometimes', ...$money],
            'credit_limit' => ['sometimes', ...$money],
            'payment_terms_days' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:365'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'status' => ['sometimes', 'in:active,inactive'],
            'preferred_language' => ['sometimes', 'in:bn,en'],
            'sms_opt_in' => ['sometimes', 'boolean'],
            'email_opt_in' => ['sometimes', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $mobile = $this->input('mobile');
                if (is_string($mobile) && trim($mobile) !== '' && BangladeshPhone::normalizeMobile($mobile) === null) {
                    $validator->errors()->add('mobile', 'Enter a valid Bangladesh mobile number, e.g. 01712-345678.');
                }
            },
        ];
    }
}
