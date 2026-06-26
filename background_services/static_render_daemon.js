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
const BEANSTALKD_HOST = '127.0.0.1:11300';
const tube = 'odr_static_render';

// Lightweight .env loader. Tries dotenv first (if installed), falls back
// to a tiny built-in parser so the daemon works either way. The .env
// lives in the same directory as this file (background_services/.env)
// and is gitignored. Only used to provide ODR_API_USERNAME +
// ODR_API_PASSWORD for the public-API JSON fetch — if those are absent
// the daemon still renders HTML normally and just skips the JSON sibling.
(function loadDotEnv() {
    try {
        require('dotenv').config({ path: path.join(__dirname, '.env') });
        return;
    } catch (e) { /* dotenv not installed — use the fallback below */ }
    try {
        const txt = fs.readFileSync(path.join(__dirname, '.env'), 'utf8');
        txt.split(/\r?\n/).forEach(function (line) {
            const m = line.match(/^\s*([A-Z_][A-Z_0-9]*)\s*=\s*(.*?)\s*$/);
            if (!m) return;
            if (process.env[m[1]]) return;   // don't clobber an existing env var
            let v = m[2];
            if ((v.startsWith('"') && v.endsWith('"')) ||
                (v.startsWith("'") && v.endsWith("'"))) v = v.slice(1, -1);
            process.env[m[1]] = v;
        });
    } catch (e) { /* no .env present — that's fine */ }
})();

const API_USERNAME = process.env.ODR_API_USERNAME || '';
const API_PASSWORD = process.env.ODR_API_PASSWORD || '';

// The Node `fetch` calls (token + record JSON) validate TLS certs by
// default and, unlike Puppeteer, have no built-in bypass. On dev boxes
// with a self-signed / invalid cert the fetch throws a generic
// "fetch failed" (TypeError) with the real reason buried in e.cause.
// Reuse the same dev toggle the Puppeteer launch uses so one flag
// covers both. NEVER set this in production.
if (process.env.STATIC_RENDER_IGNORE_HTTPS_ERRORS === '1') {
    process.env.NODE_TLS_REJECT_UNAUTHORIZED = '0';
    console.log('STATIC_RENDER_IGNORE_HTTPS_ERRORS=1 — Node fetch will skip TLS cert validation.');
    // Belt-and-suspenders: some undici versions don't honor the env var
    // for the global fetch dispatcher, so also install a permissive
    // dispatcher when undici is reachable.
    try {
        const undici = require('undici');
        undici.setGlobalDispatcher(new undici.Agent({ connect: { rejectUnauthorized: false } }));
    } catch (e) { /* undici not directly requireable on this Node — env var covers it */ }
}

// Redis keys must match StaticRenderService::VERSION_KEY_PREFIX in PHP.
const VERSION_KEY_PREFIX = 'static_render:version:';

const RENDER_READY_TIMEOUT_MS = 60000;
// Fixed grace period after render-ready, before capturing HTML.
// We don't use Puppeteer's waitForNetworkIdle here: data:/blob: URIs
// don't reliably fire requestfinished, so the network-idle counter
// gets stuck on phantom in-flight resources forever even though the
// page is genuinely done. The render-ready signal (set at the end of
// initPage() in display_ajax.html.twig) is authoritative — this short
// pause just covers any straggling lazy image decode.
const POST_READY_GRACE_MS = parseInt(process.env.STATIC_RENDER_POST_READY_MS || '500', 10);
const NAVIGATION_TIMEOUT_MS = 30000;

let browser;
const redis = new Redis(); // localhost:6379, default db

// Cached JWT for the public API. Keyed by the token URL because in a
// shared-backend / multi-site setup the same daemon could be talking to
// more than one ODR install. We re-fetch on 401 (token expired) or when
// the cache is empty.
const tokenCache = new Map();   // tokenUrl -> { token, fetchedAt }

// Datatype schemas already handled this run (keyed by schema_output_path),
// so we don't re-stat / re-fetch the same schema for every record of a
// datatype. The on-disk check still runs once per datatype here.
const schemaHandled = new Set();

/**
 * Fetch a JWT for the public API using the ODR_API_USERNAME /
 * ODR_API_PASSWORD credentials. Returns null if creds are missing or
 * the token endpoint refuses us; callers should treat null as "skip
 * the JSON fetch and move on".
 */
async function fetchApiToken(tokenUrl) {
    if (!API_USERNAME || !API_PASSWORD) {
        console.warn(`  [json] cannot fetch token: ODR_API_USERNAME / ODR_API_PASSWORD missing`);
        return null;
    }

    console.log(`  [json] requesting token from ${tokenUrl} as "${API_USERNAME}"`);
    try {
        // Manually follow up to 3 same-host redirects, preserving POST and
        // body. Node's built-in fetch downgrades POST → GET on 301/302
        // per spec, so if the server uses 301 (canonical host, trailing
        // slash, `/odr/` prefix injection, HTTPS upgrade, etc.) we'd hit
        // the endpoint as GET and get "Method Not Allowed". This loop
        // keeps the method.
        const body = JSON.stringify({ username: API_USERNAME, password: API_PASSWORD });
        const headers = { 'Content-Type': 'application/json', 'Accept': 'application/json' };
        let currentUrl = tokenUrl;
        let resp = null;
        for (let hops = 0; hops <= 3; hops++) {
            resp = await fetch(currentUrl, {
                method: 'POST',
                redirect: 'manual',
                headers: headers,
                body: body,
            });
            if (resp.status >= 300 && resp.status < 400 && resp.headers.get('location')) {
                const loc = resp.headers.get('location');
                const next = new URL(loc, currentUrl).toString();
                console.warn(`  [json] token URL ${currentUrl} returned HTTP ${resp.status} → ${next}`);
                currentUrl = next;
                continue;
            }
            break;
        }
        if (!resp.ok) {
            console.warn(`  [json] token fetch failed: HTTP ${resp.status} at ${currentUrl}`);
            return null;
        }
        const data = await resp.json();
        const token = data && (data.token || data.access_token);
        if (!token) {
            console.warn(`  [json] token endpoint returned no token field (keys: ${Object.keys(data || {}).join(',')})`);
            return null;
        }
        // Cache against the ORIGINAL request URL so callers stay
        // consistent, but also surface the resolved final URL for the
        // operator to fix in PHP if a redirect was needed.
        tokenCache.set(tokenUrl, { token: token, fetchedAt: Date.now(), resolvedUrl: currentUrl });
        if (currentUrl !== tokenUrl)
            console.warn(`  [json] tip: update StaticRenderService::enqueueByIds to use ${currentUrl} directly and avoid the redirect`);
        console.log(`  [json] obtained token (${token.length} chars), cached for reuse`);
        // Full token printed for debugging. Remove or gate behind a debug
        // flag before production — a JWT in the logs is a credential.
        console.log(`  [json] token: ${token}`);
        return token;
    } catch (e) {
        // "fetch failed" is undici's generic wrapper — the real reason
        // (cert error, ECONNREFUSED, DNS, etc.) lives in e.cause.
        const cause = e && e.cause ? (e.cause.code || e.cause.message || e.cause) : '';
        console.warn(`  [json] token fetch errored:`, (e && e.message ? e.message : e), cause ? `(cause: ${cause})` : '');
        return null;
    }
}

async function getApiToken(tokenUrl, { forceRefresh = false } = {}) {
    if (!forceRefresh) {
        const cached = tokenCache.get(tokenUrl);
        if (cached) {
            console.log(`  [json] using cached token for ${tokenUrl}`);
            return cached.token;
        }
    }
    return await fetchApiToken(tokenUrl);
}

/**
 * Pulls the public-API JSON representation of one record and writes
 * it next to the rendered HTML as <output_path>.json. Non-fatal on
 * failure — the HTML render is the primary deliverable; the JSON is a
 * companion that the sitemap advertises when present.
 *
 * Optional global toggle: STATIC_RENDER_SKIP_JSON=1 disables the
 * fetch entirely (useful on dev boxes that don't have API creds).
 */
async function fetchAndWriteJsonRecord(jobData) {
    if (process.env.STATIC_RENDER_SKIP_JSON === '1') {
        console.log(`  [json] skipped (STATIC_RENDER_SKIP_JSON=1)`);
        return;
    }

    const { api_token_url, api_record_url, output_path, datarecord_uuid } = jobData;
    if (!api_token_url || !api_record_url) {
        console.warn(`  [json] skipped: job payload missing api_token_url / api_record_url ` +
            `(re-enqueue is needed — this job was queued before the JSON change shipped)`);
        return;
    }
    if (!API_USERNAME || !API_PASSWORD) {
        console.warn(`  [json] skipped: ODR_API_USERNAME / ODR_API_PASSWORD not set ` +
            `(check background_services/.env and restart the daemon to pick up changes)`);
        return;
    }

    console.log(`  [json] starting fetch for record ${datarecord_uuid || '<unknown>'}`);
    console.log(`  [json] record URL: ${api_record_url}`);

    let token = await getApiToken(api_token_url);
    if (!token) {
        console.warn(`  [json] no token available — skipping record fetch`);
        return;
    }

    async function attempt() {
        return await fetch(api_record_url, {
            headers: {
                'Accept': 'application/json',
                'Authorization': 'Bearer ' + token,
            },
        });
    }

    let resp;
    try {
        resp = await attempt();
        if (resp.status === 401) {
            // Token expired — re-fetch once and retry.
            console.log(`  [json] got 401; refreshing token and retrying`);
            token = await getApiToken(api_token_url, { forceRefresh: true });
            if (!token) {
                console.warn(`  [json] could not refresh token after 401 — skipping`);
                return;
            }
            resp = await attempt();
        }
    } catch (e) {
        console.warn(`  [json] record fetch errored:`, e && e.message ? e.message : e);
        return;
    }

    if (!resp.ok) {
        console.warn(`  [json] record fetch failed: HTTP ${resp.status} ${resp.statusText} for ${api_record_url}`);
        return;
    }

    const json_path = output_path.replace(/\.html$/, '.json');
    try {
        const body = await resp.text();   // already JSON; keep server's formatting
        fs.writeFileSync(json_path, body, 'utf8');
        console.log(`  [json] wrote ${body.length} bytes to ${json_path}`);
    } catch (e) {
        console.warn(`  [json] write errored at ${json_path}:`, e && e.message ? e.message : e);
    }
}

/**
 * Fetches the datatype's schema JSON via the public API and writes it
 * to schema_output_path ({datatype_uuid}/schema.json). The schema is
 * identical for every record of a datatype, so this only does real
 * work once per datatype per run: it short-circuits if we've already
 * handled this schema path this run, or if the file already exists on
 * disk from a previous run. Best-effort; failures just log.
 */
async function fetchAndWriteSchema(jobData) {
    if (process.env.STATIC_RENDER_SKIP_JSON === '1') return;

    const { api_token_url, api_schema_url, schema_output_path, datatype_uuid } = jobData;
    if (!api_token_url || !api_schema_url || !schema_output_path) return;   // old job payload
    if (!API_USERNAME || !API_PASSWORD) return;

    // Once per datatype per run.
    if (schemaHandled.has(schema_output_path)) return;
    schemaHandled.add(schema_output_path);

    // Already on disk from a prior run? Leave it; schemas change rarely
    // and re-fetching every run would be wasteful. (Delete the file to
    // force a refresh.)
    if (fs.existsSync(schema_output_path)) {
        console.log(`  [schema] already present for datatype ${datatype_uuid}, skipping`);
        return;
    }

    console.log(`  [schema] fetching schema for datatype ${datatype_uuid}`);
    console.log(`  [schema] schema URL: ${api_schema_url}`);

    let token = await getApiToken(api_token_url);
    if (!token) {
        console.warn(`  [schema] no token available — skipping schema fetch`);
        return;
    }

    async function attempt() {
        return await fetch(api_schema_url, {
            headers: {
                'Accept': 'application/json',
                'Authorization': 'Bearer ' + token,
            },
        });
    }

    let resp;
    try {
        resp = await attempt();
        if (resp.status === 401) {
            console.log(`  [schema] got 401; refreshing token and retrying`);
            token = await getApiToken(api_token_url, { forceRefresh: true });
            if (!token) {
                console.warn(`  [schema] could not refresh token after 401 — skipping`);
                return;
            }
            resp = await attempt();
        }
    } catch (e) {
        const cause = e && e.cause ? (e.cause.code || e.cause.message || e.cause) : '';
        console.warn(`  [schema] fetch errored:`, (e && e.message ? e.message : e), cause ? `(cause: ${cause})` : '');
        return;
    }

    if (!resp.ok) {
        console.warn(`  [schema] fetch failed: HTTP ${resp.status} ${resp.statusText} for ${api_schema_url}`);
        return;
    }

    try {
        ensureDir(schema_output_path);
        const body = await resp.text();
        fs.writeFileSync(schema_output_path, body, 'utf8');
        console.log(`  [schema] wrote ${body.length} bytes to ${schema_output_path}`);
    } catch (e) {
        console.warn(`  [schema] write errored at ${schema_output_path}:`, e && e.message ? e.message : e);
    }
}

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
    const { datarecord_id, version, url, output_path, auth_check_url } = data;

    // De-dup check: if a newer enqueue has bumped the version past ours,
    // skip rendering. We still let the caller delete the job afterward.
    const currentRaw = await redis.get(VERSION_KEY_PREFIX + datarecord_id);
    const current = currentRaw === null ? 0 : parseInt(currentRaw, 10);
    if (current > version) {
        console.log(`Skipping stale job for record ${datarecord_id} (job v${version} < current v${current})`);
        return;
    }

    console.log(`Rendering record ${datarecord_id} v${version}`);
    console.log(`  URL : ${url}`);
    console.log(`  Path: ${output_path}`);

    const page = await browser.newPage();
    page.on('console', message => {
        // Pipe page console to our log, useful for diagnosing render failures.
        const t = message.type().substr(0, 3).toUpperCase();
        console.log(`  [page ${t}] ${message.text()}`);
    });
    await page.setViewport({ width: 1400, height: 4800 });

    // Optional request-lifecycle tracking for ad-hoc diagnosis. Enabled
    // by STATIC_RENDER_DEBUG_NET=1 only — by default we don't bother
    // attaching the listeners, because the cost is low but it keeps
    // production logs tidy. The dumper is called automatically by the
    // grace-period stage when this flag is on so you get a per-render
    // network breakdown.
    let dumpNetworkDiagnostics = function () { /* no-op when disabled */ };
    if (process.env.STATIC_RENDER_DEBUG_NET === '1') {
        const inFlight = new Map();      // request -> { url, startedAt, type }
        const requestCounts = new Map(); // url -> number of times started
        page.on('request', req => {
            const u = req.url();
            inFlight.set(req, { url: u, startedAt: Date.now(), type: req.resourceType() });
            requestCounts.set(u, (requestCounts.get(u) || 0) + 1);
        });
        const settle = req => inFlight.delete(req);
        page.on('requestfinished', settle);
        page.on('requestfailed', settle);

        dumpNetworkDiagnostics = function () {
            const now = Date.now();
            const pending = Array.from(inFlight.values());
            console.log(`  [debug-net] still-pending requests: ${pending.length}`);
            pending.slice(0, 20).forEach(r => {
                const age = ((now - r.startedAt) / 1000).toFixed(1);
                console.log(`    pending  ${r.type.padEnd(10)} ${age}s  ${r.url}`);
            });
            if (pending.length > 20) console.log(`    ...and ${pending.length - 20} more`);

            const repeats = [...requestCounts.entries()]
                .filter(([, n]) => n >= 2)
                .sort((a, b) => b[1] - a[1])
                .slice(0, 15);
            if (repeats.length) {
                console.log(`  [debug-net] repeated requests during render (2+ hits):`);
                repeats.forEach(([u, n]) => console.log(`    ${String(n).padStart(4)}x  ${u}`));
            }
        };
    }

    // Tracks which step we're currently on so a Puppeteer "Timeout
    // exceeded while waiting for event" error can be re-thrown with
    // context about WHICH timeout fired. Otherwise the bare error message
    // is useless for diagnosis.
    let stage = 'init';
    try {
        stage = 'goto';
        const response = await page.goto(url, {
            waitUntil: 'domcontentloaded',
            timeout: NAVIGATION_TIMEOUT_MS,
        });
        if (response && response.status() >= 400) {
            throw new Error(`Page returned HTTP ${response.status()} for ${url}`);
        }
        console.log(`  [stage] goto landed on: ${page.url()}`);

        // Render-ready signal set at the end of initPage() in display_ajax.html.twig.
        // The display page injects HTML via AJAX after window load, so we have
        // to poll for the signal rather than rely on load events.
        stage = 'waitForRenderReady';
        await page.waitForFunction(
            'typeof window.__odrRenderReady !== "undefined" && window.__odrRenderReady === true',
            { timeout: RENDER_READY_TIMEOUT_MS }
        );

        // Soft pause for any tail-end image / plugin-AJAX activity. We
        // intentionally swallow the timeout: WP pages often have a
        // permanent heartbeat / analytics call that never goes idle, and
        // the render-ready signal already told us the page is done.
        // Render-ready already fired — give the page a brief window for
        // any tail-end lazy image decode, then capture. We deliberately
        // do NOT use waitForNetworkIdle: data: and blob: URLs don't
        // reliably emit requestfinished, so its in-flight counter sticks
        // at 1-2 forever even though the page is actually done.
        stage = 'postReadyGrace';
        if (POST_READY_GRACE_MS > 0)
            await new Promise(r => setTimeout(r, POST_READY_GRACE_MS));
        dumpNetworkDiagnostics();   // no-op unless STATIC_RENDER_DEBUG_NET=1

        // Inject a small redirect script into <head> *before* capturing
        // HTML. The script runs only when the cached file is served (it
        // does nothing on the live page since the daemon's render is
        // discarded right after). When a logged-in visitor lands on the
        // cached static page, the script detects their session via a
        // CORS-enabled `auth/status` endpoint on the dynamic host and
        // redirects them to the dynamic URL — that way they see the
        // full record including any non-public data they're allowed to
        // view.
        stage = 'inject_redirect';
        if (auth_check_url && url) {
            await page.evaluate((checkUrl, dynamicUrl) => {
                var s = document.createElement('script');
                s.setAttribute('data-odr-static-redirect', '1');
                s.textContent =
                    "(function(){try{" +
                        "fetch(" + JSON.stringify(checkUrl) + ",{credentials:'include',cache:'no-store'})" +
                        ".then(function(r){return r.ok?r.json():null;})" +
                        ".then(function(d){if(d&&d.logged_in){window.location.replace(" + JSON.stringify(dynamicUrl) + ");}})" +
                        ".catch(function(){});" +
                    "}catch(e){}})();";
                document.head.appendChild(s);
            }, auth_check_url, url);
        }

        // Grab the entire document. We reach for outerHTML directly so
        // <!DOCTYPE> is preserved via a separate prefix.
        stage = 'evaluate';
        const html = await page.evaluate(() => '<!DOCTYPE html>\n' + document.documentElement.outerHTML);

        stage = 'write';
        ensureDir(output_path);
        fs.writeFileSync(output_path, html, 'utf8');

        // Re-check version one more time in case a fresh enqueue happened
        // mid-render. If so, the next worker run will overwrite us, but
        // dropping the just-written file would lose data — so we leave it.
        // Stale-output is not possible in the steady state because the
        // freshest job always wins.

        console.log(`Wrote ${html.length} bytes to ${output_path}`);

        // Companion JSON snapshot. Best-effort: a failure here just logs
        // a warning and the job is still considered a success.
        stage = 'fetch_json';
        await fetchAndWriteJsonRecord(data);

        // Datatype schema snapshot — only does real work once per
        // datatype per run (and skips if already on disk). Independent
        // of the record JSON above so a record-fetch failure doesn't
        // block the schema.
        stage = 'fetch_schema';
        await fetchAndWriteSchema(data);
    } catch (e) {
        // Annotate the error with which stage it failed in, plus a snapshot
        // of what the page actually contains so the operator can tell
        // whether the page never reached initPage(), reached it but never
        // set the flag, or was redirected somewhere unexpected.
        const meta = await collectFailureContext(page).catch(() => null);
        const wrap = new Error(`stage=${stage} :: ${e && e.message ? e.message : e}` +
            (meta ? `\n    landed_url: ${meta.url}\n    title: ${meta.title}\n    ready_flag: ${meta.readyFlag}\n    initPage_defined: ${meta.initPageDefined}\n    body_length: ${meta.bodyLength}` : ''));
        wrap.stack = (e && e.stack) || wrap.stack;
        throw wrap;
    } finally {
        await page.close().catch(() => {});
    }
}

/**
 * Pulls a small snapshot of the page state for failure logging.
 * Used only when a job fails — never on the happy path.
 */
async function collectFailureContext(page) {
    if (!page || page.isClosed()) return null;
    return await page.evaluate(() => ({
        url: window.location.href,
        title: document.title || '',
        readyFlag: typeof window.__odrRenderReady === 'undefined'
            ? 'undefined'
            : (window.__odrRenderReady ? 'true' : 'false'),
        initPageDefined: typeof window.initPage === 'function',
        bodyLength: document.body ? document.body.innerHTML.length : 0,
    }));
}

// One reserve-process-delete loop per worker slot. Each slot uses its own
// beanstalkd TCP connection because nodestalker serializes requests on a
// single connection — N concurrent loops on one client wouldn't actually
// parallelize. They all share the single Puppeteer browser, opening their
// own pages.
async function reserveLoop(slotId, slotClient) {
    slotClient.reserve().onSuccess(async function (job) {
        console.log(`[slot ${slotId}] Reserved job ${job.id}`);
        try {
            await processJob(job.data);
        } catch (e) {
            console.error(`[slot ${slotId}] Job error:`, e && e.message ? e.message : e);
            // For now, delete the job on error so a single bad record doesn't
            // wedge the queue. Switch to release-with-delay if/when we want
            // automatic retries.
        }

        slotClient.deleteJob(job.id).onSuccess(function () {
            reserveLoop(slotId, slotClient);
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

    // Concurrent worker slots. Default 1; bump via env var when needed.
    // Each slot has its own beanstalkd connection (nodestalker serializes
    // requests on a single conn) and opens its own Puppeteer page. The
    // browser is shared across slots.
    let concurrency = parseInt(process.env.STATIC_RENDER_CONCURRENCY || '1', 10);
    if (!Number.isFinite(concurrency) || concurrency < 1) concurrency = 1;
    if (concurrency > 16) concurrency = 16;   // safety cap

    console.log(`Watching tube "${tube}" for static-render jobs (concurrency=${concurrency})...`);
    for (let i = 1; i <= concurrency; i++) {
        const slotClient = bs.Client(BEANSTALKD_HOST);
        slotClient.watch(tube).onSuccess(function () {
            slotClient.ignore('default').onSuccess(function () {
                reserveLoop(i, slotClient);
            });
        });
    }
}

main().catch(err => {
    console.error('Daemon failed to start:', err);
    process.exit(1);
});
