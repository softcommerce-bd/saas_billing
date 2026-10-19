<?php

namespace App\Modules\Subscriptions\Services;

use App\Modules\Subscriptions\Enums\AccessMode;
use Carbon\CarbonImmutable;

/**
 * Snapshot of a tenant's effective access, computed server-side at a point in time.
 */
final readonly class EntitlementState
{
    public function __construct(
        public AccessMode $mode,
        // Why writes are blocked: expired | pending_payment | cancelled | suspended | null.
        public ?string $reason,
        public ?CarbonImmutable $expiresAt,
        public ?CarbonImmutable $validThrough,
        public bool $isTrial,
        public ?string $planCode,
        public bool $overrideActive,
        public ?CarbonImmutable $overrideExpiresAt,
        public bool $canRenew,
    ) {}

    public function allowsBusinessWrites(): bool
    {
        return $this->mode === AccessMode::Full;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'mode' => $this->mode->value,
            'can_write' => $this->allowsBusinessWrites(),
            'reason' => $this->reason,
            'expires_at' => $this->expiresAt?->toIso8601String(),
            'valid_through' => $this->validThrough?->toDateString(),
            'is_trial' => $this->isTrial,
            'plan_code' => $this->planCode,
            'override_active' => $this->overrideActive,
            'override_expires_at' => $this->overrideExpiresAt?->toIso8601String(),
            'can_renew' => $this->canRenew,
        ];
    }
}
