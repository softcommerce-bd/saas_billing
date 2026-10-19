# SmartBill BD (working name)

Multi-tenant billing, invoicing, customer-due and collection management SaaS for
Bangladeshi small businesses. **Create Bills Faster. Track Every Payment. Collect Dues Smarter.**

> Status: foundation, customers/catalog and the subscription + read-only system are built and
> tested. Invoicing, payments, collections, reminders and reports are next. See `PROGRESS.md`.
> Not yet production-ready.

## Stack
Laravel 13 (PHP 8.3) REST API · Next.js 16 / React 19 / TypeScript strict / Tailwind 4 ·
PostgreSQL 16 · Redis · Sanctum SPA cookie auth · Docker · GitHub Actions.

## Repository
```
apps/api     Laravel API (modular monolith: Identity, Tenancy, Subscriptions, Customers, Catalog)
apps/web     Next.js app (public site, business app, platform admin)
infra/       Dockerfiles, nginx, deployment env template
docs/        PRD, architecture, database, subscriptions/bKash, security, UX, testing, deployment
```

## Quick start (local)
```bash
# API
cd apps/api && cp .env.example .env && composer install && php artisan key:generate
php artisan migrate --seed && php artisan serve --port=8000
# Web (new terminal)
cd apps/web && npm ci && npm run dev    # http://localhost:3000
```
Demo (fake data, local only): `owner@demo.test` / `password123`; platform admin `admin@demo.test` / `password123`.
Payments locally use a clearly labelled **development simulator** (`SUBSCRIPTION_PAYMENT_GATEWAY=simulator`), never bKash.

## Documentation
Start with `docs/architecture/overview.md`, `docs/subscriptions/subscription-lifecycle.md` and
`docs/handoff-checklist.md`.
