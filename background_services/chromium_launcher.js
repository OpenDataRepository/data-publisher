'use strict';

/*
 * Shared Chromium launch configuration for every Puppeteer worker in this
 * directory. The goal: each script "just works" on both the x86-64 production
 * host and arm64 dev boxes, with no per-machine shell config (no ~/.bashrc
 * exports). All a script does is:
 *
 *     const chromium = require('./chromium_launcher');
 *     const browser  = await chromium.launch(puppeteer);
 *
 * What this resolves automatically:
 *
 *   1. executablePath — finds a Chromium binary whose CPU architecture matches
 *      this host. Puppeteer's bundled Chrome is x86-only, and its browser cache
 *      on arm can contain a mislabeled/incompatible build, so we validate the
 *      ELF header rather than trusting a path. Order: PUPPETEER_EXECUTABLE_PATH
 *      override -> Playwright cache (ships real arm64 builds) -> Puppeteer cache
 *      (arch-validated) -> project-local download -> system chrome/chromium
 *      (snap excluded). If nothing usable is found, we emit a one-time warning
 *      telling the operator how to install a matching build, then let Puppeteer
 *      fall back to its bundled binary.
 *
 *   2. --no-sandbox — enabled automatically only where the OS actually needs it
 *      (running as root, or a kernel that restricts unprivileged user
 *      namespaces, e.g. Ubuntu 23.10+/24.04 with AppArmor). On a normal
 *      production host none of these apply, so Chromium launches sandboxed —
 *      i.e. "normal Puppeteer on production." Override either way with
 *      ODR_CHROME_NO_SANDBOX=1 / =0.
 *
 *   3. TLS leniency — off by default (production keeps strict cert validation).
 *      Opt in per-box with ODR_CHROME_IGNORE_CERT=1 (or the legacy
 *      STATIC_RENDER_IGNORE_HTTPS_ERRORS=1). These can live in
 *      background_services/.env (git-ignored, with a committed .env.example),
 *      so the setting is repo-local rather than a shell dependency.
 *
 * Nothing here is machine-specific or unmanaged by git: detection is derived at
 * runtime from the host, and the only per-box knob (cert leniency) has a
 * documented home in .env.
 */

const fs = require('fs');
const os = require('os');
const path = require('path');

// Loads background_services/.env (non-overriding) AND relaxes Node's own TLS
// trust when cert-leniency is opted in — so both the browser and any direct
// https/fetch calls honor the single ODR_CHROME_IGNORE_CERT flag.
require('./dev_env');

// ---------------------------------------------------------------------------
// Small helpers
// ---------------------------------------------------------------------------
function isSet(name) {
    return Object.prototype.hasOwnProperty.call(process.env, name) && process.env[name] !== '';
}
function isTruthy(v) {
    return v !== undefined && v !== null && v !== '' && v !== '0' && String(v).toLowerCase() !== 'false';
}
function isExecutable(p) {
    try { fs.accessSync(p, fs.constants.X_OK); return true; } catch (e) { return false; }
}
function readProc(p) {
    try { return fs.readFileSync(p, 'utf8').trim(); } catch (e) { return null; }
}
// A /usr/bin/chromium* that is actually a shell shim redirecting to the snap.
// Snap-confined Chromium refuses to launch from a non-snap process ("is not a
// snap cgroup for tag snap.chromium.chromium"), so such shims are unusable here.
function looksLikeSnapWrapper(p) {
    let fd;
    try {
        fd = fs.openSync(p, 'r');
        const buf = Buffer.alloc(4096);
        const n = fs.readSync(fd, buf, 0, 4096, 0);
        if (n >= 4 && buf[0] === 0x7f && buf[1] === 0x45) return false; // ELF binary, not a shim
        return /snap/i.test(buf.slice(0, n).toString('utf8'));
    } catch (e) {
        return false;
    } finally {
        if (fd !== undefined) { try { fs.closeSync(fd); } catch (e) { /* ignore */ } }
    }
}
function listDir(d) {
    try { return fs.readdirSync(d); } catch (e) { return []; }
}

const _warned = new Set();
function warnOnce(msg) {
    if (_warned.has(msg)) return;
    _warned.add(msg);
    console.warn('[chromium_launcher] ' + msg);
}

// ---------------------------------------------------------------------------
// ELF architecture validation. Reads the ELF header's e_machine field and
// compares it to this host's arch, WITHOUT executing the binary. Returns:
//   true  -> ELF, arch matches host
//   false -> ELF, arch does NOT match (would give "Exec format error")
//   null  -> can't tell (not an ELF, e.g. a shell-script wrapper; or read error)
// ---------------------------------------------------------------------------
const ELF_MACHINE = { x64: 0x3e, arm64: 0xb7, arm: 0x28, ia32: 0x03 };
function elfArchMatches(file) {
    let fd;
    try {
        fd = fs.openSync(file, 'r');
        const buf = Buffer.alloc(20);
        const n = fs.readSync(fd, buf, 0, 20, 0);
        if (n < 20) return null;
        if (buf[0] !== 0x7f || buf[1] !== 0x45 || buf[2] !== 0x4c || buf[3] !== 0x46) return null; // "\x7fELF"
        const want = ELF_MACHINE[process.arch];
        if (want === undefined) return null; // unknown host arch: don't reject on a guess
        return buf.readUInt16LE(18) === want;
    } catch (e) {
        return null;
    } finally {
        if (fd !== undefined) { try { fs.closeSync(fd); } catch (e) { /* ignore */ } }
    }
}

// A cache dir can hold several Chrome versions side by side (e.g.
// linux-113.0.5672.63, linux-152.0.7977.42). readdirSync order is roughly
// alphabetical, which would pick the OLDEST — and an old Chrome fails to speak
// the current puppeteer-core's protocol (30s launch timeout). So sort each
// source newest-first by its embedded version number.
function versionTuple(name) {
    const m = name.match(/\d+/g);
    return m ? m.map(Number) : [];
}
function byVersionDesc(a, b) {
    const va = versionTuple(a), vb = versionTuple(b);
    const len = Math.max(va.length, vb.length);
    for (let i = 0; i < len; i++) {
        const x = va[i] || 0, y = vb[i] || 0;
        if (x !== y) return y - x;   // descending: newest first
    }
    return 0;
}

// Cache-managed Chromium binaries. These are real ELF binaries, so we always
// arch-validate them (that's what filters out the x86 / mislabeled builds that
// otherwise crash with "Exec format error" on arm64). Within each source the
// newest version is tried first.
function cacheCandidates() {
    const home = os.homedir();
    const out = [];

    // Playwright — publishes genuine per-platform arm64 Linux builds.
    const pw = path.join(home, '.cache', 'ms-playwright');
    for (const d of listDir(pw).filter(function (n) { return /^chromium[-_]/.test(n); }).sort(byVersionDesc)) {
        out.push(path.join(pw, d, 'chrome-linux', 'chrome'));
    }

    // Puppeteer's own browser cache. On arm this may hold an x86 build and/or a
    // mislabeled "linux_arm" build; arch validation drops the unusable ones.
    const pp = path.join(home, '.cache', 'puppeteer', 'chrome');
    for (const d of listDir(pp).sort(byVersionDesc)) {
        out.push(path.join(pp, d, 'chrome-linux64', 'chrome'));
        out.push(path.join(pp, d, 'chrome-linux', 'chrome'));
    }

    // Project-local download (background_services/chromium/linux*/...).
    const proj = path.join(__dirname, 'chromium');
    for (const d of listDir(proj).filter(function (n) { return /^linux/.test(n); }).sort(byVersionDesc)) {
        out.push(path.join(proj, d, 'chrome-linux', 'chrome'));
        out.push(path.join(proj, d, 'chrome-linux64', 'chrome'));
    }
    return out;
}

// System installs. NOT arch-validated: these are often shell-script wrappers
// (not ELF), and a distro installs the right arch anyway. Snap is excluded —
// snap-confined Chromium refuses to launch outside its own cgroup.
const SYSTEM_CANDIDATES = [
    '/usr/bin/google-chrome-stable',
    '/usr/bin/google-chrome',
    '/usr/bin/chromium',
    '/usr/bin/chromium-browser',
];

let _cachedExe; // undefined = not resolved yet, null = none found
function detectExecutable() {
    if (_cachedExe !== undefined) return _cachedExe;

    // 1. Explicit operator override always wins; just sanity-check it.
    const override = process.env.PUPPETEER_EXECUTABLE_PATH;
    if (override) {
        if (isExecutable(override)) {
            if (elfArchMatches(override) === false)
                warnOnce('PUPPETEER_EXECUTABLE_PATH (' + override + ') is not a ' + process.arch +
                    ' binary; honoring it anyway as an explicit override.');
            _cachedExe = override;
            return _cachedExe;
        }
        warnOnce('PUPPETEER_EXECUTABLE_PATH (' + override + ') is missing or not executable; auto-detecting instead.');
    }

    // 2. Arch-validated cache binaries.
    for (const p of cacheCandidates()) {
        if (!isExecutable(p)) continue;
        if (elfArchMatches(p) === false) continue; // wrong arch -> would crash; skip
        _cachedExe = p;
        return _cachedExe;
    }

    // 3. System installs (no arch validation, but skip snap shims — they don't
    //    launch from a non-snap process).
    for (const p of SYSTEM_CANDIDATES) {
        if (!isExecutable(p)) continue;
        if (looksLikeSnapWrapper(p)) {
            warnOnce(p + ' is a snap wrapper (unusable from a non-snap process); skipping.');
            continue;
        }
        _cachedExe = p;
        return _cachedExe;
    }

    _cachedExe = null;
    return _cachedExe;
}

// Whether Chromium needs --no-sandbox on this host.
function needsNoSandbox() {
    if (isSet('ODR_CHROME_NO_SANDBOX')) return isTruthy(process.env.ODR_CHROME_NO_SANDBOX); // explicit override
    if (process.platform !== 'linux') return false;
    if (typeof process.getuid === 'function' && process.getuid() === 0) return true; // root can't use the sandbox
    if (readProc('/proc/sys/kernel/apparmor_restrict_unprivileged_userns') === '1') return true; // Ubuntu 23.10+/24.04
    if (readProc('/proc/sys/kernel/unprivileged_userns_clone') === '0') return true; // userns disabled
    return false;
}

// Whether to accept invalid/self-signed TLS certs. Off by default (production
// stays strict); opt in per-box via .env / env var.
function acceptInsecureCerts() {
    if (isSet('ODR_CHROME_IGNORE_CERT')) return isTruthy(process.env.ODR_CHROME_IGNORE_CERT);
    if (isSet('STATIC_RENDER_IGNORE_HTTPS_ERRORS')) return isTruthy(process.env.STATIC_RENDER_IGNORE_HTTPS_ERRORS); // legacy name
    return false;
}

/**
 * Build a Puppeteer launch-options object with the host-appropriate Chromium,
 * sandbox flags, and TLS settings resolved automatically.
 *
 * @param {object} [overrides] Merged over the computed defaults. `overrides.args`
 *                             is appended to (not replaced) the auto flags.
 */
function getLaunchOptions(overrides) {
    overrides = overrides || {};

    const exe = detectExecutable();
    if (!exe) {
        warnOnce(
            'No architecture-matching Chromium found for ' + process.arch + '/' + process.platform + '. ' +
            "Puppeteer's bundled Chrome is x86-only and will fail on arm64.\n" +
            '  Install a matching build:  cd background_services && npx playwright install chromium\n' +
            '  (or point PUPPETEER_EXECUTABLE_PATH at a ' + process.arch + ' Chrome binary).\n' +
            '  Falling back to the bundled binary for now.'
        );
    }

    const args = [];
    if (needsNoSandbox()) args.push('--no-sandbox', '--disable-setuid-sandbox');

    const insecure = acceptInsecureCerts();
    if (insecure) args.push('--ignore-certificate-errors');

    const opts = {
        headless: 'new',
        acceptInsecureCerts: insecure,
        ignoreHTTPSErrors: insecure, // covers Puppeteer's network layer too (older option name)
        ...overrides,
        args: args.concat(overrides.args || []),
    };
    if (exe && !opts.executablePath) opts.executablePath = exe;
    return opts;
}

/**
 * Convenience wrapper: launch the caller's Puppeteer with the resolved options.
 * @param {import('puppeteer')} puppeteer The puppeteer module the caller required.
 * @param {object} [overrides]
 */
async function launch(puppeteer, overrides) {
    const opts = getLaunchOptions(overrides);
    if (opts.executablePath)
        console.log('[chromium_launcher] Using Chromium: ' + opts.executablePath +
            (opts.args.includes('--no-sandbox') ? ' (--no-sandbox)' : ''));
    else
        console.log('[chromium_launcher] Using Puppeteer-bundled Chromium.');
    return puppeteer.launch(opts);
}

module.exports = {
    launch,
    getLaunchOptions,
    detectExecutable,
    needsNoSandbox,
    acceptInsecureCerts,
};
