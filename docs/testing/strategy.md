# Test strategy (L)

## Levels

| Level | Tool | Location | Runs in CI |
|---|---|---|---|
| API unit | PHPUnit | `apps/api/tests/Unit` | Yes |
| API feature (HTTP + real PostgreSQL) | PHPUnit | `apps/api/tests/Feature` | Yes |
| API concurrency (forked processes, committed data) | PHPUnit + pcntl | `apps/api/tests/Concurrency` | Yes |
| Web unit/component | Vitest + Testing Library | `apps/web/src/**/*.test.ts(x)` | Yes |
| End-to-end (desktop + mobile viewport) | Playwright | `apps/web/e2e` | Not yet (needs stack in CI) |

## Results at this commit (actually executed)

* API: **189 tests passing** (Unit 57, Feature 130, Concurrency 2) on PostgreSQL 16.
* Web: **38 tests passing**; ESLint and `tsc` clean; production build succeeds.
* E2E: **14 tests passing** (7 scenarios × desktop/mobile) against a local stack with the payment simulator.

## Required scenario coverage (from the subscription update)

| # | Scenario | Test |
|---|---|---|
| 1 | Successful purchase | `SubscriptionPaymentFlowTest::test_successful_purchase…`, E2E |
| 2 | Cancelled checkout | flow test, E2E |
| 3 | Failed payment | flow test |
| 4 | Forged success callback | flow test, E2E |
| 5 | Wrong amount | flow test (+ wrong reference/currency/payment id) |
| 6 | Duplicate callback | flow test |
| 7 | Timeout + reconciliation | flow test (+ abandoned, crash recovery) |
| 8 | Active CRUD | `ReadOnlyModeTest::test_active_subscription_allows_full_crud` |
| 9–13 | Expired read / create / edit / delete / (payment recording: pending invoices module — covered generically by fail-closed middleware) | `ReadOnlyModeTest` |
| 14 | Background job rejection | `ReadOnlyModeTest::test_background_business_job_is_skipped_after_expiry` |
| 15 | Expiry during active session | `ReadOnlyModeTest`, E2E |
| 16 | Renewal after expiry | flow test, E2E |
| 17 | Early renewal | flow test |
| 18 | Concurrent renewal | `tests/Concurrency/ConcurrentActivationTest` (real processes) + flow tests |
| 19 | Tenant isolation | `TenantIsolationTest` |
| 20 | Suspended tenant | `ReadOnlyModeTest`, `AdminPlatformTest` |
| 21 | Calendar/timezone boundaries | `CalendarPeriodTest` |
| 22 | Refund & dispute | `RefundAndDisputeTest` |

Gap: sandbox tests against real bKash (needs credentials) — required before production.

## Planned additions
* Invoice calculation property tests (random lines, discounts, taxes; totals invariant).
* Payment allocation concurrency tests (parallel allocations to one invoice).
* Load tests (k6): login, customer list/search, invoice create, dashboard; budgets in PRD §6.
* E2E in CI via docker compose; accessibility checks (axe) in Playwright.
