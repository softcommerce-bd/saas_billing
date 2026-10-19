# Security model and threat analysis (H)

The system is **not** claimed to be secure in absolute terms. This document lists controls,
their verification status, and residual risks for independent review.

## Assets
Tenant business data (customers, future invoices/payments), user credentials and sessions,
platform subscription revenue and gateway credentials, audit trail integrity.

## Trust boundaries
Browser ↔ nginx (public internet) · nginx ↔ API/Next (private network) · API ↔ PostgreSQL/Redis ·
API ↔ bKash (outbound TLS) · Browser ↔ bKash hosted checkout (customer).

## Threats and controls (STRIDE)

| Threat | Example | Controls | Verified by |
|---|---|---|---|
| Spoofing | Credential stuffing | Rate limit per email+IP, generic errors, uniform timing, `throttle:auth`, password policy (10+ chars, letters+digits, HIBP check in production) | Auth tests |
| Spoofing | Session theft | HttpOnly, Secure (prod), SameSite=Lax, encrypted session, regenerate on login, revoke on reset | Config review; tests for reset |
| Spoofing | Platform admin account takeover | **MFA not yet implemented (release blocker)** | — |
| Tampering | Client sets `tenant_id` or price | Server-derived tenant; guarded attributes; prices from server plan; totals server-calculated | Isolation & payment tests |
| Tampering | Forged gateway success | Server-to-server execute/query + order matching | Payment flow tests (forged, mismatch, duplicate, reused trx) |
| Tampering | Audit edit | DB trigger blocks UPDATE/DELETE | Admin test |
| Repudiation | "I didn't change that" | Audit events with actor, IP, UA for auth, settings, members, customers, catalog, subscription, admin actions | Several tests assert audit rows |
| Information disclosure | Company A reads Company B | Fail-closed tenant scope, membership check, composite FKs, ULIDs, 404 for foreign ids | `TenantIsolationTest` (16 cases) |
| Information disclosure | Super admin browsing tenant data | Platform roles have no membership-based access; admin API exposes subscription metadata only | Isolation test |
| Information disclosure | Stack traces | JSON error renderer hides internals unless `APP_DEBUG=true` | Manual/config |
| Information disclosure | CSV formula injection | Cell prefixing | Customer test |
| DoS | Brute force / scraping | Throttles on auth, callbacks; pagination caps | Partial |
| Elevation | Admin grants self owner | Owner-only owner management; last owner protected | Permission tests |
| Elevation | Expired tenant writes via jobs/imports | Model guard + job middleware + HTTP middleware | Read-only tests |
| Payment integrity | Double extension | Row locks, applied marker, unique period/payment and verified-trx-per-order indexes | Concurrency (multi-process) + flow tests |

## OWASP ASVS alignment (target L2) — status

| Area | Status |
|---|---|
| V2 Authentication | Partial: password policy, throttling, reset; **MFA missing** |
| V3 Session | Implemented (Sanctum SPA); needs prod cookie flags verified on deployment |
| V4 Access control | Implemented with tests |
| V5 Validation/encoding | FormRequests/validators; React escaping; no raw SQL with input |
| V7 Errors/logging | Structured errors; audit log; log redaction helper for audit metadata; central log shipping TBD |
| V8 Data protection | Encrypted sessions; 2FA secret column encrypted; backups encryption TBD |
| V9 Communications | HTTPS required in production (HSTS line ready in nginx) |
| V12 Files | Public serving disabled; upload pipeline not yet built |
| V13 API | CSRF for cookie auth, idempotency for payment-like ops |
| V14 Config | Secrets via env; `.env` excluded; debug off in prod image |

## Risks requiring independent review (release blockers marked ★)

1. ★ MFA (TOTP) for super admins and optionally owners.
2. ★ bKash adapter unverified against official docs/sandbox (see `docs/subscriptions/bkash-integration.md`).
3. ★ Production TLS, cookie flags (`SESSION_SECURE_COOKIE=true`), `TRUSTED_PROXIES`, CSP header for Next.js.
4. ★ Backups (PITR) and restore drill not yet executed.
5. Support access workflow (time-boxed, tenant-approved, audited) not built; support staff currently have none.
6. PostgreSQL row-level security as a second isolation layer — recommended before scale-up.
7. Least-privilege DB roles (migration role vs. runtime role without DDL) — documented, not provisioned.
8. Email verification link is valid 60 min and works unauthenticated (signature + email hash) — review.
9. Rate limits tuned for pilot; revisit for scale and add per-tenant API quotas.
10. Dependency and container image scanning (CI runs `composer audit`; add `npm audit`/Trivy).
11. Invoice/VAT legal compliance (NBR) — must be reviewed before claiming compliance.
12. Data protection obligations under Bangladesh law for personal data — legal review.
