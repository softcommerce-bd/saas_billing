# bKash integration (platform subscription payments)

> **Status: implemented but NOT verified against official documentation or the bKash sandbox.**
> The official developer portal could not be reached from the build environment. The adapter
> follows the publicly described Tokenized Checkout v1.2.0-beta shape, and every base URL,
> path and the payment mode are configurable (`apps/api/config/bkash.php`). Do not enable
> production until the checklist below is complete.

## Components

| File | Role |
|---|---|
| `Gateway/SubscriptionPaymentGateway.php` | Provider-agnostic contract (create, execute, query, refund) |
| `Gateway/Bkash/BkashGateway.php` | bKash adapter: token grant + cache (Redis lock), 401 re-grant, create/execute/query/refund, response normalisation, MSISDN masking |
| `Gateway/Simulator/SimulatorGateway.php` | **Development-only** stand-in; refuses to construct outside `local`/`testing`; nginx blocks `/dev/` in production |
| `Services/SubscriptionCheckoutService.php` | Orders and checkout start |
| `Services/SubscriptionPaymentProcessor.php` | Callback handling, verification, reconciliation |
| `Services/SubscriptionActivationService.php` | The only code that extends validity |
| `Services/SubscriptionRefundService.php` | Refunds/disputes |

## Trust model

* Credentials live only in server env vars; never in the browser or logs. Gateway payloads are stored sanitised (no tokens, masked wallet number).
* The browser return URL, its `status` parameter, screenshots and user-entered trx ids are never evidence of payment.
* Success requires a server-to-server response with status Completed **and** matching payment id, merchant invoice number, currency (BDT) and amount.
* Webhooks: not implemented. If the merchant account supports verified webhooks, implement them as *triggers* that call `reconcile()` — never trust webhook bodies directly.
* Recurring wallet debits: not implemented (customer-initiated renewal only) unless bKash approves an agreement product.

## Configuration

```
SUBSCRIPTION_PAYMENT_GATEWAY=bkash
BKASH_ENABLED=true
BKASH_ENVIRONMENT=sandbox|production
BKASH_BASE_URL=...            # from merchant documentation
BKASH_APP_KEY / BKASH_APP_SECRET / BKASH_USERNAME / BKASH_PASSWORD
BKASH_PATH_* overrides if the documented paths differ
APP_URL=https://your-public-origin   # used to build the callback URL
```

## Go-live checklist (must be completed and signed off)

- [ ] Obtain merchant onboarding docs for the exact product/version; record version here.
- [ ] Confirm base URLs (sandbox/production), all paths, header names (`Authorization`, `X-App-Key`, `username`, `password`).
- [ ] Confirm request fields (`mode`, `payerReference`, `callbackURL`, `amount` format, `currency`, `intent`, `merchantInvoiceNumber` length/charset).
- [ ] Confirm response fields and status values (`statusCode` success value, `transactionStatus` values, error formats) — adjust `BkashGateway::paymentCall()` mapping.
- [ ] Confirm execute idempotency/"already executed" error codes and execution time window.
- [ ] Confirm token lifetime and refresh policy; rate limits.
- [ ] Confirm refund API version, fields and partial refund support.
- [ ] Confirm callback URL parameters and whether a signature is provided.
- [ ] Sandbox test matrix: success, cancel, failure, insufficient balance, timeout during execute, duplicate callback, wrong amount (if testable), refund full/partial, reconciliation of a lost callback.
- [ ] Record sandbox evidence (payment ids, logs) in `docs/testing/`.
- [ ] Security review of the adapter and secrets handling; rotate credentials.
- [ ] Production smoke test with a minimal real payment and refund.

Until then the module is **not production-ready**, even though all automated tests (faked transport) pass.
