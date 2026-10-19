<?php

namespace App\Modules\Subscriptions\Models;

use App\Modules\Subscriptions\Enums\PlanCode;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Subscription package: a calendar-month duration with an admin-configured BDT price.
 */
class Plan extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'code' => PlanCode::class,
            'duration_months' => 'integer',
            'price_minor' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function entitlementProfile(): BelongsTo
    {
        return $this->belongsTo(EntitlementProfile::class);
    }

    /**
     * Display-only monthly equivalent, rounded down to whole poisha.
     */
    public function monthlyEquivalentMinor(): int
    {
        return intdiv($this->price_minor, $this->duration_months);
    }
}
