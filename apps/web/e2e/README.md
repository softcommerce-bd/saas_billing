# End-to-end tests

Run against a local stack with fake demo data and the development payment simulator
(never the real bKash gateway):

```bash
# API (apps/api): SUBSCRIPTION_PAYMENT_GATEWAY=simulator, APP_URL=http://localhost:3000
php artisan migrate:fresh --seed
php artisan serve --port=8000

# Web (apps/web)
npm run build && API_ORIGIN=http://127.0.0.1:8000 npx next start -p 3000

# Tests
npx playwright test            # PW_CHROMIUM_PATH can point at a preinstalled Chromium
```

The helpers use `php artisan tinker` to move the demo subscription's expiry, so the API
directory must be available locally (`E2E_API_DIR`).
