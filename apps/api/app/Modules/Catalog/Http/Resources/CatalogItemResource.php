<?php

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Models\CatalogItem;
use App\Support\Money\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CatalogItem */
class CatalogItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'name' => $this->name,
            'description' => $this->description,
            'sku' => $this->sku,
            'category' => $this->category,
            'unit' => $this->unit,
            'price_minor' => $this->default_price_minor,
            'price' => Money::toDecimalString($this->default_price_minor),
            'default_discount_bp' => $this->default_discount_bp,
            'tax_rate_bp' => $this->tax_rate_bp,
            'tax_label' => $this->tax_label,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
