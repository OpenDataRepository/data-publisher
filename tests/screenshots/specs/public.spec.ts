import { test, expect } from "@playwright/test";

test.describe("Public pages (no auth required)", () => {
  test("login page", async ({ page }) => {
    await page.goto("/login");
    await page.waitForSelector('input[placeholder="Username"]');
    await expect(page).toHaveScreenshot("login.png", { fullPage: true });
  });

  test("resetting request", async ({ page }) => {
    await page.goto("/resetting/request");
    await page.waitForLoadState("networkidle");
    await expect(page).toHaveScreenshot("resetting_request.png", { fullPage: true });
  });

  test("resetting resend email", async ({ page }) => {
    await page.goto("/resetting/resend-email");
    await page.waitForLoadState("networkidle");
    await expect(page).toHaveScreenshot("resetting_resend_email.png", { fullPage: true });
  });

  test("oauth auth login", async ({ page }) => {
    await page.goto("/oauth/v2/auth_login");
    await page.waitForLoadState("networkidle");
    await expect(page).toHaveScreenshot("oauth_auth_login.png", { fullPage: true });
  });
});
