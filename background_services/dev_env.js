'use strict';

/*
 * Shared dev-environment bootstrap for the background_services workers.
 * Require this FIRST in any worker:  require('./dev_env');
 *
 *   1. Loads background_services/.env into process.env (non-overriding), so
 *      per-box settings live in one git-ignored file (with a committed
 *      .env.example) instead of your shell / ~/.bashrc.
 *
 *   2. If TLS leniency is opted into — ODR_CHROME_IGNORE_CERT=1 (or the legacy
 *      STATIC_RENDER_IGNORE_HTTPS_ERRORS=1) — relaxes Node's OWN TLS trust
 *      (NODE_TLS_REJECT_UNAUTHORIZED=0) so direct https/fetch calls to a
 *      self-signed / staging dev host succeed. This is the layer the browser's
 *      acceptInsecureCerts flag never reaches (it only covers Chromium
 *      navigations). Production (flag unset) keeps full TLS validation.
 *
 * chromium_launcher.js requires this module, so Puppeteer workers get both
 * behaviors from the single flag too; Node-only workers require it directly.
 */

const fs = require('fs');
const path = require('path');

function isSet(n) {
    return Object.prototype.hasOwnProperty.call(process.env, n) && process.env[n] !== '';
}
function isTruthy(v) {
    return v !== undefined && v !== null && v !== '' && v !== '0' && String(v).toLowerCase() !== 'false';
}

// --- 1. Load .env (never overrides a value already in the environment) ---
(function loadLocalEnv() {
    let txt;
    try { txt = fs.readFileSync(path.join(__dirname, '.env'), 'utf8'); }
    catch (e) { return; }
    for (const raw of txt.split('\n')) {
        const line = raw.trim();
        if (!line || line[0] === '#') continue;
        const eq = line.indexOf('=');
        if (eq < 1) continue;
        const key = line.slice(0, eq).trim();
        if (!/^[A-Za-z_][A-Za-z0-9_]*$/.test(key)) continue;
        if (Object.prototype.hasOwnProperty.call(process.env, key)) continue;
        let val = line.slice(eq + 1).trim();
        if ((val.startsWith('"') && val.endsWith('"')) ||
            (val.startsWith("'") && val.endsWith("'")))
            val = val.slice(1, -1);
        process.env[key] = val;
    }
})();

// Whether invalid/self-signed TLS certs should be accepted on this box.
function acceptInsecureCerts() {
    if (isSet('ODR_CHROME_IGNORE_CERT')) return isTruthy(process.env.ODR_CHROME_IGNORE_CERT);
    if (isSet('STATIC_RENDER_IGNORE_HTTPS_ERRORS')) return isTruthy(process.env.STATIC_RENDER_IGNORE_HTTPS_ERRORS);
    return false;
}

// --- 2. Relax Node's TLS trust if opted in ---
let _relaxed = false;
if (acceptInsecureCerts() && process.env.NODE_TLS_REJECT_UNAUTHORIZED !== '0') {
    process.env.NODE_TLS_REJECT_UNAUTHORIZED = '0';
    _relaxed = true;
    console.warn('[dev_env] Accepting self-signed/invalid TLS certs for this process ' +
        '(NODE_TLS_REJECT_UNAUTHORIZED=0). DEV ONLY — never set the flag on production.');
}

module.exports = { acceptInsecureCerts, tlsRelaxed: _relaxed };
