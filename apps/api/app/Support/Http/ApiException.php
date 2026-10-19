<?php

namespace App\Support\Http;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Domain-level error rendered as a consistent JSON envelope:
 * {"error": {"code": "...", "message": "...", "details": {...}}}.
 */
class ApiException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly ErrorCode $errorCode,
        string $message,
        public readonly int $status = 400,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public static function subscriptionExpired(array $details = []): self
    {
        return new self(
            ErrorCode::SubscriptionExpired,
            'Renew your subscription to continue managing your business.',
            403,
            $details,
        );
    }

    public static function tenantSuspended(): self
    {
        return new self(
            ErrorCode::TenantSuspended,
            'This company account is suspended. Please contact support.',
            403,
        );
    }

    public static function forbidden(string $message = 'You are not allowed to perform this action.'): self
    {
        return new self(ErrorCode::Forbidden, $message, 403);
    }

    public function render(): JsonResponse
    {
        return ErrorResponse::make($this->errorCode, $this->getMessage(), $this->status, $this->details);
    }
}
