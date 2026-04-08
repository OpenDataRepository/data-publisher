import { test, expect } from "@playwright/test";

const SEARCH_SLUG = "rruff_sample";
const DATARECORD_ID = 640190;
const THEME_ID = 2010;
const SEARCH_KEY = "eyJkdF9pZCI6IjczOCJ9";

test.describe("Search & data pages", () => {
  test("search rruff_sample", async ({ page }) => {
    await page.goto(`/${SEARCH_SLUG}`);
    await page.waitForLoadState("networkidle");
    await expect(page).toHaveScreenshot("search_rruff_sample.png", { fullPage: true });
  });

  test("search immediate (quartz)", async ({ page }) => {
    await page.goto(`/${SEARCH_SLUG}/quartz`);
    await page.waitForLoadState("networkidle");
    await expect(page).toHaveScreenshot("search_immediate.png", { fullPage: true });
  });

  test("view record", async ({ page }) => {
    await page.goto(
      `/${SEARCH_SLUG}#/view/${DATARECORD_ID}/${THEME_ID}/${SEARCH_KEY}/1`
    );
    await page.waitForSelector('text="RRUFF ID"', { timeout: 20_000 });
    await page.waitForLoadState("networkidle");
    await expect(page).toHaveScreenshot("view_record.png", { fullPage: true });
  });

  test("edit record", async ({ page }) => {
    await page.goto(`/${SEARCH_SLUG}#/edit/${DATARECORD_ID}`);
    await page.waitForSelector('text="Mineral Name"', { timeout: 30_000 });
    await page.waitForLoadState("networkidle");
    // Viewport only — page is too large for full-page capture
    await expect(page).toHaveScreenshot("edit_record.png");
  });

  test("search render with search key", async ({ page }) => {
    await page.goto(
      `/${SEARCH_SLUG}#/search/display/${THEME_ID}/${SEARCH_KEY}`
    );
    await page.waitForLoadState("networkidle");
    await expect(page).toHaveScreenshot("search_render.png", { fullPage: true });
  });

  test("legacy search results", async ({ page }) => {
    await page.goto(
      `/${SEARCH_SLUG}#/search/results/${SEARCH_KEY}`
    );
    await page.waitForLoadState("networkidle");
    await expect(page).toHaveScreenshot("legacy_search_results.png", { fullPage: true });
  });

  test("legacy render", async ({ page }) => {
    await page.goto(
      `/${SEARCH_SLUG}#/search/render/${SEARCH_KEY}/1/searching`
    );
    await page.waitForLoadState("networkidle");
    await expect(page).toHaveScreenshot("legacy_render.png", { fullPage: true });
  });
});
