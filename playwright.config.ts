import { defineConfig } from "@playwright/test";
import path from "path";
import fs from "fs";

const AUTH_FILE = path.join(__dirname, "tests/screenshots/.auth/user.json");

// Timestamped snapshot directories — each run gets its own folder.
//   Update mode:  npm run test:update   → creates new timestamped dir
//   Compare mode: npm test              → compares against latest baseline
//   Specific:     BASELINE=202604061712 npm test  → compare against that run
function getSnapshotDir(): string {
  const snapshotsRoot = path.join(__dirname, "tests/screenshots/snapshots");

  if (process.env.BASELINE) {
    // Compare against a specific baseline
    return path.join(snapshotsRoot, process.env.BASELINE);
  }

  if (process.argv.includes("--update-snapshots")) {
    // Generate new timestamped directory for this capture run
    const now = new Date();
    const ts = now.getFullYear().toString()
      + String(now.getMonth() + 1).padStart(2, "0")
      + String(now.getDate()).padStart(2, "0")
      + String(now.getHours()).padStart(2, "0")
      + String(now.getMinutes()).padStart(2, "0");
    const dir = path.join(snapshotsRoot, ts);
    fs.mkdirSync(dir, { recursive: true });
    return dir;
  }

  // Default: use the latest timestamped directory
  if (fs.existsSync(snapshotsRoot)) {
    const dirs = fs.readdirSync(snapshotsRoot)
      .filter(d => /^\d{12}$/.test(d))
      .sort();
    if (dirs.length > 0) {
      return path.join(snapshotsRoot, dirs[dirs.length - 1]);
    }
  }

  // No baseline exists yet — fall back to creating one
  const dir = path.join(snapshotsRoot, "initial");
  fs.mkdirSync(dir, { recursive: true });
  return dir;
}

const snapshotDir = getSnapshotDir();

export default defineConfig({
  testDir: "./tests/screenshots",
  outputDir: "./tests/screenshots/test-results",
  globalSetup: "./tests/screenshots/global-setup.ts",
  timeout: 60_000,
  expect: {
    toHaveScreenshot: {
      // Allow small pixel differences from font rendering, anti-aliasing, etc.
      maxDiffPixelRatio: 0.01,
    },
  },
  // Place snapshots in timestamped directories: snapshots/{timestamp}/{spec-name}/screenshot.png
  snapshotPathTemplate: `${snapshotDir}/{testFileName}/{arg}{ext}`,
  use: {
    baseURL: "http://odr.io",
    viewport: { width: 1280, height: 900 },
    ignoreHTTPSErrors: true,
    screenshot: "only-on-failure",
    storageState: AUTH_FILE,
  },
  projects: [
    {
      name: "chromium",
      use: { browserName: "chromium" },
    },
  ],
});
