# Deployment (M)

## Topology (pilot)

One Linux VPS (4 vCPU / 8 GB RAM / 80 GB SSD suggested starting point, to be validated by load tests):
nginx (TLS) → Next.js + php-fpm containers; queue worker and scheduler containers;
managed PostgreSQL preferred (else container with volume + backups); Redis with AOF.
Scale-up path: move PostgreSQL to managed service → add app nodes behind a load balancer
(stateless app; sessions/queues/locks in Redis) → separate queue nodes → read replica for reports.

Scale-up triggers: API p95 > 300 ms sustained, CPU > 70 % for 15 min, queue latency > 60 s,
DB connections > 70 % of max.

## Files

* `docker-compose.yml` — production-like stack (`docker compose up -d --build`, nginx on :8080).
* `infra/docker/api/Dockerfile` — php 8.3-fpm-alpine, opcache, pdo_pgsql, redis, pcntl.
* `infra/docker/web/Dockerfile` — Next.js standalone server.
* `infra/docker/nginx/default.conf` — single-origin routing, security headers, `/dev/` blocked.
* `infra/deployment/stack.env.example` — environment template (never commit filled-in values).
* `.github/workflows/ci.yml` — API (Pint, tests on PostgreSQL, composer audit) and Web (lint, typecheck, tests, build).

Verification status: compose file validates and nginx config passes `nginx -t`; the image
builds could not complete in the authoring sandbox (no package registry egress from build
containers) and must be built once in CI or a normal Docker host before relying on them.

## First deployment

```bash
cp infra/deployment/stack.env.example infra/deployment/stack.env   # fill secrets, APP_KEY
docker compose build
docker compose up -d postgres redis
docker compose run --rm api php artisan migrate --force
docker compose run --rm api php artisan db:seed --class=PlatformSeeder --force
docker compose up -d
# Set real plan prices in /admin/plans and create the first super admin:
docker compose run --rm api php artisan tinker   # create user, set platform_role=super_admin, email_verified_at
```

## Release procedure
1. CI green on the commit. 2. Backup database (`pg_dump -Fc`). 3. `docker compose build`.
4. `php artisan down --render=...` only if a migration needs it. 5. `php artisan migrate --force`.
6. Restart api, queue (`queue:restart`), scheduler, web. 7. Smoke test `/up`, login, a read and a write.
Migrations must be backwards compatible with the previous release (expand → migrate → contract).

## Rollback
Redeploy previous images; roll back migrations only if they were additive and data-safe;
otherwise restore from the pre-release backup.

## Backups and recovery
Nightly `pg_dump` to encrypted off-site object storage (30 days), plus WAL archiving/PITR on
managed PostgreSQL. Quarterly restore drill into a staging environment, documented.
Redis is not a source of truth (sessions/queues may be lost; jobs are idempotent).

## Monitoring
`/up` health check; container restart policies; log shipping (stderr → collector); alert on
5xx rate, queue failed jobs (`failed_jobs`), reconciliation errors, payment `requires_review`,
disk, DB connections. Laravel Horizon recommended once queue volume grows.

## Production settings that must be set
`APP_ENV=production`, `APP_DEBUG=false`, HTTPS + `SESSION_SECURE_COOKIE=true`, HSTS,
`TRUSTED_PROXIES`, `SANCTUM_STATEFUL_DOMAINS=<public host>`, real SMTP, `SUBSCRIPTION_PAYMENT_GATEWAY=bkash`.
The simulator cannot be enabled in production (constructor throws; routes not registered; nginx blocks `/dev/`).
