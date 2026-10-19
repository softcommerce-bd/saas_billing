import { expect, test } from "@playwright/test";
import { login, setDemoSubscriptionExpiry } from "./helpers";

test.describe.configure({ mode: "serial" });

test.afterAll(() => {
  // Leave the demo tenant active for other runs.
  setDemoSubscriptionExpiry("now()->addDays(14)");
});

test("owner manages customers, hits read-only on expiry, renews via checkout and regains access", async ({ page, isMobile }) => {
  setDemoSubscriptionExpiry("now()->addDays(14)");
  await login(page);

  await expect(page.getByRole("heading", { name: /Welcome, Demo/ })).toBeVisible();

  // Create a customer; the API normalises the Bangladesh mobile number.
  const name = `E2E Customer ${Date.now()}`;
  await page.goto("/app/customers/new");
  await page.getByLabel(/Customer name/).fill(name);
  await page.getByLabel("Mobile number").fill("01712 345678");
  await page.getByRole("button", { name: "Save customer" }).click();
  await expect(page.getByRole("heading", { name })).toBeVisible();
  await expect(page.getByText("01712-345678")).toBeVisible();

  // The subscription expires while the user is signed in.
  setDemoSubscriptionExpiry("now()->subMinute()");

  // The next API response carries X-Subscription-Mode: read_only and the UI switches without logout.
  await page.goto("/app/customers");
  await expect(page.getByRole("heading", { name: "Your Subscription Has Expired" })).toBeVisible();
  await expect(page.getByText(name).filter({ visible: true })).toBeVisible(); // data stays visible
  await expect(page.getByRole("button", { name: "Add customer" }).first()).toBeDisabled();

  // Renew: choose Monthly and pay through the (development) hosted checkout.
  await page.getByRole("link", { name: "Renew Subscription" }).click();
  await page.waitForURL("**/app/subscription**");
  await page.getByRole("button", { name: /Pay ৳ 1,000.00 with bKash/ }).click();

  await expect(page.getByText("DEVELOPMENT SIMULATOR — this is not bKash.")).toBeVisible();
  await page.getByRole("button", { name: "Approve payment" }).click();

  await page.waitForURL("**/app/subscription/payment-result**");
  await expect(page.getByText("Payment confirmed")).toBeVisible({ timeout: 20_000 });
  await expect(page.getByRole("heading", { name: "Your Subscription Has Expired" })).toBeHidden();

  await page.goto("/app/customers");
  await expect(page.getByRole("button", { name: "Add customer" }).first()).toBeEnabled();
  if (!isMobile) await expect(page.getByText("Active", { exact: true }).first()).toBeVisible();
});

test("cancelled checkout leaves the company read-only", async ({ page }) => {
  setDemoSubscriptionExpiry("now()->subMinute()");
  await login(page);

  await page.goto("/app/subscription");
  await page.getByRole("button", { name: /Pay ৳ 1,000.00 with bKash/ }).click();
  await page.getByRole("button", { name: "Cancel" }).click();

  await page.waitForURL("**/app/subscription/payment-result**");
  await expect(page.getByText("Payment cancelled")).toBeVisible({ timeout: 20_000 });
  await expect(page.getByRole("heading", { name: "Your Subscription Has Expired" })).toBeVisible();
});

test("forged success URL is not trusted", async ({ page }) => {
  setDemoSubscriptionExpiry("now()->subMinute()");
  await login(page);

  // Directly opening the gateway return URL with status=success for an unknown payment.
  await page.goto("/api/v1/subscription-payments/callback/simulator?paymentID=FORGED123&status=success");
  await expect(page.getByText("We couldn't match this payment")).toBeVisible();
  await page.goto("/app");
  await expect(page.getByRole("heading", { name: "Your Subscription Has Expired" })).toBeVisible();
});
