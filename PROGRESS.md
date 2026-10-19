# Progress report

_Last updated: 2026-10-19_

## Completed (implemented, integrated, tests executed and passing)

| Module | Evidence |
|---|---|
| Architecture & docs: PRD, feature hierarchy, architecture/module map, ERD, API conventions, tenant isolation design, threat model, UX spec, roadmap, test strategy, deployment, handoff | `docs/` |
| Multi-tenant foundation (tenant context, fail-closed scoping, composite FKs, ULIDs) | `TenantIsolationTest` (16) |
| Auth: registration + trial, login throttling, email verification, password reset, logout | `RegistrationAndLoginTest` (15) |
| Roles & permissions (6 roles, multi-role), team management, invitations, user quota | `PermissionTest`, `InvitationFlowTest` |
| Audit log (append-only, DB trigger), idempotency keys | Admin & payment tests |
| Customers (BD phone normalisation, search, CSV export) and catalog | CRUD tests |
| Subscription engine: packages, calendar periods (Asia/Dhaka, exclusive UTC expiry, anchor day), period ledger, entitlement service | `CalendarPeriodTest`, flow tests |
| Read-only enforcement: HTTP middleware, model guard, job middleware, UI banner/guards, mid-session switch | `ReadOnlyModeTest` (17), E2E |
| Renewal flow, verification, reconciliation, refunds/disputes, complimentary extensions, overrides, reminders | `SubscriptionPaymentFlowTest` (25), `RefundAndDisputeTest`, `SubscriptionRemindersTest`, real multi-process concurrency tests |
| Super Admin basics (tenants, suspend, extend, payments review, plan prices) | `AdminPlatformTest` |
| Next.js app: public pages, auth, shell, dashboard (real data), customers, catalog, team, settings, subscription, admin | 38 Vitest tests, 14 Playwright E2E, lint/tsc/build clean |
| CI workflow, Dockerfiles, compose, nginx | compose validated, `nginx -t` passed |

Test totals at this commit: API 189 passing · Web 38 passing · E2E 14 passing.

## Implemented but unverified

* **bKash adapter** — written against the publicly described v1.2.0-beta API; official docs unreachable from the build environment; not run against the sandbox. See go-live checklist in `docs/subscriptions/bkash-integration.md`.
* **Docker images** — Dockerfiles written; builds could not complete in the sandbox (no registry egress from build containers). CI workflow not yet run on GitHub.
* **Emails** — notifications built and asserted in tests; no real SMTP delivery tested.

## Not started (per roadmap)
Invoices & PDF, payments/allocations, collection scheduling & Today queue, dashboard financial KPIs,
reports, SMS/email business reminders, CSV import, onboarding wizard & logo upload, Bangla UI strings,
data export & account closure, MFA, support access workflow.

## Remaining risks (see `docs/security/threat-model.md`)
★ MFA for privileged users · ★ bKash verification · ★ production TLS/cookie/proxy config and CSP ·
★ backups + restore drill · RLS evaluation · least-privilege DB roles · legal review (NBR invoice
format, personal-data obligations, record retention) · load testing to set capacity claims.

## Next tasks
1. MFA for super admins. 2. Invoice module (Phase 4). 3. Payments & collections (Phase 5).
