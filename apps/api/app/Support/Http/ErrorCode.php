<?php

namespace App\Support\Http;

/**
 * Stable, machine-readable error codes returned in {"error": {"code": ...}}.
 * The frontend switches on these values; never rename an existing case.
 */
enum ErrorCode: string
{
    case Unauthenticated = 'UNAUTHENTICATED';
    case Forbidden = 'FORBIDDEN';
    case NotFound = 'NOT_FOUND';
    case ValidationFailed = 'VALIDATION_FAILED';
    case TooManyRequests = 'TOO_MANY_REQUESTS';
    case CsrfTokenMismatch = 'CSRF_TOKEN_MISMATCH';
    case Conflict = 'CONFLICT';
    case ServerError = 'SERVER_ERROR';

    case InvalidCredentials = 'INVALID_CREDENTIALS';
    case AccountDisabled = 'ACCOUNT_DISABLED';
    case EmailNotVerified = 'EMAIL_NOT_VERIFIED';

    case TenantRequired = 'TENANT_REQUIRED';
    case TenantNotFound = 'TENANT_NOT_FOUND';
    case TenantSuspended = 'TENANT_SUSPENDED';
    case SubscriptionExpired = 'SUBSCRIPTION_EXPIRED';
    case QuotaExceeded = 'QUOTA_EXCEEDED';

    case IdempotencyKeyRequired = 'IDEMPOTENCY_KEY_REQUIRED';
    case IdempotencyKeyReused = 'IDEMPOTENCY_KEY_REUSED';
    case IdempotencyRequestInProgress = 'IDEMPOTENCY_REQUEST_IN_PROGRESS';

    case PaymentGatewayUnavailable = 'PAYMENT_GATEWAY_UNAVAILABLE';
    case PaymentNotAllowed = 'PAYMENT_NOT_ALLOWED';
}
