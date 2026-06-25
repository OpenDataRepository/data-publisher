# Running the Puppeteer background services on ARM dev boxes

The graph-rendering and clear-tube/precache workers in this directory drive headless
Chromium through Puppeteer. On **arm64 Linux** (e.g. an Apple-silicon VM or a Raspberry-Pi-class
box running Ubuntu) the default Puppeteer setup doesn't work, for two separate reasons:

1. **Puppeteer ships no arm64 Chrome.** Its bundled download is x86-64 only, so
   `puppeteer.launch()` fails to find a runnable browser.
2. **The only system Chromium on Ubuntu is the snap**, and snap confinement refuses to be
   launched by a non-snap process:
   `... is not a snap cgroup for tag snap.chromium.chromium`.
3. On **Ubuntu 23.10+/24.04** AppArmor restricts unprivileged user namespaces
   (`kernel.apparmor_restrict_unprivileged_userns = 1`), so even a working Chromium aborts with
   `No usable sandbox!`.

## The fix (two env vars, no per-machine code changes)

We point Puppeteer at **Playwright's** Chromium — Playwright *does* publish genuine arm64 Linux
builds — and opt into `--no-sandbox` only on these dev boxes.

### One-time setup

```bash
# 1. Install Playwright's arm64 Chromium (Playwright is already a dependency here).
cd /path/to/data-publisher/background_services
npx playwright install chromium

# 2. Add the two env vars to your shell (~/.bashrc). The `ls ... | sort -V | tail -1`
#    resolves the newest chromium-<rev> folder, so it survives Playwright updates.
cat >> ~/.bashrc <<'EOF'

# ODR background services on ARM dev boxes (graph rendering via Puppeteer)
export PUPPETEER_EXECUTABLE_PATH="$(ls -d "$HOME"/.cache/ms-playwright/chromium-*/chrome-linux/chrome 2>/dev/null | sort -V | tail -1)"
export ODR_CHROME_NO_SANDBOX=1
export ODR_CHROME_IGNORE_CERT=1   # dev boxes serve odr.io with an untrusted (self-signed/staging) cert
EOF
source ~/.bashrc
```

### Running a service

```bash
cd /path/to/data-publisher/background_services
node graph_renderer_daemon.js          # or any of the other workers
```

That's it — both env vars are read automatically.

| Env var | Purpose |
|---------|---------|
| `PUPPETEER_EXECUTABLE_PATH` | Path to the arm64 Chromium binary. Puppeteer uses it as the default `executablePath`. |
| `ODR_CHROME_NO_SANDBOX` | When set, the services pass `--no-sandbox --disable-setuid-sandbox` to Chromium (the Ubuntu 24.04 userns workaround). |
| `ODR_CHROME_IGNORE_CERT` | When set, the services launch Chromium with `acceptInsecureCerts`, so navigations to a self-signed / untrusted-CA `odr.io` succeed. Leave unset on production (valid cert). |

These three flags are independent — e.g. a staging box could have a valid cert but still need
`--no-sandbox`. Set only the ones that apply.

## Why this is safe for production

Every `puppeteer.launch()` call in this directory was changed to:

```js
puppeteer.launch({ headless: 'new',
  acceptInsecureCerts: !!process.env.ODR_CHROME_IGNORE_CERT,
  args: process.env.ODR_CHROME_NO_SANDBOX ? ['--no-sandbox','--disable-setuid-sandbox'] : [] })
```

So **production behaviour is unchanged unless these env vars are set** — on the x86-64
production host (where Puppeteer's own Chrome works, the sandbox is fine, and `odr.io` has a valid
cert) none of them are set, so Chromium launches normally with its sandbox enabled and full cert
validation. These flags only ever take effect on a box that explicitly opts in.

## Alternative to `--no-sandbox` (keeps the sandbox)

If you'd rather not disable the sandbox, re-enable unprivileged user namespaces system-wide instead
and leave `ODR_CHROME_NO_SANDBOX` unset:

```bash
sudo sysctl -w kernel.apparmor_restrict_unprivileged_userns=0
echo 'kernel.apparmor_restrict_unprivileged_userns=0' | sudo tee /etc/sysctl.d/60-apparmor-userns.conf
```

This relaxes an Ubuntu kernel-hardening setting machine-wide, which is why the per-app
`--no-sandbox` opt-in is the default for dev boxes here.

## Troubleshooting

- `Failed to launch the browser process! ... is not a snap cgroup` → `PUPPETEER_EXECUTABLE_PATH`
  is unset or still pointing at the snap; confirm it resolves to a path under `~/.cache/ms-playwright`.
- `No usable sandbox!` → `ODR_CHROME_NO_SANDBOX` isn't set in the shell that started the service
  (note: it won't be inherited by systemd/cron jobs unless you set it there too).
- `net::ERR_CERT_AUTHORITY_INVALID at https://www.odr.io/...` → `ODR_CHROME_IGNORE_CERT` isn't set;
  the dev box is serving `odr.io` with a self-signed / untrusted-CA cert.
- `Could not find Chromium ... PUPPETEER_EXECUTABLE_PATH` empty → run `npx playwright install chromium`.
- Verify the binary is actually arm64: `file "$PUPPETEER_EXECUTABLE_PATH"` should say `ARM aarch64`.
