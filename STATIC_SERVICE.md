# Static Render Service

A background service that produces static HTML snapshots of every public
record so that search engines (which don't execute the AJAX-driven display
flow) can index ODR content. Snapshots live at
`web/uploads/static/{site_folder}/{datatype_uuid}/{record_uuid}.html` and
are regenerated on demand.

The `{site_folder}` segment is derived automatically from the existing
`site_baseurl` parameter (leading `//` / `https://` and trailing slash
stripped), so a single web root shared by multiple sites can host static
output without collisions. Examples:
- `site_baseurl: "//dev.rruff.net"` → `web/uploads/static/dev.rruff.net/...`
- `site_baseurl: "//dev.odr.io"`    → `web/uploads/static/dev.odr.io/...`

This branch is `static-service`, based off `develop`.

---

## Architecture at a glance

```
                        Redis                     Beanstalkd
                  static_render:version:NN         odr_static_render
                          ▲                              ▲
                          │ INCR                         │ put / reserve
                          │                              │
     ┌────────────────────┴──────────────────┐  ┌────────┴───────────────────┐
     │ PHP                                   │  │ Node                       │
     │  StaticRenderService                  │  │  static_render_daemon.js   │
     │   ↳ enqueueRecord(DataRecord)         │  │   • watch tube             │
     │   ↳ enqueueDatatype(DataType)         │  │   • version-skip check     │
     │  StaticRenderEnqueueCommand           │  │   • puppeteer.goto(url)    │
     │   (php app/console)                   │  │   • wait for ready signal  │
     └───────────────────────────────────────┘  │   • write HTML to disk     │
                                                └────────────────────────────┘
                                                          │
                                                          ▼
                            web/uploads/static/{site_folder}/{dt_uuid}/{r_uuid}.html
```

### Render-ready signal

The display page renders by first loading a thin shell, then injecting the
record HTML into `#ODRSearchContent` via AJAX. Inside that injected HTML,
`initPage()` (defined in
`src/ODR/AdminBundle/Resources/views/Display/display_ajax.html.twig`)
finishes the synchronous render. Two things happen at the end of
`initPage()`:

1. `window.__odrRenderReady = true`
2. `document.dispatchEvent(new CustomEvent('odr:render-complete'))`

The Node daemon polls for `window.__odrRenderReady === true`, then pauses
briefly (default 500 ms, override with `STATIC_RENDER_POST_READY_MS`) to
catch any tail-end lazy image decode, then captures the HTML. We
deliberately do **not** use Puppeteer's `waitForNetworkIdle`: `data:`
and `blob:` URIs don't reliably emit `requestfinished`, so the
in-flight counter sticks at 1–2 forever even though the page is
genuinely done — which is what we kept hitting in practice (data-URI
images and a blob: script that never "settle").

### De-duplication

The render service stores a per-record version counter in Redis at
`static_render:version:{record_id}`. Every enqueue does an `INCR` and stamps
the new version into the job payload. When the daemon reserves a job, it
re-reads the current version from Redis; if `current_version > job.version`
the job is dropped (a fresher enqueue exists). This way, repeated saves
during a render don't waste worker time and only the latest enqueue's output
ever lands on disk.

---

## Phase 1 components (already built)

| Component | Path |
| --- | --- |
| Render-ready signal | `src/ODR/AdminBundle/Resources/views/Display/display_ajax.html.twig` |
| PHP service | `src/ODR/AdminBundle/Component/Service/StaticRenderService.php` |
| Service registration | `src/ODR/AdminBundle/Resources/config/services.yml` (`odr.static_render_service`) |
| Console command | `src/ODR/AdminBundle/Command/StaticRenderEnqueueCommand.php` |
| Node daemon | `background_services/static_render_daemon.js` |
| Process startup | `background_services/start_jobs.sh`, `start_jobs_dev.sh` |

### Tube and key names

- Beanstalkd tube: `odr_static_render`
- Redis version keys: `static_render:version:{record_id}`
- Output dir: `web/uploads/static/{site_folder}/{datatype_uuid}/{record_uuid}.html`
  - `{site_folder}` derived from `site_baseurl` in `app/config/parameters.yml`

### Job payload (JSON)

```json
{
    "datatype_uuid":   "ddc5e9ba834ad596cc31aebb1225",
    "datarecord_uuid": "9cf3...",
    "datarecord_id":   12345,
    "version":         42,
    "url":             "https://dev.rruff.net/view/record/9cf3...",
    "output_path":     "/home/.../web/uploads/static/dev.rruff.net/ddc5e9.../9cf3....html"
}
```

---

## Running and testing Phase 1

### Prerequisites

- Beanstalkd running on `127.0.0.1:11300` (default).
- Redis running on `127.0.0.1:6379` (the SncRedis default client).
- Background services Node deps installed: `(cd background_services && npm install)`.
- A target site reachable at `site_baseurl` (from `app/config/parameters.yml`).
  The daemon hits `<site_baseurl>/view/record/{uuid}` over HTTPS.
- **`background_services/.env`** with API credentials, if you want a
  `.json` snapshot of each record (see "JSON record snapshot" below).
  Copy `.env.example` to `.env` and fill in `ODR_API_USERNAME` /
  `ODR_API_PASSWORD`. Omit them to skip JSON entirely — HTML still
  renders.

### Start the daemon

The daemon is wired into both startup scripts. Pick one of:

```bash
# Production-style (plain `node`)
./background_services/start_jobs.sh

# Dev-style (auto-reloading via `nodemon`)
./background_services/start_jobs_dev.sh
```

To run only the static-render daemon for ad-hoc testing:

```bash
cd background_services
node static_render_daemon.js
```

Logs go to `app/logs/static_render_daemon.log` (when launched via the start
scripts) or stdout (when run manually).

### Enqueue jobs

Enqueue every public top-level record of a datatype:

```bash
# By numeric id
php app/console odr_static_render:enqueue --datatype_id=738

# By UUID (alternative)
php app/console odr_static_render:enqueue --datatype_uuid=ddc5e9ba834ad596cc31aebb1225
```

The command prints how many records were queued. Records are filtered to:
- Top-level (grandparent === self)
- Not soft-deleted
- Public (`publicDate != '2200-01-01 00:00:00'`)

#### `--limit=N` — cap how many records get enqueued

The console command accepts an optional **`--limit=N`** flag to stop
after `N` records. Use it on dev to keep the queue manageable while
testing, or for smoke-testing a render change against a handful of
records before committing to the full datatype.

```bash
# Smoke test: just the first 5 records of this datatype
php app/console odr_static_render:enqueue --datatype_id=738 --limit=5

# Dev-box cap: 300 records is usually enough to validate the pipeline
php app/console odr_static_render:enqueue --datatype_id=738 --limit=300
```

- Default is `0`, which means **no cap** (enqueues every matching record).
- `--limit` is applied as a SQL `LIMIT` on the id+uuid pull, so it
  doesn't waste time fetching the full list.
- Records are ordered by `dr.id ASC`, so the same N records are picked
  consistently across runs.
- The flag is the **only** record-count cap in the system. There is no
  hidden dev-mode setting, no env var, no parameter — production should
  run the bare command (no `--limit`) to render everything.

### Verify output

```bash
# Daemon log
tail -f app/logs/static_render_daemon.log

# Beanstalkd queue depth (telnet style)
echo -e 'stats-tube odr_static_render\r\nquit' | nc 127.0.0.1 11300

# Generated files
ls -la web/uploads/static/<site_folder>/<datatype_uuid>/
```

A successful render writes
`<site_folder>/<datatype_uuid>/<record_uuid>.html` containing the
`<!DOCTYPE html>` followed by the document's outer HTML — exactly what the
user would see, but as a static file.

### Manual smoke test

To enqueue a single record without going through the command:

```bash
# Quick PHP one-liner from app/console; replace 12345 with a real record id.
php app/console doctrine:query:sql \
    "SELECT 1" >/dev/null    # warms the autoloader cache, optional

php -r '
require "app/autoload.php";
require_once "app/AppKernel.php";
$kernel = new AppKernel("dev", true);
$kernel->boot();
$container = $kernel->getContainer();
$em = $container->get("doctrine.orm.entity_manager");
$svc = $container->get("odr.static_render_service");
$record = $em->getRepository("ODRAdminBundle:DataRecord")->find(12345);
echo $svc->enqueueRecord($record), PHP_EOL;
'
```

Then watch `app/logs/static_render_daemon.log` for the render output.

---

## Troubleshooting

### Daemon log shows `Skipping stale job for record N`
A newer enqueue bumped the version while this job was still in the queue.
This is the dedup logic working as designed. The latest job will run.

### `waitForFunction` timeout (`window.__odrRenderReady`)
The display page never reached the end of `initPage()`. Possible causes:
- The record requires login but the daemon is hitting the URL anonymously.
  Solution: ensure the record's grandparent is public, and the datatype
  itself is publicly viewable.
- A render plugin throws a JS error before `initPage()` completes. Open the
  URL in a browser; check the JS console.
- The render-ready signal hasn't been deployed yet. Confirm the
  `display_ajax.html.twig` change is in place and Symfony's prod cache has
  been cleared.

### Late-rendering / lazy content cut off

The daemon captures HTML after a fixed `STATIC_RENDER_POST_READY_MS`
grace period (default 500 ms) following the render-ready signal. If
some plugin defers content beyond that window (animated chart that
needs 800 ms to draw, etc.) bump it up:

```bash
STATIC_RENDER_POST_READY_MS=1500 node static_render_daemon.js
```

### Investigating what the page is doing at capture time

Set `STATIC_RENDER_DEBUG_NET=1` to log a per-render network breakdown
during the grace period:

```
[debug-net] still-pending requests: 2
   pending  image      0.5s  data:image/webp;base64,...
   pending  script     0.5s  blob:https://dev.rruff.net/...
[debug-net] repeated requests during render (2+ hits):
    12x  https://dev.rruff.net/wp-admin/admin-ajax.php
```

- **`still-pending`** entries are requests Puppeteer thinks are open
  at capture time. `data:` and `blob:` URIs commonly appear here as
  phantoms — they don't actually go through the network stack but
  Puppeteer never fires their `requestfinished` event. Genuinely
  pending HTTP requests of types `xhr` / `fetch` / `script` are the
  ones worth investigating.
- **Repeated requests** are URLs that fired ≥2 times during the
  render. Heartbeats (`admin-ajax.php?action=heartbeat`) and
  analytics polling show up here. If a URL is hammered, that's a
  candidate for `page.setRequestInterception()` blocking.

### `Page returned HTTP 4xx/5xx`
The daemon is hitting a URL that doesn't redirect to a renderable page.
- Confirm `site_baseurl` in `app/config/parameters.yml` resolves from the
  machine running the daemon.
- Confirm the route `/view/record/{uuid}` works in a browser for the same
  record id.

### `net::ERR_CERT_AUTHORITY_INVALID` / self-signed certs on dev
On local dev boxes the site baseurl often points at a host with an invalid
or self-signed certificate (e.g. `https://dev.odr.io`). Chrome refuses
those by default, so the daemon will fail every job with
`net::ERR_CERT_AUTHORITY_INVALID`.

To skip cert validation, set:

```bash
export STATIC_RENDER_IGNORE_HTTPS_ERRORS=1
```

before launching the daemon. This adds `--ignore-certificate-errors` to
the Chrome args and sets Puppeteer's `ignoreHTTPSErrors: true` so neither
layer blocks the navigation. The daemon logs
`STATIC_RENDER_IGNORE_HTTPS_ERRORS=1 — skipping HTTPS cert validation.`
on startup when it's active.

Off by default. **Do not enable this in production** — a misconfigured
target host could silently render a wrong page from a MITM. Keep it
scoped to dev startup scripts only, e.g.:

```bash
# In start_jobs_dev.sh only:
STATIC_RENDER_IGNORE_HTTPS_ERRORS=1 nodemon static_render_daemon.js \
    >> ../app/logs/static_render_daemon.log 2>&1 &
```

### Output directory not created
The daemon `mkdir -p`s the directory before writing. If permission denied,
the Apache user (and the user running the daemon) must both be able to
write under `web/uploads/static/`. The existing `set_cache_permissions.sh`
covers `web/uploads/`; you may need to extend it.

### Queue won't drain
- Check the daemon is actually running: `pgrep -af static_render_daemon`.
- Beanstalkd might be down: `nc -z 127.0.0.1 11300 || echo down`.
- Redis might be down: `redis-cli ping`.

### `Daemon failed to start: Failed to launch the browser process`

The daemon needs a working Chromium/Chrome binary. Probe order (first hit
wins):

1. `$PUPPETEER_EXECUTABLE_PATH` (env var override).
2. `~/.cache/ms-playwright/chromium-*/chrome-linux/chrome` — Playwright's
   Chromium download (the only sane path on ARM64 Linux).
3. `<background_services>/chromium/linux*/chrome-linux/chrome` —
   `@puppeteer/browsers`-managed download in the project cache.
4. `/usr/bin/google-chrome-stable`, `google-chrome`, `chromium`,
   `chromium-browser` — apt-installed system browsers.
5. `/snap/bin/chromium` — last resort; usually doesn't work (see below).
6. Puppeteer's own bundled Chrome — also last resort.

The daemon passes `--no-sandbox --disable-setuid-sandbox` because
snap-confined Chromium and root-launched Chrome both refuse to start
without it. Drop those flags if you're running a sandbox-capable build
and want sandboxing.

#### ARM64 Linux specifics

Two issues compound on aarch64 Ubuntu:

1. **Puppeteer 20.x and `@puppeteer/browsers` only ship x86_64 Linux
   binaries.** Chromium's official snapshot storage
   (`storage.googleapis.com/chromium-browser-snapshots`) doesn't have an
   ARM64 Linux folder, and `@puppeteer/browsers/lib/cjs/browser-data/chromium.js`
   maps `LINUX_ARM` → `Linux_x64` regardless. So `npx @puppeteer/browsers
   install chromium` produces a binary that won't run; the kernel falls
   back to `/bin/sh` interpretation and you'll see an "Unterminated quoted
   string" syntax error.
2. **System Chromium on Ubuntu 22.04+ is snap-only.** The snap binary
   refuses to launch when its parent process isn't already inside the
   snap cgroup, with errors like
   `is not a snap cgroup for tag snap.chromium.chromium`.

**Recommended fix**: install Playwright's Chromium, which has real ARM64
Linux builds:

```bash
cd background_services
npm install --save-dev playwright-core
npx playwright install chromium
```

The daemon's probe finds it automatically at
`~/.cache/ms-playwright/chromium-*/chrome-linux/chrome`. Verify the binary
arch with `file <path>` — it should report `aarch64`, not `x86-64`.

**Alternative — make the snap chromium work** by giving it its own
systemd scope (creates a fresh cgroup that snap-confine accepts):

```bash
# In start_jobs.sh, replace the static_render_daemon line with:
systemd-run --user --scope node static_render_daemon.js \
    >> ../app/logs/static_render_daemon.log 2>&1 &
```

Then `PUPPETEER_EXECUTABLE_PATH=/snap/bin/chromium` — or just let the
probe find it. This is more fragile than option A and depends on running
under a systemd-managed user session.

---

## Phase 2 components (built)

| Component | Path |
| --- | --- |
| API endpoint — enqueue datatype | `APIController::staticRenderEnqueueAction` |
| API endpoint — queue status | `APIController::staticRenderStatusAction` |
| Sitemap controller | `APIController::sitemapAction` |
| Routes | [api.yml](src/ODR/AdminBundle/Resources/config/api.yml) (`odr_api_static_render_*`, `odr_sitemap`) |
| Event hooks | `ODREventSubscriber` (Modified, Deleted, PublicStatusChanged) |
| Daemon concurrency | `STATIC_RENDER_CONCURRENCY` env var |
| Service helpers | `StaticRenderService::deleteStaticFile`, `getQueueStatus`, `listRenderedFiles`, `getPublicUrlForFile` |

### API: enqueue a datatype

```http
POST /api/v1/dataset/{dataset_uuid}/static_render
Content-Type: application/json

{ "user_email": "admin@example.com" }

→ 200 { "datatype_uuid": "...", "queued": 47 }
```

Auth follows the same convention as `datasetQuotaByUUIDAction`: caller
posts a `user_email`, that user must be a datatype admin (via
`PermissionsManagementService::isDatatypeAdmin`). Same query as the
console command — public, top-level records only.

### API: am-I-logged-in probe

```http
GET /api/v1/auth/status
Origin: https://<site_baseurl host>
Cookie: <session cookie>

→ 200 { "logged_in": true|false }
```

Used by cached static pages: a small `<script>` injected into `<head>`
calls this endpoint with `credentials: 'include'`, and on `logged_in:
true` redirects the visitor to the dynamic record URL so they see the
full record (including any non-public data they have access to).

The endpoint:
- Detects login via Symfony's security context. WP-integrated installs
  bridge WP login → Symfony via the existing `WORDPRESS_USER` env-var
  hand-off in `page-odr.php`, so the same endpoint works for both.
- Always returns 200 (never 401/403). The script only redirects when
  the JSON body says `logged_in: true`.
- Includes CORS headers (`Access-Control-Allow-Origin: <site_baseurl>`,
  `Access-Control-Allow-Credentials: true`, `Vary: Origin`) **only**
  for requests whose `Origin` matches `site_baseurl`. Any other origin
  is ignored — never reflected.

### Cached-page redirect script

The static-render daemon injects a tiny `<script>` into `<head>` right
before capturing the HTML. The script reads two URLs baked in at
render time:

- **auth-check URL** — `<fetch_baseurl>/api/v1/auth/status`
- **dynamic URL** — `<fetch_baseurl>/view/record/<r_uuid>`

Behavior at view time:
1. Visitor (e.g. a Googlebot crawler or anonymous user) loads the
   cached page. Script fires.
2. `fetch(auth-check, credentials:'include')` — browser sends session
   cookies for `fetch_baseurl`'s origin. Cross-origin in WP-integrated
   mode (cached file lives under `site_baseurl`, cookies live under
   `wordpress_site_baseurl`); CORS makes that work.
3. If the response says `logged_in: true`, `window.location.replace`
   sends the visitor to the dynamic URL.
4. Otherwise, the cached page stays put — full content visible to
   anonymous visitors / crawlers.

### API: queue status

```http
GET /api/v1/static_render/status

→ 200 {
  "name":       "odr_static_render",
  "ready":      0,
  "reserved":   1,
  "delayed":    0,
  "buried":     0,
  "total_jobs": 53,
  "workers":    4
}
```

No auth. Reads beanstalkd `stats-tube` directly via the pheanstalk
service. `workers` is the count of clients currently watching the tube
(daemon worker slots).

### JSON record snapshot

Alongside each `<record_uuid>.html`, the daemon also writes a
`<record_uuid>.json` file containing the same record's public API
representation. Both files live in the same directory:

```
web/uploads/static/{site_folder}/{datatype_uuid}/
    abc...html
    abc...json
    def...html
    def...json
```

How it works:

1. On startup the daemon reads `ODR_API_USERNAME` / `ODR_API_PASSWORD`
   from `background_services/.env` (loaded via `dotenv` if installed,
   or a tiny built-in parser otherwise).
2. The first job that needs a token does `POST <odr_baseurl>/api/v5/token`
   with `{username, password}` and caches the returned JWT in memory.
   (All API calls use `odr_baseurl` = `site_baseurl` — the API is
   served by Symfony on the ODR backend directly, never through the
   WordPress host, even on WP-integrated installs.)
3. For each job, after the HTML is written, the daemon does
   `GET <odr_baseurl>/api/v5/search/record/<uuid>.json` with
   `Authorization: Bearer <jwt>` and writes the body to disk verbatim.
4. On `401` the daemon re-fetches the token once and retries.

Failures here are **non-fatal**: a missing or unreachable API just
logs `[json] ...` warnings and the job still completes — the HTML
render is the authoritative output, JSON is a companion.

Security: the daemon doesn't enforce anything on the resulting `.json`
file — the API account itself is expected to only have access to
public records, so whatever it returns is fair game for the public
cache. If the account has broader permissions, the cached JSON could
leak more than you want.

Toggles (all optional):

- `STATIC_RENDER_SKIP_JSON=1` — skip JSON (and schema) fetching entirely
  without removing credentials.

### Datatype schema snapshot

Each datatype also gets a single `schema.json` written into its
directory, fetched from
`GET <odr_baseurl>/api/v5/search/database/<datatype_uuid>.json`:

```
web/uploads/static/{site_folder}/{datatype_uuid}/
    schema.json          ← one per datatype
    abc...html
    abc...json
    ...
```

The schema is identical for every record of a datatype, so the daemon
only does the fetch **once per datatype per run** (`[schema] ...` log
lines), and skips entirely if the file already exists on disk from a
prior run. To force a refresh after a schema change, delete the
`schema.json` and re-run. Same credentials, token, and 401-retry path
as the record JSON; same `STATIC_RENDER_SKIP_JSON=1` opt-out.

The sitemap **index** (`/sitemap.xml`) advertises the schema as a
`<schema>` sibling of `<loc>` in each datatype's `<sitemap>` entry,
emitted only when the `schema.json` actually exists:

```xml
<sitemap>
    <loc>https://dev.rruff.net/sitemap-<dt_uuid>.xml</loc>
    <schema>https://dev.odr.io/uploads/static/<site_folder>/<dt_uuid>/schema.json</schema>
    <lastmod>2026-06-10T12:34:56Z</lastmod>
</sitemap>
```

(Like `<json>`, `<schema>` is a non-standard extension search engines
ignore; ODR tooling can use it to discover the schema URL.)

Because the daemon only writes `schema.json` when it's absent, a schema
change won't propagate on a normal re-render. Force a refresh with the
`--purge-schemas` flag on the enqueue command — it deletes the
datatype's cached `schema.json` first, so the daemon re-fetches it:

```bash
php app/console odr_static_render:enqueue --datatype_id=738 --purge-schemas
```

(Equivalent to deleting `web/uploads/static/<site_folder>/<dt_uuid>/schema.json`
by hand before re-running.)

### Sitemap (index + per-datatype children)

```http
GET /sitemap.xml                            # sitemap index
GET /sitemap-{dataset_uuid}.xml             # child sitemap, page 1
GET /sitemap-{dataset_uuid}-{page}.xml      # child sitemap, page 2+
```

`/sitemap.xml` is a `<sitemapindex>` listing one or more child sitemaps
per datatype. The sitemaps.org / Google / Bing spec caps a child
sitemap at **50,000 URLs** (and 50 MB), so datatypes with more than
50,000 rendered records get split into pages of 50k each.

Each `<sitemap>` entry in the index has its own `<lastmod>` set to the
maximum file mtime within that page, so crawlers can skip unchanged
sections without fetching them.

Each child `<urlset>` has one `<url>` per rendered file:

```xml
<url>
    <loc>https://dev.odr.io/uploads/static/dev.rruff.net/<dt>/<r>.html</loc>
    <json>https://dev.odr.io/uploads/static/dev.rruff.net/<dt>/<r>.json</json>
    <lastmod>2026-05-23T12:34:56Z</lastmod>
</url>
```

- `<loc>` — direct webroot URL of the rendered HTML.
- `<json>` — companion API JSON snapshot. Emitted **only** if the
  `.json` sibling actually exists on disk (the daemon writes them
  best-effort; if API creds are missing or the fetch failed, the
  tag is silently omitted so there's no dead URL).
- `<lastmod>` — HTML file's mtime in UTC ISO8601.

Note that `<json>` is a non-standard extension to the sitemaps.org
schema. Search engines that don't recognize it will silently ignore
the element; downstream ODR tooling (and any consumer that knows to
look for it) will see the URL and can fetch the snapshot directly.

The sitemap intentionally does **not** point at the dynamic
`/view/record/{uuid}` URL — that route requires JS to run before it
can show content, and indexers can't always handle that. Pointing at
the static file gets the content into Google directly.

Why split per-datatype:
- Stays under the 50k-URL cap.
- Crawlers re-fetch only the sitemap whose `<lastmod>` changed, so an
  edit to a single record only invalidates that datatype's child
  sitemap, not the whole index.
- Easier to inspect / debug.

The sitemap-index format itself can hold up to 50,000 child sitemaps,
so we'd need ~2.5 billion records before we'd outgrow it.

### Event hooks

Wired in `ODREventSubscriber`:

| Event | Behavior |
| --- | --- |
| `DatarecordModifiedEvent` | Re-enqueue the affected record's grandparent (only if it's public + top-level). Dedup is handled by the per-record version counter, so rapid edits collapse into one render. |
| `DatarecordDeletedEvent` | If the deleted record was top-level, delete its static file from disk so the sitemap stays accurate. |
| `DatarecordPublicStatusChangedEvent` | Becoming public → enqueue. Becoming private → delete the static file from disk. |

Failures in the static-render path are caught and logged via
`$this->logger->warning(...)` so they don't break the rest of the
existing event handlers.

### Daemon concurrency

```bash
export STATIC_RENDER_CONCURRENCY=4   # default 1, capped at 16
```

Spawns N parallel reserve-process-delete loops, each on its own
beanstalkd TCP connection (nodestalker serializes per-connection, so
one connection = one slot). All slots share the single Puppeteer
browser instance and each opens its own page. The startup log line
shows the active count:
`Watching tube "odr_static_render" for static-render jobs (concurrency=4)...`

Per-slot log entries are tagged: `[slot 2] Reserved job 17`.

## Still deferred (Phase 3 candidates)

- **Plugin "register pending work" hook** — render plugins with their
  own AJAX could explicitly extend the ready window beyond what
  network-idle catches.
- **Frontend admin UI button** — "Generate Static Pages" on each
  datatype's admin page that calls the API endpoint.
- **Per-record progress** — today the `status` endpoint only knows
  queue depth from beanstalkd; no per-record state.

---

## Useful commands

```bash
# Total ready jobs in the tube
echo -e 'stats-tube odr_static_render\r\nquit' | nc 127.0.0.1 11300 | grep current-jobs

# Drain (delete) all jobs without running them
# (Run this against an existing tube; nothing fancy here.)
php app/console odr_static_render:enqueue --datatype_id=738 --help

# Inspect Redis version counters
redis-cli --scan --pattern 'static_render:version:*' | head
redis-cli get 'static_render:version:12345'
```
