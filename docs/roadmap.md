# Implementation roadmap (K)

| Phase | Scope | Status |
|---|---|---|
| 1 | Monorepo, PostgreSQL schema foundation, auth (Sanctum SPA), tenancy, audit, idempotency | **Done** (tested) |
| 2 | Company settings, roles/permissions, team & invitations | **Done** (tested); onboarding wizard + logo upload pending |
| 3 | Customers, product/service catalog | **Done** (tested); CSV import, tags, contacts pending |
| 8* | Subscriptions, bKash adapter, entitlement, read-only enforcement, renewal, reminders, admin basics | **Done in code** (tested with faked gateway); bKash sandbox verification pending |
| 4 | Invoices: sequences, builder, lifecycle, PDF (Bangla fonts), share links, credit notes, quotations, recurring | Next |
| 5 | Payments, allocations, advances, reversals, receipts, schedules/installments, promises | |
| 6 | Today Collection Queue, dashboard KPIs (per `collection-metrics.md`), reports & exports | |
| 7 | SMS/email reminders, templates (Bangla/English), quotas, delivery logs, provider adapters | |
| 9 | UI polish, Bangla UI strings, accessibility audit, PWA manifest | |
| 10 | MFA, support access workflow, RLS evaluation, load tests, CI E2E, production hardening, launch review | |

*The subscription module was pulled forward because the read-only rule constrains every later module
(each new business model must use `GuardsBusinessWrites`; each new job must use the entitlement job middleware).

## Next implementation tasks (in order)
1. MFA (TOTP) for super admins (release blocker, small).
2. Invoice schema + `invoice_sequences` with row-locked gapless numbering per tenant/prefix.
3. Invoice calculation service (minor units, line rounding, discounts, tax) with property tests.
4. Invoice API (draft CRUD, issue, void→credit note) + policies (draft-only edits).
5. Invoice builder UI + PDF generation (queue job, private storage, signed download, share links).
6. Payments + allocations with idempotency and row locks; customer financial summary.
