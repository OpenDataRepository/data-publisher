import { chromium } from "@playwright/test";
import path from "path";

const AUTH_FILE = path.join(__dirname, ".auth", "user.json");

async function globalSetup() {
  const browser = await chromium.launch();
  const context = await browser.newContext({ ignoreHTTPSErrors: true });
  const page = await context.newPage();

  // Log in once and save the auth state
  await page.goto("http://odr.io/login");
  await page.waitForSelector('input[placeholder="Username"]');
  await page.fill('input[placeholder="Username"]', "nate@opendatarepository.org");
  await page.fill('input[placeholder="Password"]', "HeUQ8PK!aVGWd6fh6tiK");
  await page.click('button:has-text("Log in")');
  await page.waitForLoadState("networkidle");

  // Save signed-in state
  await context.storageState({ path: AUTH_FILE });
  await browser.close();
}

export default globalSetup;
