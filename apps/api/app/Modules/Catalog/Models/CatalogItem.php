<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Subscriptions\Services\GuardsBusinessWrites;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Database\Factories\CatalogItemFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Reusable product/service for invoice lines. No stock tracking in V1; a future
 * inventory module can attach to catalog_items.id without changing this table.
 */
#[UseFactory(CatalogItemFactory::class)]
class CatalogItem extends Model
{
    use BelongsToTenant, GuardsBusinessWrites, HasFactory, HasUlids, SoftDeletes;

    public const UNITS = ['piece', 'unit', 'box', 'kg', 'hour', 'day', 'month', 'project', 'service'];

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'default_price_minor' => 'integer',
            'default_discount_bp' => 'integer',
            'tax_rate_bp' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
