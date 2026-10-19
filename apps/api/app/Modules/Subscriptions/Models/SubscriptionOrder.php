<?php

namespace App\Modules\Subscriptions\Models;

use App\Modules\Subscriptions\Enums\OrderStatus;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Tenancy\Concerns\TenantScope;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A tenant's intent to buy a package, with a server-side price snapshot.
 */
class SubscriptionOrder extends Model
{
    use BelongsToTenant, HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'amount_minor' => 'integer',
            'duration_months' => 'integer',
            'paid_at' => 'immutable_datetime',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * Not tenant-scoped: guaranteed same-tenant by the composite FK on subscription_payments.
     */
    public function payments(): HasMany
    {
        return $this->hasMany(SubscriptionPayment::class, 'order_id')->withoutGlobalScope(TenantScope::class);
    }
}
