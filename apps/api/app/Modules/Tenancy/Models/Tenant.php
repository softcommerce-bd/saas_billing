<?php

namespace App\Modules\Tenancy\Models;

use App\Modules\Subscriptions\Models\Subscription;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A company using the platform. Root of every tenant-owned record.
 */
#[UseFactory(TenantFactory::class)]
class Tenant extends Model
{
    use HasFactory, HasUlids;

    protected $guarded = ['id', 'suspended_at', 'suspension_reason', 'closed_at'];

    protected function casts(): array
    {
        return [
            'onboarding_completed_at' => 'datetime',
            'suspended_at' => 'datetime',
            'closure_requested_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(TenantMembership::class);
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class);
    }

    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    public function isClosed(): bool
    {
        return $this->closed_at !== null;
    }
}
