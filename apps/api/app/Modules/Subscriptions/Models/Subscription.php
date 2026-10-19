<?php

namespace App\Modules\Subscriptions\Models;

use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One row per tenant describing the current validity window. Platform-managed state:
 * it is only modified by the Subscriptions services, never by tenant input.
 */
class Subscription extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'is_trial' => 'boolean',
            'starts_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'anchor_day' => 'integer',
            'amount_minor' => 'integer',
            'activated_at' => 'immutable_datetime',
            'renewed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'suspended_at' => 'immutable_datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function periods(): HasMany
    {
        return $this->hasMany(SubscriptionPeriod::class);
    }

    public static function forTenant(string $tenantId): ?self
    {
        return static::query()->where('tenant_id', $tenantId)->first();
    }
}
