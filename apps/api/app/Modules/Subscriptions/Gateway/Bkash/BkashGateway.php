<?php

namespace App\Modules\Subscriptions\Gateway\Bkash;

use App\Modules\Subscriptions\Gateway\CheckoutRequest;
use App\Modules\Subscriptions\Gateway\CheckoutSession;
use App\Modules\Subscriptions\Gateway\GatewayException;
use App\Modules\Subscriptions\Gateway\GatewayPaymentState;
use App\Modules\Subscriptions\Gateway\GatewayPaymentStatus;
use App\Modules\Subscriptions\Gateway\RefundResult;
use App\Modules\Subscriptions\Gateway\SubscriptionPaymentGateway;
use App\Support\Money\Money;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\PendingRequest;
use InvalidArgumentException;

/**
 * bKash Tokenized Checkout adapter for the platform's merchant account.
 *
 * STATUS: implemented against the publicly described v1.2.0-beta request/response
 * shape; NOT yet verified against the official documentation for this merchant
 * account or exercised against the bKash sandbox. Paths are configurable in
 * config/bkash.php. See docs/subscriptions/bkash-integration.md for the go-live
 * verification checklist.
 */
final class BkashGateway implements SubscriptionPaymentGateway
{
    private const TOKEN_CACHE_KEY = 'platform:bkash:id_token';

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private readonly Http $http,
        private readonly Cache $cache,
        private readonly array $config,
    ) {}

    public function name(): string
    {
        return 'bkash';
    }

    public function environment(): string
    {
        return $this->config['environment'] === 'production' ? 'production' : 'sandbox';
    }

    public function isAvailable(): bool
    {
        return (bool) $this->config['enabled']
            && $this->config['base_url'] !== ''
            && filled($this->config['app_key'])
            && filled($this->config['app_secret'])
            && filled($this->config['username'])
            && filled($this->config['password']);
    }

    public function createCheckout(CheckoutRequest $request): CheckoutSession
    {
        if ($request->currency !== 'BDT') {
            throw new InvalidArgumentException('bKash checkout supports BDT only.');
        }

        $body = $this->post('create_payment', [
            'mode' => $this->config['checkout_mode'],
            'payerReference' => $request->payerReference,
            'callbackURL' => $request->callbackUrl,
            'amount' => Money::toDecimalString($request->amountMinor),
            'currency' => 'BDT',
            'intent' => 'sale',
            'merchantInvoiceNumber' => $request->merchantReference,
        ]);

        if (! $this->isSuccessCode($body) || empty($body['paymentID']) || empty($body['bkashURL'])) {
            throw new GatewayException(
                'bKash create payment failed: '.($this->errorMessage($body) ?? 'unexpected response'),
                $this->errorCode($body),
            );
        }

        return new CheckoutSession((string) $body['paymentID'], (string) $body['bkashURL'], self::sanitize($body));
    }

    public function execute(string $paymentId): GatewayPaymentState
    {
        return $this->paymentCall('execute_payment', $paymentId);
    }

    public function query(string $paymentId): GatewayPaymentState
    {
        return $this->paymentCall('query_payment', $paymentId);
    }

    public function refund(string $paymentId, string $transactionId, int $amountMinor, string $reason): RefundResult
    {
        $body = $this->post('refund_payment', [
            'paymentID' => $paymentId,
            'trxID' => $transactionId,
            'amount' => Money::toDecimalString($amountMinor),
            'sku' => 'subscription',
            'reason' => mb_substr($reason, 0, 255),
        ]);

        $completed = $this->isSuccessCode($body) && ($body['transactionStatus'] ?? null) === 'Completed';

        return new RefundResult(
            succeeded: $completed,
            refundTransactionId: isset($body['refundTrxID']) ? (string) $body['refundTrxID'] : null,
            amountMinor: isset($body['amount']) ? $this->parseAmount($body['amount']) : null,
            errorCode: $completed ? null : $this->errorCode($body),
            errorMessage: $completed ? null : $this->errorMessage($body),
            raw: self::sanitize($body),
        );
    }

    private function paymentCall(string $pathKey, string $paymentId): GatewayPaymentState
    {
        try {
            $body = $this->post($pathKey, ['paymentID' => $paymentId]);
        } catch (GatewayException $e) {
            return GatewayPaymentState::unknown($paymentId, $e->isTimeout ? 'TIMEOUT' : 'GATEWAY_ERROR', $e->getMessage());
        }

        $raw = self::sanitize($body);

        if (! $this->isSuccessCode($body)) {
            // A non-success code tells us the call failed, not that the payment failed.
            return GatewayPaymentState::unknown(
                $paymentId,
                $this->errorCode($body) ?? 'GATEWAY_ERROR',
                $this->errorMessage($body) ?? 'Unsuccessful gateway response.',
                $raw,
            );
        }

        $status = match ($body['transactionStatus'] ?? null) {
            'Completed' => GatewayPaymentStatus::Completed,
            'Initiated', 'Pending Authorized', 'Authorized' => GatewayPaymentStatus::Pending,
            'Cancelled' => GatewayPaymentStatus::Cancelled,
            'Failed', 'Expired', 'Declined' => GatewayPaymentStatus::Failed,
            default => GatewayPaymentStatus::Unknown,
        };

        return new GatewayPaymentState(
            status: $status,
            paymentId: (string) ($body['paymentID'] ?? ''),
            transactionId: isset($body['trxID']) && $body['trxID'] !== '' ? (string) $body['trxID'] : null,
            amountMinor: isset($body['amount']) ? $this->parseAmount($body['amount']) : null,
            currency: isset($body['currency']) ? (string) $body['currency'] : null,
            merchantReference: isset($body['merchantInvoiceNumber']) ? (string) $body['merchantInvoiceNumber'] : null,
            raw: $raw,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws GatewayException
     */
    private function post(string $pathKey, array $payload, bool $retryOnAuth = true): array
    {
        try {
            $response = $this->client()
                ->withHeaders([
                    'Authorization' => $this->idToken(),
                    'X-App-Key' => (string) $this->config['app_key'],
                ])
                ->post($this->config['paths'][$pathKey], $payload);
        } catch (ConnectionException $e) {
            throw new GatewayException('bKash connection failed: '.$e->getMessage(), isTimeout: true);
        }

        if ($response->status() === 401 && $retryOnAuth) {
            $this->cache->forget(self::TOKEN_CACHE_KEY);

            return $this->post($pathKey, $payload, false);
        }

        if ($response->serverError()) {
            throw new GatewayException('bKash server error (HTTP '.$response->status().').');
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    private function idToken(): string
    {
        $cached = $this->cache->get(self::TOKEN_CACHE_KEY);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        return $this->cache->lock(self::TOKEN_CACHE_KEY.':lock', 15)->block(10, function () {
            $cached = $this->cache->get(self::TOKEN_CACHE_KEY);
            if (is_string($cached) && $cached !== '') {
                return $cached;
            }

            try {
                $response = $this->client()
                    ->withHeaders([
                        'username' => (string) $this->config['username'],
                        'password' => (string) $this->config['password'],
                    ])
                    ->post($this->config['paths']['grant_token'], [
                        'app_key' => (string) $this->config['app_key'],
                        'app_secret' => (string) $this->config['app_secret'],
                    ]);
            } catch (ConnectionException $e) {
                throw new GatewayException('bKash token request failed: '.$e->getMessage(), isTimeout: true);
            }

            $body = $response->json() ?? [];
            $token = $body['id_token'] ?? null;

            if (! is_string($token) || $token === '') {
                throw new GatewayException('bKash token grant failed: '.($this->errorMessage($body) ?? 'no token'), $this->errorCode($body));
            }

            // Refresh well before the documented expiry.
            $ttl = max(60, ((int) ($body['expires_in'] ?? 3600)) - 300);
            $this->cache->put(self::TOKEN_CACHE_KEY, $token, $ttl);

            return $token;
        });
    }

    private function client(): PendingRequest
    {
        return $this->http
            ->baseUrl($this->config['base_url'])
            ->acceptJson()
            ->asJson()
            ->timeout($this->config['timeout_seconds'])
            ->connectTimeout(10);
    }

    private function isSuccessCode(array $body): bool
    {
        return ($body['statusCode'] ?? null) === $this->config['success_status_code'];
    }

    private function errorCode(array $body): ?string
    {
        $code = $body['statusCode'] ?? $body['errorCode'] ?? null;

        return $code === null ? null : (string) $code;
    }

    private function errorMessage(array $body): ?string
    {
        $message = $body['statusMessage'] ?? $body['errorMessage'] ?? null;

        return $message === null ? null : mb_substr((string) $message, 0, 255);
    }

    private function parseAmount(mixed $amount): ?int
    {
        try {
            return Money::parseToMinor((string) $amount);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Strip credentials/tokens and mask the payer's wallet number before storage.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public static function sanitize(array $body): array
    {
        unset($body['id_token'], $body['refresh_token'], $body['app_secret'], $body['password']);

        if (isset($body['customerMsisdn']) && is_string($body['customerMsisdn'])) {
            $body['customerMsisdn'] = str_repeat('*', max(0, strlen($body['customerMsisdn']) - 3)).substr($body['customerMsisdn'], -3);
        }

        return $body;
    }
}
