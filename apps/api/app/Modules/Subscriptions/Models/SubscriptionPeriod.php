<?php

namespace App\Modules\Subscriptions\Models;

use App\Modules\Subscriptions\Enums\PeriodSource;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ledger entry for one validity extension. Verified payments, trials and complimentary
 * grants are distinguished by `source`; complimentary grants never reference a payment.
 */
class SubscriptionPeriod extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'source' => PeriodSource::class,
            'period_start' => 'immutable_datetime',
            'period_end' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'months' => 'integer',
            'days' => 'integer',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPayment::class, 'payment_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
