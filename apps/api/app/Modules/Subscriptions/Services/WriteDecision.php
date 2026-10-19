<?php

namespace App\Modules\Subscriptions\Services;

use App\Support\Http\ApiException;
use App\Support\Http\ErrorCode;

final readonly class WriteDecision
{
    private function __construct(
        public bool $allowed,
        public ?ErrorCode $code = null,
        public ?string $message = null,
        public ?EntitlementState $state = null,
    ) {}

    public static function allow(?EntitlementState $state = null): self
    {
        return new self(true, state: $state);
    }

    public static function deny(ErrorCode $code, string $message, ?EntitlementState $state = null): self
    {
        return new self(false, $code, $message, $state);
    }

    public function toException(): ApiException
    {
        $status = $this->code === ErrorCode::TenantNotFound ? 404 : 403;
        $details = $this->state ? ['subscription' => $this->state->toArray()] : [];

        return new ApiException($this->code ?? ErrorCode::Forbidden, (string) $this->message, $status, $details);
    }

    public function throwIfDenied(): void
    {
        if (! $this->allowed) {
            throw $this->toException();
        }
    }
}
