/**
 * Static Render Daemon
 *
 * Watches the `odr_static_render` beanstalkd tube for jobs enqueued by
 * ODR\AdminBundle\Component\Service\StaticRenderService. For each job:
 *
 *   1. Re-check the per-record version in Redis. If our job is older than
 *      the current version, drop the job — a fresher enqueue exists.
 *   2. Open a Puppeteer page, navigate to the job's URL.
 *   3. Wait for `window.__odrRenderReady === true` (set at the end of
 *      initPage() in display_ajax.html.twig).
 *   4. Wait for network-idle so image loads / late plugin AJAX settle.
 *   5. Capture document.documentElement.outerHTML and write it to disk.
 *   6. Delete the beanstalkd job.
 *
 * The daemon is single-threaded (one page at a time) by design — easy to
 * reason about, easy to bound resource use. Bump CONCURRENCY later if
 * needed.
 *
 * Job payload format (see StaticRenderService::enqueueRecord):
 *   {
 *     datatype_uuid, datarecord_uuid, datarecord_id,
 *     version, url, output_path
 *   }
 */

const puppeteer = require('puppeteer');
const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');
const Redis = require('ioredis');

const bs = require('nodestalker');
const client = bs.Client('127.0.0.1:11300');
const tube = 'odr_static_render';

// Redis keys must match StaticRenderService::VERSION_KEY_PREFIX in PHP.
const VERSION_KEY_PREFIX = 'static_render:version:';

const RENDER_READY_TIMEOUT_MS = 60000;
const NETWORK_IDLE_TIME_MS = 800;
const NETWORK_IDLE_TIMEOUT_MS = 60000;
const NAVIGATION_TIMEOUT_MS = 30000;

let browser;
const redis = new Redis(); // localhost:6379, default db

function ensureDir(filePath) {
    const dir = path.dirname(filePath);
    if (!fs.existsSync(dir))
        fs.mkdirSync(dir, { recursive: true });
}

/**
 * Find a Chromium/Chrome binary to drive Puppeteer.
 *
 * Puppeteer ships its own Chrome download, but on ARM64 Linux that download
 * has historically been a x86_64 binary that simply won't run. Honor
 * PUPPETEER_EXECUTABLE_PATH if set, otherwise probe a list of standard
 * system paths. Falls back to whatever Puppeteer ships with as a last
 * resort (caller will see a clear launch error if nothing works).
 */
function findChromiumExecutable() {
    if (process.env.PUPPETEER_EXECUTABLE_PATH)
        return process.env.PUPPETEER_EXECUTABLE_PATH;

    // Prefer non-snap, non-system installs first. Snap-confined Chromium
    // refuses to launch outside its own cgroup ("is not a snap cgroup
    // for tag snap.chromium.chromium"), so we explicitly avoid it.
    const home = process.env.HOME || '';
    const globPatterns = [
        // Playwright-installed Chromium (ships real ARM64 Linux binaries):
        path.join(home, '.cache/ms-playwright/chromium-*/chrome-linux/chrome'),
        // Puppeteer-managed Chromium downloaded into project cache:
        path.join(__dirname, 'chromium/linux*/chrome-linux/chrome'),
    ];
    for (const pattern of globPatterns) {
        try {
            const expanded = execSync(`ls ${pattern} 2>/dev/null | head -1`, { encoding: 'utf8' }).trim();
            if (expanded) return expanded;
        } catch (e) { /* none found, try next */ }
    }

    const candidates = [
        '/usr/bin/google-chrome-stable',
        '/usr/bin/google-chrome',
        '/usr/bin/chromium',
        '/usr/bin/chromium-browser',
        '/snap/bin/chromium',     // snap — only useful inside systemd-run --scope
    ];
    for (const p of candidates) {
        try {
            fs.accessSync(p, fs.constants.X_OK);
            return p;
        } catch (e) { /* not present, try next */ }
    }

    return null;   // caller will let Puppeteer try its bundled binary
}

async function processJob(jobData) {
    const data = JSON.parse(jobData);
    const { datarecord_id, version, url, output_path } = data;

    // De-dup check: if a newer enqueue has bumped the version past ours,
    // skip rendering. We still let the caller delete the job afterward.
    const currentRaw = await redis.get(VERSION_KEY_PREFIX + datarecord_id);
    const current = currentRaw === null ? 0 : parseInt(currentRaw, 10);
    if (current > version) {
        console.log(`Skipping stale job for record ${datarecord_id} (job v${version} < current v${current})`);
        return;
    }

    console.log(`Rendering record ${datarecord_id} v${version} -> ${output_path}`);

    const page = await browser.newPage();
    page.on('console', message => {
        // Pipe page console to our log, useful for diagnosing render failures.
        const t = message.type().substr(0, 3).toUpperCase();
        console.log(`  [page ${t}] ${message.text()}`);
    });
    await page.setViewport({ width: 1400, height: 4800 });

    try {
        const response = await page.goto(url, {
            waitUntil: 'domcontentloaded',
            timeout: NAVIGATION_TIMEOUT_MS,
        });
        if (response && response.status() >= 400) {
            throw new Error(`Page returned HTTP ${response.status()} for ${url}`);
        }

        // Render-ready signal set at the end of initPage() in display_ajax.html.twig.
        // The display page injects HTML via AJAX after window load, so we have
        // to poll for the signal rather than rely on load events.
        await page.waitForFunction(
            'typeof window.__odrRenderReady !== "undefined" && window.__odrRenderReady === true',
            { timeout: RENDER_READY_TIMEOUT_MS }
        );

        // Catch any remaining image / plugin-AJAX activity.
        await page.waitForNetworkIdle({
            idleTime: NETWORK_IDLE_TIME_MS,
            timeout: NETWORK_IDLE_TIMEOUT_MS,
        });

        // Grab the entire document. We reach for outerHTML directly so
        // <!DOCTYPE> is preserved via a separate prefix.
        const html = await page.evaluate(() => '<!DOCTYPE html>\n' + document.documentElement.outerHTML);

        ensureDir(output_path);
        fs.writeFileSync(output_path, html, 'utf8');

        // Re-check version one more time in case a fresh enqueue happened
        // mid-render. If so, the next worker run will overwrite us, but
        // dropping the just-written file would lose data — so we leave it.
        // Stale-output is not possible in the steady state because the
        // freshest job always wins.

        console.log(`Wrote ${html.length} bytes to ${output_path}`);
    } finally {
        await page.close().catch(() => {});
    }
}

async function reserveLoop() {
    client.reserve().onSuccess(async function (job) {
        console.log(`Reserved job ${job.id}`);
        try {
            await processJob(job.data);
        } catch (e) {
            console.error('Job error:', e && e.message ? e.message : e);
            // For now, delete the job on error so a single bad record doesn't
            // wedge the queue. Switch to release-with-delay if/when we want
            // automatic retries.
        }

        client.deleteJob(job.id).onSuccess(function () {
            reserveLoop();
        });
    });
}

async function main() {
    const args = ['--no-sandbox', '--disable-setuid-sandbox'];

    // Optional dev toggle — set STATIC_RENDER_IGNORE_HTTPS_ERRORS=1 when the
    // target host has an invalid/self-signed cert (typical for local dev
    // boxes like dev.odr.io). Off by default so production stays strict.
    const ignoreHTTPSErrors = process.env.STATIC_RENDER_IGNORE_HTTPS_ERRORS === '1';
    if (ignoreHTTPSErrors)
        args.push('--ignore-certificate-errors');

    const launchOpts = {
        headless: 'new',
        // --no-sandbox is required for snap-installed chromium and for
        // running as root; harmless on a non-sandboxed binary.
        args: args,
        // Tells Puppeteer's network layer to ignore TLS errors too — needed
        // alongside --ignore-certificate-errors so neither layer blocks the
        // navigation.
        ignoreHTTPSErrors: ignoreHTTPSErrors,
    };
    const executablePath = findChromiumExecutable();
    if (executablePath) {
        console.log(`Using Chromium executable: ${executablePath}`);
        launchOpts.executablePath = executablePath;
    } else {
        console.log('Using puppeteer-bundled Chrome.');
    }
    if (ignoreHTTPSErrors)
        console.log('STATIC_RENDER_IGNORE_HTTPS_ERRORS=1 — skipping HTTPS cert validation.');

    browser = await puppeteer.launch(launchOpts);
    client.watch(tube).onSuccess(function () {
        client.ignore('default').onSuccess(function () {
            console.log(`Watching tube "${tube}" for static-render jobs...`);
            reserveLoop();
        });
    });
}

main().catch(err => {
    console.error('Daemon failed to start:', err);
    process.exit(1);
});
