<?php

namespace App\Modules\Catalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CatalogItemRequest extends FormRequest
{
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';
        $percent = ['nullable', 'string', 'regex:/^(100(\.0{1,2})?|\d{1,2}(\.\d{1,2})?)$/'];

        return [
            'type' => [$required, 'in:product,service'],
            'name' => [$required, 'string', 'min:1', 'max:160'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'sku' => ['sometimes', 'nullable', 'string', 'max:60'],
            'category' => ['sometimes', 'nullable', 'string', 'max:80'],
            // One of the standard units or a custom label.
            'unit' => [$required, 'string', 'min:1', 'max:30'],
            'price' => [$required, 'string', 'regex:/^\d{1,13}(\.\d{1,2})?$/'],
            'default_discount_percent' => ['sometimes', ...$percent],
            'tax_rate_percent' => ['sometimes', ...$percent],
            'tax_label' => ['sometimes', 'nullable', 'string', 'max:40'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
