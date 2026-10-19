# Product Requirements Document — SmartBill BD (working name)

> Branding is configurable (`NEXT_PUBLIC_PRODUCT_NAME`, `APP_NAME`). "SmartBill BD" is a placeholder.

## 1. Summary

A multi-tenant B2B SaaS for billing, invoicing, customer dues and collection management
for Bangladeshi small businesses (1–30 staff): IT/software firms, agencies, traders,
wholesalers, distributors, retailers, consultants, service and maintenance companies.

**Promise:** *Create Bills Faster. Track Every Payment. Collect Dues Smarter.*

**North-star usability target:** after initial setup, a non-technical owner creates and
sends a professional invoice in about one minute.

## 2. Users and roles

| Level | Role | Purpose |
|---|---|---|
| Platform | Super Admin | Plans/prices, tenants, subscription payments, suspensions, audited extensions/overrides. **No access to tenant business data.** |
| Platform | Support Admin | Reserved. Future: time-boxed, tenant-approved, scoped, audited support access. Currently no admin API access. |
| Company | Business Owner | Everything, incl. subscription purchase and owner management. |
| Company | Company Admin | Everything except buying the subscription and managing owners. |
| Company | Accounts Manager | Customers, catalog, invoices (incl. void), payments (incl. reversal), credit notes, reports, export, reminders. |
| Company | Billing Executive | Customers, invoices (create/edit draft/issue), view payments, send reminders. |
| Company | Collection Executive | View customers/invoices, record payments, send reminders. |
| Company | Viewer | Read-only. |

Users may hold multiple roles per company and belong to multiple companies. The exact
permission matrix lives in `apps/api/app/Modules/Tenancy/Enums/Role.php` (tested).

## 3. Scope

### In scope for Version 1
Tenancy & identity · team & roles · customers · product/service catalog · invoices
(draft → issue → partial/paid → overdue/void, PDF, share link) · payments & allocations ·
collection scheduling & Today Collection Queue · SMS/email reminders · reports ·
SaaS subscriptions with bKash and read-only expiry enforcement · Super Admin.

### Out of scope for Version 1
Inventory/stock, ERP, payroll/HRM, full double-entry accounting, automatic wallet
debits (unless bKash approves an agreement-based product), claims of NBR/VAT compliance.
The architecture keeps these addable (catalog item ids are stable anchors for inventory;
financial events are append-only for future ledgers).

## 4. Feature hierarchy (B)

```
1. Platform
   1.1 Registration, email verification, login, password reset          [built]
   1.2 Company (tenant) creation, settings, invoice prefix              [built]
   1.3 Team: invitations, multi-role memberships, disable/remove        [built]
   1.4 Subscriptions: packages, bKash checkout, verification, renewal   [built – gateway unverified]
   1.5 Read-only mode on expiry (API, jobs, model guard, UI)            [built]
   1.6 Super Admin: tenants, plans/prices, payments, suspend, extend    [built – basic UI]
   1.7 MFA for privileged users                                         [planned – release blocker]
   1.8 Data export & account closure workflow                           [planned]
2. Customers                                                            [built: CRUD, search, CSV export]
   2.1 CSV import with validation & duplicate detection                 [planned]
   2.2 Groups/tags, contacts                                            [planned]
   2.3 Profile financials (invoiced/collected/outstanding/overdue)      [needs invoices]
3. Catalog (products & services, units, tax labels)                    [built]
4. Invoicing                                                            [planned – Phase 4]
   4.1 Builder (search customer, inline create, autocomplete items)
   4.2 Numbering per tenant (prefix + gapless sequence)
   4.3 Lifecycle: draft, issue, void; amendments via credit notes
   4.4 PDF (A4, Bangla/English fonts, logo, signature), print, share link
   4.5 Installments, recurring invoices, quotations
5. Payments & collections                                               [planned – Phase 5]
   5.1 Manual payments (cash, bank, bKash, Nagad, Rocket, cheque, other)
   5.2 Allocation across invoices, advances, reversals, receipts
   5.3 Collection schedules, promises, Today Collection Queue
6. Dashboard & reports                                                  [planned – Phase 6]
7. Notifications: SMS/email reminders, templates, quotas, delivery logs [planned – Phase 7]
```

## 5. Key business rules

* **Money:** integer minor units (poisha) in the DB and API; decimal strings at the edges; never floats.
* **Time:** UTC storage; Asia/Dhaka for business days, reports and subscription calendar periods.
* **Tenant isolation:** every company-owned row has `tenant_id`; server-derived context; composite FKs.
* **Financial immutability (planned modules):** issued invoices and posted payments are never edited
  destructively — voids, credit notes and reversals create new auditable records.
* **No overpayment** without the advance/credit policy (unallocated advances are explicit records).
* **Subscription expiry ⇒ read-only** (see `docs/subscriptions/subscription-lifecycle.md`). Non-negotiable.
* **Collection metrics** are defined precisely in `collection-metrics.md` before implementation.

## 6. Non-functional requirements

* Security per `docs/security/threat-model.md` (OWASP ASVS L2 aligned as target).
* Availability target for pilot: 99.5 % monthly; RPO ≤ 24 h (nightly backups + WAL archiving recommended), RTO ≤ 4 h.
* Performance budgets (to be validated by load tests, see `docs/testing/strategy.md`):
  API p95 < 300 ms for list/detail endpoints at 50 req/s with 1 000 tenants × 5 000 customers;
  dashboard aggregates p95 < 800 ms.
* Accessibility: WCAG 2.1 AA for core flows; large tap targets on mobile.
* Languages: English UI now; Bangla UI strings and Bangla invoice templates planned (fonts already loaded).

## 7. Acceptance criteria (implemented modules)

| Module | Criteria (all covered by automated tests) |
|---|---|
| Auth | Register creates company + owner + trial; generic login errors; brute-force lockout; verified email required for company data; single-use reset tokens. |
| Tenancy | Company A cannot list/read/update/delete Company B records by id, search, export or header switching; unknown and foreign tenants indistinguishable (404). |
| Roles | Viewer cannot write; only owner buys subscription or grants owner; last owner cannot be removed. |
| Customers/Catalog | BD mobile normalised to E.164; exact money parsing; SKU unique per tenant; CSV export safe from formula injection. |
| Subscriptions | Only server-verified, matching gateway payments extend validity; duplicates/forgeries/mismatches never extend; concurrent application extends once; calendar policy exact in Asia/Dhaka. |
| Read-only | After expiry every business mutation returns 403 `SUBSCRIPTION_EXPIRED`; reads and renewal keep working; UI flips mid-session. |
