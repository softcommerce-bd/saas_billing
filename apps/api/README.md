# SmartBill BD — API (Laravel)

Laravel 13 REST API (PHP 8.3+, PostgreSQL 16, Redis). See the repository root
`README.md` and `docs/` for architecture, security and deployment documentation.

## Local setup

```bash
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate --seed      # PlatformSeeder (+ DemoSeeder in APP_ENV=local)
php artisan serve               # http://localhost:8000
php artisan queue:work          # separate terminal
php artisan schedule:work       # separate terminal
```

Demo logins (local only, fake data): `owner@demo.test` / `password123`,
platform admin `admin@demo.test` / `password123`.

## Tests

Tests run against a real PostgreSQL database (`smartbill_test`, see `phpunit.xml`).

```bash
php artisan test                         # all suites
php artisan test --testsuite=Unit
php artisan test --testsuite=Feature
php artisan test --testsuite=Concurrency # forks processes; requires pcntl
./vendor/bin/pint --test                 # code style
```
