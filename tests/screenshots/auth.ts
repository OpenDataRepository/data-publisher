import { Page } from "@playwright/test";

const USERNAME = "nate@opendatarepository.org";
const PASSWORD = "HeUQ8PK!aVGWd6fh6tiK";

/**
 * Log in via the login form. Call this when a navigation redirects to /login.
 */
export async function login(page: Page): Promise<void> {
  // Wait for the login form to appear
  await page.waitForSelector('input[name="_username"], input[placeholder="Username"]', {
    timeout: 10_000,
  });
  await page.fill('input[name="_username"], input[placeholder="Username"]', USERNAME);
  await page.fill('input[name="_password"], input[placeholder="Password"]', PASSWORD);
  await page.click('button:has-text("Log in")');
  // Wait for navigation after login
  await page.waitForLoadState("networkidle");
}

/**
 * Ensure we have an admin session by navigating to /admin and logging in if needed.
 */
export async function ensureAdminSession(page: Page): Promise<void> {
  await page.goto("/admin");
  // If redirected to login, authenticate
  if (page.url().includes("/login")) {
    await login(page);
  }
  await page.waitForLoadState("networkidle");
}

/**
 * Ensure we have a search session by navigating to /{slug} and logging in if needed.
 */
export async function ensureSearchSession(
  page: Page,
  slug: string = "rruff_sample"
): Promise<void> {
  await page.goto(`/${slug}`);
  if (page.url().includes("/login")) {
    await login(page);
  }
  await page.waitForLoadState("networkidle");
}
