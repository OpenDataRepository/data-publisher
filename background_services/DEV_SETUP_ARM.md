# Running the Puppeteer background services on ARM dev boxes

The graph-rendering and clear-tube/precache workers in this directory drive headless
Chromium through Puppeteer. On **arm64 Linux** (e.g. an Apple-silicon VM or a Raspberry-Pi-class
box running Ubuntu) the default Puppeteer setup doesn't work, for a few separate reasons:

1. **Puppeteer ships no usable arm64 Chrome.** Its bundled download is x86-64, and its browser
   cache on arm can even contain a mislabeled `linux_arm` build that aborts with
   `cannot execute binary file: Exec format error`.
2. **The only system Chromium on Ubuntu is the snap**, and snap confinement refuses to be
   launched by a non-snap process: `... is not a snap cgroup for tag snap.chromium.chromium`.
   (`/usr/bin/chromium-browser` is just a shell shim that redirects to the snap.)
3. On **Ubuntu 23.10+/24.04** AppArmor restricts unprivileged user namespaces
   (`kernel.apparmor_restrict_unprivileged_userns = 1`), so even a working Chromium aborts with
   `No usable sandbox!` unless launched with `--no-sandbox`.

## How it works now — automatic, no shell config

All the workers share **`chromium_launcher.js`**. Each script just does:

```js
const chromium = require('./chromium_launcher');
const browser  = await chromium.launch(puppeteer);
```

and the launcher resolves everything at runtime **from the host itself** — nothing lives in
`~/.bashrc`, and nothing is machine-specific outside of git:

- **Which Chromium to use.** It probes, in order: `PUPPETEER_EXECUTABLE_PATH` (if you set one) →
  Playwright's cache → Puppeteer's cache → a project-local download → system chrome/chromium.
  Cache binaries are **ELF-arch-validated**, so an x86 or mislabeled build is skipped rather than
  launched into an `Exec format error`. Snap shims are detected and skipped. If nothing usable is
  found it prints a one-time warning (see below) and lets Puppeteer try its bundled binary.
- **`--no-sandbox`.** Added automatically **only** where the host needs it — running as root, or a
  kernel that restricts unprivileged user namespaces (Ubuntu 23.10+/24.04). On a normal production
  host none of these apply, so Chromium launches sandboxed — i.e. **normal Puppeteer on production.**
- **TLS leniency.** Off by default (production keeps strict cert validation). Opt in per box — see
  `.env` below.

### The only setup step on an arm box: install a real Chromium

```bash
cd /path/to/data-publisher/background_services
npx playwright install chromium      # Playwright publishes genuine arm64 Linux builds
```

That's it. The launcher finds it in `~/.cache/ms-playwright/…` automatically on the next run — no
env vars, no `~/.bashrc`. If you skip this step, every worker prints:

```
[chromium_launcher] No architecture-matching Chromium found for arm64/linux. ...
  Install a matching build:  cd background_services && npx playwright install chromium
```

### Running a service

```bash
cd /path/to/data-publisher/background_services
node graph_renderer_daemon.js        # or any of the other workers
```

## Per-box settings (optional) — in `.env`, not your shell

The one genuinely per-box knob is TLS leniency (dev boxes often serve `odr.io` with a
self-signed / staging cert). Put it in **`background_services/.env`** — which is git-ignored and
has a committed `.env.example` — so it travels with the repo instead of living in `~/.bashrc`:

```ini
# background_services/.env
ODR_CHROME_IGNORE_CERT=1   # accept self-signed / untrusted certs (DEV ONLY)
```

Every worker loads `.env` automatically (non-overriding). Overrides you can also set, if ever
needed:

| Variable | Purpose |
|----------|---------|
| `PUPPETEER_EXECUTABLE_PATH` | Force a specific Chromium binary, bypassing auto-detection. |
| `ODR_CHROME_NO_SANDBOX` | `1` forces `--no-sandbox`; `0` forces it off. Unset = auto-detect. |
| `ODR_CHROME_IGNORE_CERT` | `1` accepts invalid/self-signed TLS certs. Unset = strict. |
| `STATIC_RENDER_IGNORE_HTTPS_ERRORS` | Legacy alias for the cert toggle (still honored). |

## Why this is safe for production

On the x86-64 production host the auto-detection finds Puppeteer's own (working) Chrome, the
sandbox checks all pass so Chromium launches **sandboxed**, and no cert toggle is set so TLS
validation stays strict. None of the arm/dev workarounds engage unless the host actually meets the
condition for them. So production runs "normal Puppeteer" with no configuration.

## Alternative to `--no-sandbox` (keeps the sandbox)

If you'd rather not disable the sandbox on the dev box, re-enable unprivileged user namespaces
system-wide and the launcher will stop adding `--no-sandbox` on its own:

```bash
sudo sysctl -w kernel.apparmor_restrict_unprivileged_userns=0
echo 'kernel.apparmor_restrict_unprivileged_userns=0' | sudo tee /etc/sysctl.d/60-apparmor-userns.conf
```

## Troubleshooting

- `No architecture-matching Chromium found …` → run `npx playwright install chromium`.
- `... is not a snap cgroup` → the launcher fell through to a snap shim; install the Playwright
  Chromium so it's preferred. (`file "$(command -v chromium-browser)"` will show it's a shell shim.)
- `No usable sandbox!` → `--no-sandbox` wasn't applied. The launcher auto-detects this from
  `/proc/sys/kernel/apparmor_restrict_unprivileged_userns`; if you run under a scheduler with a
  different kernel view, set `ODR_CHROME_NO_SANDBOX=1` in `.env`.
- `net::ERR_CERT_AUTHORITY_INVALID …` → set `ODR_CHROME_IGNORE_CERT=1` in `.env`.
- Verify a binary's arch without launching it: `file "<path>/chrome"` should say `ARM aarch64`.

## Note

The two daemons (`static_render_daemon.js`, `graph_renderer_daemon.js`) still contain an old local
`findChromiumExecutable()` that is now unused — the shared `chromium_launcher.js` supersedes it.
It's harmless dead code and can be deleted.
