<?php

namespace App\Support\Idempotency;

use App\Modules\Tenancy\Support\TenantContext;
use App\Support\Http\ErrorCode;
use App\Support\Http\ErrorResponse;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes a mutating endpoint safe to retry. The client sends a unique Idempotency-Key
 * header per logical operation; a retry with the same key and body replays the
 * original response instead of executing the operation twice.
 *
 * Usage: ->middleware('idempotent:payments.record')
 */
final class RequireIdempotencyKey
{
    private const TTL_HOURS = 24;

    public function __construct(private readonly TenantContext $tenantContext) {}

    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $key = (string) $request->header('Idempotency-Key', '');

        if (! preg_match('/^[A-Za-z0-9_\-:.]{8,100}$/', $key)) {
            return ErrorResponse::make(
                ErrorCode::IdempotencyKeyRequired,
                'A valid Idempotency-Key header (8-100 characters) is required for this request.',
                400,
            );
        }

        $user = $request->user();
        $hash = hash('sha256', $request->method().'|'.$request->path().'|'.json_encode($request->all()));

        try {
            // Own transaction/savepoint so a unique violation does not poison an outer transaction.
            $record = DB::transaction(fn () => IdempotencyKey::create([
                'user_id' => $user->getAuthIdentifier(),
                'tenant_id' => $this->tenantContext->tenantIdOrNull(),
                'scope' => $scope,
                'key' => $key,
                'request_hash' => $hash,
                'status' => 'processing',
                'expires_at' => now()->addHours(self::TTL_HOURS),
            ]));
        } catch (UniqueConstraintViolationException) {
            $existing = IdempotencyKey::query()
                ->where('user_id', $user->getAuthIdentifier())
                ->where('scope', $scope)
                ->where('key', $key)
                ->first();

            if ($existing === null) {
                return ErrorResponse::make(ErrorCode::Conflict, 'Please retry the request.', 409);
            }

            if ($existing->request_hash !== $hash) {
                return ErrorResponse::make(
                    ErrorCode::IdempotencyKeyReused,
                    'This Idempotency-Key was already used for a different request.',
                    422,
                );
            }

            if ($existing->status !== 'completed') {
                return ErrorResponse::make(
                    ErrorCode::IdempotencyRequestInProgress,
                    'The original request is still being processed.',
                    409,
                );
            }

            return new JsonResponse($existing->response_body, $existing->response_status, ['Idempotent-Replayed' => 'true']);
        }

        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            $record->delete();
            throw $e;
        }

        // Only successful outcomes are cached; failures can be retried with the same key.
        if ($response->getStatusCode() < 400 && $response instanceof JsonResponse) {
            $record->update([
                'status' => 'completed',
                'response_status' => $response->getStatusCode(),
                'response_body' => $response->getData(true),
            ]);
        } else {
            $record->delete();
        }

        return $response;
    }
}
