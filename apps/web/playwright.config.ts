import { defineConfig, devices } from "@playwright/test";

/**
 * End-to-end tests against a running stack: Laravel API on :8000 (payment simulator
 * driver, demo seed) and this app on :3000 proxying /api. See e2e/README.md.
 */
export default defineConfig({
  testDir: "./e2e",
  fullyParallel: false,
  workers: 1,
  retries: 0,
  timeout: 60_000,
  reporter: [["list"]],
  use: {
    baseURL: process.env.E2E_BASE_URL ?? "http://localhost:3000",
    trace: "retain-on-failure",
    screenshot: "only-on-failure",
  },
  projects: [
    { name: "desktop", use: { ...devices["Desktop Chrome"], launchOptions: { executablePath: process.env.PW_CHROMIUM_PATH } } },
    { name: "mobile", use: { ...devices["Pixel 7"], launchOptions: { executablePath: process.env.PW_CHROMIUM_PATH } } },
  ],
});
