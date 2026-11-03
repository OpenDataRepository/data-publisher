# Ban System and Gate

Protects the sites on an ODR server from automated traffic in two layers:

- **The gate** (Apache). Every visitor must solve a reCAPTCHA before any page is served.
  Exceptions: API routes, approved search crawlers, and the server itself. One setup serves every
  site on the server, and each site shows its own branded gate page.
- **Bans** (fail2ban and nftables). Clients that misbehave are dropped at the kernel firewall,
  so their requests never reach Apache at all.

- [How it works](#how-it-works)
- [What happens to a request](#what-happens-to-a-request)
- [What gets banned](#what-gets-banned)
- [Log values](#log-values)
- [Installation](#installation)
- [Day-to-day operation](#day-to-day-operation)
- [Changing the rules](#changing-the-rules)
- [Troubleshooting](#troubleshooting)
- [Removing it](#removing-it)
- [Files](#files)

---

## How it works

```
 request ──► nftables firewall: banned address? ──yes──► dropped (no TLS, no Apache)
                  │ no
                  ▼
 Apache, the site's "Use OdrGate" line (checked in this order):
   1. localhost / this server                  ──► straight through        gate=trusted
   2. banned bot (user agent), any site        ──► 410                     gate=banned-bot
      the site's own rule set (rruff: the Bustamite flood, junk urls) ──► 410 / 404
   3. /api/v<n>, /<word>/api/v<n>              ──► straight through        gate=api
      approved crawler (Google, Bing, DDG)     ──► straight through        gate=crawler
   4. valid pass cookie for this site          ──► straight through        gate=passed
   5. anything else                            ──► the site's gate page    gate=challenge
                  │
                  ▼
 WordPress / ODR  (refuse any remaining junk urls themselves: gate=minimal_404)
                  │
                  ▼
 odr-ban-monitor.log: one line per gate event, from every site (sites' own logs unchanged)
                  │
                  ▼
 fail2ban: reads each new line, counts per address, bans ──► nftables
```

**One gate, many sites.**
- **`odr-gate.conf`** is enabled once for the whole server. It defines the gate as an Apache
  **macro**.
- **Each site's vhost** switches the gate on with one line, naming the site's ODR instance and
  its rule set:
  ```apache
  Use OdrGate /home/rruff/data-publisher rruff
  ```
- **Shared by every site:** the reCAPTCHA keys, the pass length and the secret, all in one
  `gate.ini`; the free-pass and crawler lists; and the bans.
- **Per site:** the gate page's look, in that instance's `app/config/gate_branding.ini`, and the
  rule set.

**The gate page.** A visitor without a valid pass cookie is handed, by internal rewrite, to
`challenge.php`, a small standalone PHP script that doesn't load WordPress or ODR. It shows the
reCAPTCHA in the site's branding. When the visitor solves it:

1. The script asks Google to confirm the answer.
2. It sets the `odr_pass` cookie.
3. It sends the visitor back to the page they asked for.

The cookie contains its expiry and a signature made from the expiry, the visitor's address, the
site's hostname and a server secret. Apache checks the signature on every request. That means:

- a pass only works on the site that issued it, from the address that solved it;
- it can't be forged or extended without the secret;
- it expires after `pass_hours` (96 hours by default).

**Logos.** Everything on a site is behind its gate, so a logo at `/wp-content/uploads/…` would
show as broken on the gate page. The gate therefore serves each site's logo itself, at
`/odr-gate/challenge.php?logo`, which isn't gated:
- **`logo_url`** — any url, including one on the site itself. `odr-gate refresh-logos`
  downloads it into that instance's `var/gate/`. It runs at install, at add-site and daily from
  cron, and connects through 127.0.0.1 for the server's own hostnames. The gate page only ever
  *reads* that copy, and never downloads anything while a visitor waits.
- **`logo_file`** — an image you copied into `app/config/gate/`.

**Approved crawlers** are recognised by address, from the ranges Google, Bing and DuckDuckGo
publish, plus the addresses of our Site24x7 uptime monitor, **never by user agent**. ODR downloads those ranges, and a root script turns them into
Apache config.

**The bans.** fail2ban is a background service that follows **`/var/log/apache2/odr-ban-monitor.log`**,
a shared log the gate writes its events to from every site. Each site keeps its own access log,
in whatever format and name it already uses; fail2ban doesn't read those.

1. It reads each new line and tests it against its **filters**, which are regular expressions
   matching the `gate=…` value at the end of the line.
2. For each **jail** (a filter plus its limits), it counts the recent matches per address.
3. When an address reaches the jail's limit, fail2ban adds it to a firewall set. The kernel then
   drops its packets to ports 80 and 443 until the ban expires.

Bans are **server-wide**: an address banned on one site is blocked from all of them. They're
saved in fail2ban's database and survive restarts. Banned traffic never reaches Apache, so it
isn't in the access logs. Bans are recorded in `/var/log/fail2ban.log`.

**Banned bots' published addresses.** The ODR command `odr:banned_bot_ips:update` downloads them
(currently Amazonbot's), and a root script hands them to fail2ban.

## What happens to a request

| Who | Result |
| --- | --- |
| This server or localhost (the static render daemon, WordPress cron and loopback) | Everything allowed. No rule applies. |
| A visitor who solved this site's reCAPTCHA recently, from the same address | Allowed. Junk urls still get a 404, and banned bots a 410. |
| A logged-in WordPress or ODR user | The same as any visitor: they solve the reCAPTCHA once per pass. |
| Approved crawler (Google, Bing, DuckDuckGo, and the Site24x7 monitor) | Allowed, with no reCAPTCHA. Junk urls get the 404 / 410, logged `approved_404` / `approved_410`, and are **never banned** for it. |
| API route (`/api/v1/…`, `/odr_rruff/api/v4/…`) | Allowed. Queries need an API token anyway. |
| Anyone else | The site's gate page (403), for every url, until they solve it. |

The WordPress theme's and ODR's own search gates accept the Apache gate's decision, so nobody
solves two reCAPTCHAs.

## What gets banned

| Jail | Trigger | Ban |
| --- | --- | --- |
| `odr-410` | any 410: banned bots by user agent, the `Bustamite?sample_child_id=` flood | immediately, 48 hours |
| `odr-minimal404` | 10 × `gate=minimal_404` within 24 hours | 48 hours |
| `odr-challenge` | 10 × `gate=challenge` within 24 hours: ten page requests without solving the reCAPTCHA | 48 hours |
| `odr-bots` | on a banned bot's published address list (Amazonbot) | 3 days, re-applied every 6 hours |

- Bans cover ports 80 and 443 only, so a mistaken ban can't lock anyone out of ssh.
- Addresses in fail2ban's `odr_ignoreip` (the server, the office) are never banned.
- `approved_404`, `approved_410`, `challenge-google-unreachable`, `gate-logo` and ordinary 404s
  (a missing image on a real page) are never counted.

## Log values

Each line of `odr-ban-monitor.log` ends in `gate=<value> site=<site>`. The monitor log holds the
values marked **●**; the others are only sent as the `X-ODR-Gate` response header, so a site can
add them to its own log with `%{X-ODR-Gate}o` if wanted.

| Value | Meaning |
| --- | --- |
| `trusted` | from this server or localhost: allowed without checks |
| `api` | an API route: allowed |
| `crawler` | an approved crawler: allowed |
| `passed` | a visitor with a valid pass cookie: allowed |
| `challenge` ● | shown the gate page (403). **Counted by fail2ban** (10 in 24 h bans). |
| `solved` ● | solved it: pass cookie set, sent back to their page (303) |
| `challenge-google-unreachable` ● | Google couldn't be asked, so the challenge was shown again. Not counted. |
| `gate-logo` | the gate page's logo |
| `gate-misconfigured` ● | the gate couldn't read its settings (503). See Troubleshooting. |
| `minimal_404` ● | a url no real page can match (404). **Counted by fail2ban** (10 in 24 h bans). |
| `approved_404` ● | the same, for an approved crawler. Not counted. |
| `attack` / `banned-bot` ● | the Bustamite flood / a banned bot's user agent (410). **Banned at once.** |
| `approved_410` ● | the same, for an approved crawler. Not counted. |
| `-` | none of the above: answered before the gate (e.g. a 301 to https), or untagged |

WordPress adds its own values on the RRUFF search routes (`logged-in`, `passed-earlier` and so on),
described in the theme's `odr_search_gate.php`.

---

## Installation

Everything is done by one tool, **`odr-gate`**, in the ODR repository at
`./docs/server/bot-bans/bin/odr-gate`. Run it **as root**, from the ODR checkout the other
instances are linked to. It has three commands:

| Command | What it does |
| --- | --- |
| `odr-gate verify` | Checks every part of the setup and lists what's wrong, with the fix for each. **Changes nothing.** Run it any time. |
| `odr-gate install` | Sets up, or repairs, everything server-wide. Safe to re-run. |
| `odr-gate add-site …` | Turns the gate on for one site. |
| `odr-gate refresh-logos` | Downloads each site's `logo_url` for its gate page. Also runs daily from cron. |

`install` and `add-site` back up every file they change to `/var/backups/odr-gate/<date-time>/`.
`--dry-run` shows what they would do without doing it.

### Before you start

- **The ODR code** is up to date in the main instance (`git pull`, as its user).
- **WordPress sites** run theme **odr-astra-child-theme 1.2.21 or later**.
- **On a new server, you have three secrets to hand.** On a server that already has the gate,
  `install` shows the current ones and asks whether to keep them, so you only need these if you
  want to change them:
  - **the reCAPTCHA site key and secret key**, a v2 "I'm not a robot" pair with every hostname of
    every site registered on it;
  - **the cookie secret** that signs visitors' passes. To keep everyone signed in, use the current
    one: `grep ODR_GATE_SECRET /etc/apache2/odr-gate-local.conf`. For a new server, or to sign
    everyone out, make one with `openssl rand -hex 32`.

### 1. Install

```bash
cd /home/odr/data-publisher
./docs/server/bot-bans/bin/odr-gate install
```

It asks **all its questions first**:
1. the three secrets. If this server's install already has them, it shows them (the two secret
   ones masked) and asks whether to keep them; otherwise you type them, and the secret ones aren't
   shown as you type;
2. this server's public address(es) and the office / VPN addresses that must never be banned,
   suggesting what it detects or already has (press Enter to accept);
3. whether to install fail2ban, if it isn't installed.

Then it shows a summary, with the secrets masked, and **changes nothing until you answer `yes`**.

To run it without questions, pass the answers instead:
`--yes --recaptcha-site-key … --recaptcha-secret … --pass-secret … --office-ips "216.220.243.246"`.

**What it does**, in order. Each step is skipped if it's already right:
1. enables the Apache modules (`macro rewrite headers setenvif alias env`);
2. installs and enables `odr-gate.conf` and the RRUFF rule set;
3. writes `/etc/apache2/odr-gate-local.conf` (secret, free pass), converting the old format if
   it finds it;
4. writes the shared `app/config/gate.ini` (keys, secret, pass length);
5. downloads the crawler and banned-bot lists, and installs the two root scripts;
6. installs fail2ban if needed, writes its filters and jails, and restarts it;
7. loads the lists into Apache and fail2ban;
8. writes the schedule, `/etc/cron.d/odr-gate`, and removes the old jobs from root's and the
   ODR user's own crontabs;
9. checks Apache's configuration, then reloads Apache;
10. downloads each site's `logo_url` (`refresh-logos`).

### 2. Add each site

```bash
./docs/server/bot-bans/bin/odr-gate add-site www.rruff.net --instance /home/rruff/data-publisher --rules rruff
./docs/server/bot-bans/bin/odr-gate add-site odr.io --instance /home/odr/data-publisher --rules none --site-name "Open Data Repository"
```

- **The hostname** is any `ServerName` or `ServerAlias` of the site. Every vhost that has it,
  :443 and :80, gets the gate.
- **`--instance`** is the site's ODR instance. Its branding is read from there.
- **`--rules`**: `rruff` for the RRUFF site, `none` for every other site.

In each of those vhosts it:
- adds `Use OdrGate …`, replacing any old `Include …/odr-gate.conf` lines and removing the
  hand-added Bustamite rules from earlier instructions;
- leaves the site's own `CustomLog` alone;
- warns about anything it can't safely judge, such as an `ErrorDocument 404` that might point
  into WordPress.

If Apache's configuration check fails, it puts the vhost files back. It also creates the site's
branding file, if there isn't one yet.

### 3. Brand each site

Edit `<instance>/app/config/gate_branding.ini`. Every setting is optional, and changes show up
immediately:

| Setting | |
| --- | --- |
| `site_name` | page title, and the heading when there's no logo |
| `logo_url` | the logo, by url, even one on this site (`https://www.rruff.net/wp-content/uploads/…/logo.png`). After changing it, run `odr-gate refresh-logos` to download it now; otherwise the daily cron job does. Until then the page shows the site name. |
| `logo_file` | or: an image copied into `<instance>/app/config/gate/` (e.g. `logo.svg`). Wins over `logo_url`. |
| `logo_alt`, `logo_height` | the logo's alt text and height in pixels |
| `accent_color`, `background_color`, `text_color` | `#rgb` or `#rrggbb` |
| `message` | the text under "Confirm you are human" |
| `footer`, `footer_link` | a line at the bottom, optionally a link |

### 4. Verify

```bash
./docs/server/bot-bans/bin/odr-gate verify
```

It ends with `OK` or `NOT READY` and lists every problem with its fix. Usually the fix is to
re-run `install` or `add-site`. Warnings (`!`) are things to look at, not blockers.

Then open each site from a phone on cellular, not the office network. You should see its
branded gate page, and after solving it you should be able to browse normally.

### Upgrading

Pull the new code and run `odr-gate install` again, then `odr-gate verify`. That's all. The
installer converts anything from an earlier version it recognises: the single-site settings
file, old filters and jails, crontab entries, and old `Include` lines (via `add-site`). Have the
reCAPTCHA keys ready; it asks for them. When the pass format changes, as it did for the multi-site gate, everyone
solves the reCAPTCHA once more.

---

## Day-to-day operation

| Task | Command |
| --- | --- |
| Gate events, all sites | `grep -o 'gate=[a-z0-9_-]*' /var/log/apache2/odr-ban-monitor.log \| sort \| uniq -c \| sort -rn` |
| Gate events, one site | `grep 'site=www.rruff.net' /var/log/apache2/odr-ban-monitor.log \| tail -20` |
| Ban overview | `fail2ban-client status` |
| One jail: counts and banned addresses | `fail2ban-client status odr-challenge` |
| Is an address banned, and by which jail? | `fail2ban-client banned 1.2.3.4` |
| Unban an address | `fail2ban-client unban 1.2.3.4` |
| Ban an address by hand (48 h) | `fail2ban-client set odr-410 banip 1.2.3.4` |
| Recent bans | `grep -E ' (Ban\|Unban) ' /var/log/fail2ban.log \| tail -20` |

**Sign everyone out of every site.** Run `odr-gate install` and give it a new cookie secret
(`openssl rand -hex 32`). It writes the secret to both places it's needed and reloads Apache.

**Turn the gate off for one site in an emergency.** Comment out its `Use OdrGate` line(s), then
run `apachectl configtest && systemctl reload apache2`. That site goes back to how it was before
the gate. Its rule set (the RRUFF 404 and 410 rules) goes too.

## Changing the rules

| Change | Where | Then |
| --- | --- | --- |
| A site's gate page | `app/config/gate_branding.ini` in its instance | immediately |
| How long a solved reCAPTCHA lasts | `pass_hours` in the main instance's `app/config/gate.ini` (default 96, minimum 1) | passes solved from then on |
| Free-pass addresses | `/etc/apache2/odr-gate-local.conf` | `systemctl reload apache2` |
| Never-ban addresses | `odr_ignoreip` in `/etc/fail2ban/jail.d/odr.local` | `fail2ban-client reload` |
| Ban limits (`maxretry`, `findtime`, `bantime`) | `/etc/fail2ban/jail.d/odr.local` | `fail2ban-client reload` |
| Ban a bot by user agent, on every site | the `(Amazonbot)` list in `/etc/apache2/conf-available/odr-gate.conf`, e.g. `(Amazonbot\|Bytespider)` | `systemctl reload apache2` |
| Ban a bot by its published addresses | `SOURCES` in `src/ODR/AdminBundle/Command/UpdateBannedBotIpsCommand.php` | next cron run |
| A new rule set for a site | a new `/etc/apache2/conf-available/odr-gate-rules-<name>.conf`, then `Use OdrGate <instance> <name>` | `systemctl reload apache2` |

Copy changes to the Apache and fail2ban files back into `docs/server/bot-bans/` in the repo, so
the next server gets them too.

A bot's published-address source must publish `"ipv4Prefix"` / `"ipv6Prefix"` entries. The
command deliberately never takes "any address-looking text" from a page: Amazon's page also
contains the *visitor's* address, which would ban the server that ran the update.

## Troubleshooting

**Every page shows the gate page again, even after solving it.** The pass cookie isn't being
accepted. Check:
- Do the secrets match? Compare `ODR_GATE_SECRET` in `/etc/apache2/odr-gate-local.conf` with
  `pass_secret` in the main instance's `app/config/gate.ini`.
- Is the browser keeping the cookie? Look for `odr_pass` in its cookies for the site.
- Did the visitor's address change? Phones moving between networks do, and the pass is per
  address.

**"Temporarily unavailable" (503, `gate=gate-misconfigured`).** The gate can't read the shared
`gate.ini`, or a value is missing. Check `ODR_GATE_CONFIG` in `/etc/apache2/odr-gate-local.conf`
points at it, that it's group `www-data` and mode 640, and look in Apache's error log for
`odr-gate:`.

**The gate page shows no logo.**
- `logo_file`: the file must be in the instance's `app/config/gate/`.
- `logo_url`: run `odr-gate refresh-logos`. It downloads the logo, or says why it can't (an
  address that doesn't answer, not an image, over 1 MB).

**The reCAPTCHA box says "Invalid domain for site key".** Register the hostname on the key in the
reCAPTCHA admin console.

**Nobody is ever banned.** Start with `odr-gate verify`, which checks all of this. By hand:
1. Is the gate writing the monitor log? `tail /var/log/apache2/odr-ban-monitor.log` should show
   recent `gate=challenge` lines. If it's empty, check that the sites have their `Use OdrGate`
   line and that `odr-gate.conf` is enabled.
2. Is fail2ban reading it? `fail2ban-client status odr-challenge` should list
   `/var/log/apache2/odr-ban-monitor.log` under **File list**.

**Google is being challenged** (Googlebot requests logged `gate=challenge`). The crawler ranges
aren't loaded. Run `odr-gate verify`, then `odr-gate install --refresh`, which downloads the ranges
again and reloads them.

**The static render daemon or WordPress cron stopped working.** Their requests are being
challenged: look for `gate=challenge` from the server's own address. Run
`odr-gate install --server-ips "<all of this server's addresses>"`.

**API calls get the gate page.** The route must look like `/api/v<n>…` or `/<prefix>/api/v<n>…`,
where the prefix is letters, digits, `-` or `_`.

**A real person or the office got banned.**
1. `fail2ban-client banned <address>` shows which jail banned it.
2. `fail2ban-client unban <address>` lifts the ban.
3. Add the address to `odr_ignoreip`, then run `fail2ban-client reload`.

**`apachectl configtest` fails.** It names the file and line. If the problem is
`odr-gate-crawlers.conf`, re-run `odr-gate-update-crawlers`. It restores the previous file
whenever the new one fails the check.

## Removing it

1. Delete every `Use OdrGate` line from the vhosts.
2. Then:

```bash
a2disconf odr-gate
apachectl configtest && systemctl reload apache2
systemctl disable --now fail2ban
rm /etc/fail2ban/jail.d/odr.local /etc/fail2ban/fail2ban.d/odr.local /etc/fail2ban/filter.d/odr-*.conf
rm /etc/apache2/odr-gate-local.conf /etc/apache2/odr-gate-crawlers.conf
rm /etc/apache2/conf-available/odr-gate.conf /etc/apache2/conf-available/odr-gate-rules-*.conf
rm /usr/local/sbin/odr-ban-bot-ips /usr/local/sbin/odr-gate-update-crawlers
rm /etc/cron.d/odr-gate
```

The `gate.ini` and `gate_branding.ini` files in the instances can stay or go.

---

## Files

| In the repo | Installed to | Purpose |
| --- | --- | --- |
| `docs/server/bot-bans/bin/odr-gate` | (run from the repo, as root) | `verify`, `install`, `add-site`: everything below is put in place by it |
| `docs/server/bot-bans/apache/odr-gate.conf` | `/etc/apache2/conf-available/`, `a2enconf` | the gate, as the `OdrGate` macro, plus the server-wide lists |
| `docs/server/bot-bans/apache/odr-gate-rules-rruff.conf` | `/etc/apache2/conf-available/` (not enabled) | the RRUFF site's extra rules: the Bustamite flood, junk-url 404s |
| `docs/server/bot-bans/apache/odr-gate-local.conf.example` | `/etc/apache2/odr-gate-local.conf` (root, 600) | the secret, the main instance, the free-pass addresses |
| `docs/server/bot-bans/gate/challenge.php` | (served from the shared code) | the gate page and logo; sets the pass cookie |
| `app/config/gate.ini.dist` | the main instance's `app/config/gate.ini` (640, group www-data) | reCAPTCHA keys, secret, pass length, for every site |
| `app/config/gate_branding.ini.dist` | each site's instance `app/config/gate_branding.ini` | that site's gate page |
| `docs/server/bot-bans/bin/odr-gate-update-crawlers` | `/usr/local/sbin/` | crawler list → `/etc/apache2/odr-gate-crawlers.conf` |
| `docs/server/bot-bans/bin/odr-ban-bot-ips` | `/usr/local/sbin/` | banned-bot list → fail2ban `odr-bots` |
| `docs/server/bot-bans/fail2ban/filter.d/odr-*.conf` | `/etc/fail2ban/filter.d/` | what each jail counts |
| `docs/server/bot-bans/fail2ban/jail.d/odr.local` | `/etc/fail2ban/jail.d/` | the jails and their limits |
| `docs/server/bot-bans/fail2ban/fail2ban.d/odr.local` | `/etc/fail2ban/fail2ban.d/` | keeps ban records long enough to survive restarts |
| `src/ODR/AdminBundle/Command/UpdateCrawlerIpsCommand.php` | (ODR code) | `odr:crawler_ips:update` → `var/crawler_ips.json`, read by ODR, the theme and the gate |
| `src/ODR/AdminBundle/Command/UpdateBannedBotIpsCommand.php` | (ODR code) | `odr:banned_bot_ips:update` → `var/banned_bot_ips.txt` |
