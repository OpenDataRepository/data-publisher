import { test, expect } from "@playwright/test";

// Test parameters — keep in sync with design_screenshots/screenshot_urls.py
const DATATYPE_ID = 738;
const THEME_ID = 2010;
const SIDEBAR_LAYOUT_ID = 1;
const GROUP_ID = 1;
const USER_ID_ADMIN = 1;
const USER_ID_TARGET = 2;

test.describe("Admin interface", () => {

  // --- DataType management ---

  test("admin homepage / database list", async ({ page }) => {
    await page.goto(`/admin#/admin/type/list/databases`);
    await page.waitForLoadState("networkidle");
    await expect(page).toHaveScreenshot("admin_homepage.png", { fullPage: true });
  });

  test("type list databases", async ({ page }) => {
    await page.goto(`/admin#/admin/type/list/databases`);
    await page.waitForLoadState("networkidle");
    await expect(page).toHaveScreenshot("type_list_databases.png", { fullPage: true });
  });

  test("type list templates", async ({ page }) => {
    await page.goto(`/admin#/admin/type/list/templates`);
    await page.waitForLoadState("networkidle");
    await expect(page).toHaveScreenshot("type_list_templates.png", { fullPage: true });
  });

  test("type list datatemplates", async ({ page }) => {
    await page.goto(`/admin#/admin/type/list/datatemplates`);
    await page.waitForLoadState("networkidle");
    await expect(page).toHaveScreenshot("type_list_datatemplates.png", { fullPage: true });
  });

  test("type properties", async ({ page }) => {
    await page.goto(`/admin#/admin/type/properties/${DATATYPE_ID}/0`);
    await page.waitForLoadState("networkidle");
    await expect(page).toHaveScreenshot("type_properties.png", { fullPage: true });
  });

  test("type landing", async ({ page }) => {
    await page.goto(`/admin#/admin/type/landing/${DATATYPE_ID}`);
    await page.waitForLoadState("networkidle");
    await expect(page).toHaveScreenshot("type_landing.png", { fullPage: true });
  });

  test("type create", async ({ page }) => {
    await page.goto(`/admin#/admin/type/create/0`);
    await page.waitForLoadState("networkidle");
    await expect(page).toHaveScreenshot("type_create.png", { fullPage: true });
  });

  test("type copy list", async ({ page }) => {
    await page.goto(`/admin#/admin/type/copy/list`);
    await page.waitForLoadState("networkidle");
    await expect(page).toHaveScreenshot("type_copy_list.png", { fullPage: true });
  });

  // --- User management ---

  test("user list", async ({ page }) => {
    await page.goto(`/admin#/admin/user/list`);
    await page.waitForLoadState("networkidle");
    await expect(page).toHaveScreenshot("user_list.png", { fullPage: true });
  });

  test("new user create", async ({ page }) => {
    await page.goto(`/admin#/admin/new_user/create`);
    await page.waitForLoadState("networkidle");
    await expect(page).toHaveScreenshot("new_user_create.png", { fullPage: true });
  });

  test("user manage roles", async ({ page }) => {
    await page.goto(`/admin#/admin/user/manage/roles`);
    await page.waitForLoadState("networkidle");
    await expect(page).toHaveScreenshot("user_manage_roles.png", { fullPage: true });
  });

  test("user profile edit", async ({ page }) => {
    await page.goto(`/admin#/admin/user/profile_edit/${USER_ID_TARGET}`);
    await page.waitForLoadState("networkidle");
    await expect(page).toHaveScreenshot("user_profile_edit.png", { fullPage: true });
  });

  test("user change password", async ({ page }) => {
    await page.goto(`/admin#/admin/user/change_password/${USER_ID_ADMIN}`);
    await page.waitForLoadState("networkidle");
    await expect(page).toHaveScreenshot("user_change_password.png", { fullPage: true });
  });

  test("user manage groups", async ({ page }) => {
    await page.goto(`/admin#/admin/user/managegroups/${USER_ID_TARGET}`);
    await page.waitForLoadState("networkidle");
    await expect(page).toHaveScreenshot("user_managegroups.png", { fullPage: true });
  });

  test("self profile edit", async ({ page }) => {
    await page.goto(`/admin#/profile_edit`);
    await page.waitForLoadState("networkidle");
    await expect(page).toHaveScreenshot("self_profile_edit.png", { fullPage: true });
  });

  // --- Groups & Plugins ---

  test("group manage", async ({ page }) => {
    await page.goto(`/admin#/admin/group/manange/${DATATYPE_ID}`);
    await page.waitForLoadState("networkidle");
    await expect(page).toHaveScreenshot("group_manage.png", { fullPage: true });
  });

  test("group permissions", async ({ page }) => {
    await page.goto(`/admin#/admin/group/permissions/${GROUP_ID}`);
    await page.waitForLoadState("networkidle");
    await expect(page).toHaveScreenshot("group_permissions.png", { fullPage: true });
  });

  test("plugins list", async ({ page }) => {
    await page.goto(`/admin#/admin/plugins/list`);
    await page.waitForLoadState("networkidle");
    await expect(page).toHaveScreenshot("plugins_list.png", { fullPage: true });
  });

  // --- Jobs ---

  test("jobs list", async ({ page }) => {
    await page.goto(`/admin#/jobs/list`);
    await page.waitForLoadState("networkidle");
    await expect(page).toHaveScreenshot("jobs_list.png", { fullPage: true });
  });

  // --- OAuth ---

  test("oauth client list", async ({ page }) => {
    await page.goto(`/admin#/profile/oauth_client/list`);
    await page.waitForLoadState("networkidle");
    await expect(page).toHaveScreenshot("oauth_client_list.png", { fullPage: true });
  });

  test("oauth client create", async ({ page }) => {
    await page.goto(`/admin#/profile/oauth_client/create`);
    await page.waitForLoadState("networkidle");
    await expect(page).toHaveScreenshot("oauth_client_create.png", { fullPage: true });
  });

  // --- Statistics ---

  test("statistics dashboard admin", async ({ page }) => {
    await page.goto(`/admin#/statistics/dashboard`);
    await page.waitForLoadState("networkidle");
    await page.click('text="1 Year"');
    await page.waitForTimeout(1000);
    await expect(page).toHaveScreenshot("statistics_dashboard_admin.png", { fullPage: true });
  });

  test("statistics dashboard datatype", async ({ page }) => {
    await page.goto(`/rruff_sample#/statistics/dashboard`);
    await page.waitForLoadState("networkidle");
    await page.click('text="1 Year"');
    await page.waitForTimeout(1000);
    await expect(page).toHaveScreenshot("statistics_dashboard_datatype.png", { fullPage: true });
  });

  test("statistics dashboard landing", async ({ page }) => {
    await page.goto(`/rruff_sample#/admin/type/landing/${DATATYPE_ID}`);
    await page.waitForLoadState("networkidle");
    await page.click('text="1 Year"');
    await page.waitForTimeout(1000);
    await expect(page).toHaveScreenshot("statistics_dashboard_landing.png", { fullPage: true });
  });

  // --- Design ---

  test("design modify view", async ({ page }) => {
    await page.goto(`/admin#/design/modify_view/${DATATYPE_ID}/${THEME_ID}`);
    await page.waitForLoadState("networkidle");
    await expect(page).toHaveScreenshot("design_modify_view.png", { fullPage: true });
  });

  test("design modify layout", async ({ page }) => {
    await page.goto(`/admin#/design/modify_layout/${DATATYPE_ID}/${SIDEBAR_LAYOUT_ID}`);
    await page.waitForLoadState("networkidle");
    await expect(page).toHaveScreenshot("design_modify_layout.png", { fullPage: true });
  });

  test("design create layout", async ({ page }) => {
    await page.goto(`/admin#/design/create_layout/${DATATYPE_ID}`);
    await page.waitForLoadState("networkidle");
    await expect(page).toHaveScreenshot("design_create_layout.png", { fullPage: true });
  });

  // --- Search config (admin context) ---

  test("search render default", async ({ page }) => {
    await page.goto(`/admin#/search/render_default/${DATATYPE_ID}`);
    await page.waitForLoadState("networkidle");
    await expect(page).toHaveScreenshot("search_render_default.png", { fullPage: true });
  });

  test("remote search start", async ({ page }) => {
    await page.goto(`/admin#/remote_search`);
    await page.waitForLoadState("networkidle");
    await expect(page).toHaveScreenshot("remote_search_start.png", { fullPage: true });
  });

  test("remote search select", async ({ page }) => {
    await page.goto(`/admin#/remote_search/${DATATYPE_ID}`);
    await page.waitForLoadState("networkidle");
    await expect(page).toHaveScreenshot("remote_search_select.png", { fullPage: true });
  });

  test("remote search example", async ({ page }) => {
    await page.goto(`/admin#/remote_search/example/json`);
    await page.waitForLoadState("networkidle");
    await expect(page).toHaveScreenshot("remote_search_example.png", { fullPage: true });
  });
});
