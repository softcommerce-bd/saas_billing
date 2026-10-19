import { execFileSync } from "node:child_process";
import path from "node:path";
import type { Page } from "@playwright/test";

const apiDir = process.env.E2E_API_DIR ?? path.resolve(__dirname, "../../api");

/** Runs PHP inside the Laravel app (local test stack only) to arrange server state. */
export function artisanTinker(php: string): string {
  return execFileSync("php", ["artisan", "tinker", "--execute", php], { cwd: apiDir, encoding: "utf8" });
}

export function setDemoSubscriptionExpiry(expression: string): void {
  artisanTinker(`
    $tenant = App\\Modules\\Tenancy\\Models\\Tenant::where('name', 'Demo Traders Ltd')->firstOrFail();
    App\\Modules\\Subscriptions\\Models\\Subscription::where('tenant_id', $tenant->id)->update(['expires_at' => ${expression}]);
  `);
}

export async function login(page: Page, email = "owner@demo.test", password = "password123") {
  await page.goto("/login");
  await page.getByLabel("Email").fill(email);
  await page.getByLabel("Password").fill(password);
  await page.getByRole("button", { name: "Sign in" }).click();
  await page.waitForURL("**/app");
}
