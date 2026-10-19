# Developer handoff checklist (N)

For the independent reviewers / developers taking over.

## Get running
- [ ] PHP 8.3+, Composer 2, Node 22, PostgreSQL 16, Redis 7 installed (or use docker compose).
- [ ] `apps/api`: `cp .env.example .env`, `composer install`, `php artisan key:generate`, `php artisan migrate --seed`.
- [ ] Create `smartbill_test` DB for the test suite; `php artisan test` → 189 passing.
- [ ] `apps/web`: `npm ci`, `npm test`, `npm run build`.
- [ ] Optional E2E: see `apps/web/e2e/README.md`.

## Read first
- [ ] `docs/architecture/overview.md` — tenant isolation design and ADRs.
- [ ] `docs/subscriptions/subscription-lifecycle.md` — read-only rule; every new feature must respect it.
- [ ] `docs/security/threat-model.md` — open risks (★ = release blockers).
- [ ] `docs/requirements/collection-metrics.md` — before building collections/reports.

## Rules for new code
- [ ] Tenant-owned models: `BelongsToTenant` + `GuardsBusinessWrites`; composite FKs to parents; `UNIQUE(tenant_id,id)`.
- [ ] New mutation routes go inside the `business.write` group (fail-closed) with a `permission:` middleware.
- [ ] Queue jobs touching business data: public `$tenantId`, `RunInTenantContext` + `RequiresBusinessWriteEntitlement` middleware.
- [ ] Money: integer minor units; parse with `Money::parseToMinor`; never floats.
- [ ] Payment-like creates: `idempotent:<scope>` middleware + DB uniqueness + row locks.
- [ ] Cross-tenant reads only via `::acrossTenants()` in platform code, reviewed.
- [ ] Audit every financial or permission-relevant action via `AuditLogger`.
- [ ] Tests: isolation test for every new resource; read-only test for every new mutation.

## Review focus areas
- [ ] `ResolveTenant`, `BelongsToTenant`, middleware priority in `bootstrap/app.php`.
- [ ] `SubscriptionPaymentProcessor` / `SubscriptionActivationService` (locking, idempotency, verification).
- [ ] `BkashGateway` against official docs (go-live checklist).
- [ ] Session/cookie/CORS/CSRF settings for the production domain.
- [ ] Docker images build and run as non-root; secrets handling.
