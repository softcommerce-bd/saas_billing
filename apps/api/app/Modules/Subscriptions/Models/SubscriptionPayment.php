<?php

namespace App\Modules\Subscriptions\Models;

use App\Modules\Subscriptions\Enums\PaymentStatus;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Tenancy\Concerns\TenantScope;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One gateway checkout attempt for an order. `verified_*` fields are written only from
 * server-side gateway responses, never from browser callbacks or user input.
 */
class SubscriptionPayment extends Model
{
    use BelongsToTenant, HasUlids;

    protected $guarded = ['id'];

    protected $hidden = ['gateway_payload'];

    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'amount_minor' => 'integer',
            'verified_amount_minor' => 'integer',
            'refunded_amount_minor' => 'integer',
            'requires_review' => 'boolean',
            'check_attempts' => 'integer',
            'gateway_payload' => 'array',
            'verified_at' => 'immutable_datetime',
            'applied_at' => 'immutable_datetime',
            'refunded_at' => 'immutable_datetime',
            'disputed_at' => 'immutable_datetime',
            'last_checked_at' => 'immutable_datetime',
        ];
    }

    /**
     * Not tenant-scoped: the composite FK (tenant_id, order_id) already guarantees the
     * order belongs to the payment's tenant, and platform code loads it without context.
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(SubscriptionOrder::class, 'order_id')->withoutGlobalScope(TenantScope::class);
    }

    /**
     * Append a sanitised gateway response to the payload history.
     *
     * @param  array<string, mixed>  $response
     */
    public function appendGatewayPayload(string $stage, array $response): void
    {
        $history = $this->gateway_payload ?? [];
        $history[] = ['stage' => $stage, 'at' => now()->toIso8601String(), 'response' => $response];
        $this->gateway_payload = array_slice($history, -20);
    }
}
