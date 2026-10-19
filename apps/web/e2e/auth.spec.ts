import { expect, test } from "@playwright/test";

test("new company registration starts trial and asks to verify email", async ({ page }) => {
  const email = `owner-${Date.now()}@e2e.test`;
  await page.goto("/register");
  await page.getByLabel("Your name").fill("Nusrat Jahan");
  await page.getByLabel("Company name").fill("Jahan Agency");
  await page.getByLabel("Work email").fill(email);
  await page.getByRole("textbox", { name: "Password", exact: true }).fill("secure-pass-123");
  await page.getByLabel("Confirm password").fill("secure-pass-123");
  await page.getByRole("button", { name: "Create account" }).click();

  await expect(page.getByRole("heading", { name: "Verify your email" })).toBeVisible();
  await expect(page.getByText(email)).toBeVisible();
});

test("invalid credentials show a generic error", async ({ page }) => {
  await page.goto("/login");
  await page.getByLabel("Email").fill("owner@demo.test");
  await page.getByLabel("Password").fill("wrong-password-1");
  await page.getByRole("button", { name: "Sign in" }).click();
  await expect(page.getByText("These credentials do not match our records.")).toBeVisible();
});

test("protected pages redirect to login", async ({ page }) => {
  await page.goto("/app/customers");
  await page.waitForURL("**/login?next=%2Fapp%2Fcustomers");
  await expect(page.getByRole("heading", { name: "Sign in" })).toBeVisible();
});

test("pricing page shows admin-configured packages", async ({ page }) => {
  await page.goto("/pricing");
  for (const name of ["Monthly", "Quarterly", "Half-Yearly", "Yearly"]) {
    await expect(page.getByRole("heading", { name, exact: true })).toBeVisible();
  }
});
