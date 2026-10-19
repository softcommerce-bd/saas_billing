# SmartBill BD — Web (Next.js)

Next.js 16 (App Router, TypeScript strict, Tailwind CSS 4, TanStack Query, React Hook
Form + Zod). The browser talks to a single origin: `/api` and `/sanctum` are proxied to
the Laravel API (Next rewrites in development, nginx in production), so the Sanctum
session cookie stays first-party and no auth token is ever stored in JavaScript.

```bash
cp .env.example .env.local
npm install
npm run dev            # http://localhost:3000 (API must run on API_ORIGIN)
npm run lint
npm run typecheck
npm test               # Vitest unit/component tests
npx playwright test    # E2E, see e2e/README.md
```
