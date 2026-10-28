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
- [Installation: once per server](#installation-once-per-server)
- [Adding a site](#adding-a-site)
- [Upgrading from the single-site gate](#upgrading-from-the-single-site-gate)
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
 access log, every line ending in gate=<value>
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
- **`logo_url`** — any url, including one on the site itself. The gate downloads it (the
  server has a free pass), keeps a copy in that instance's `var/gate/`, and refreshes it daily.
- **`logo_file`** — an image you copied into `app/config/gate/`.

**Approved crawlers** are recognised by address, from the ranges Google, Bing and DuckDuckGo
publish, **never by user agent**. ODR downloads those ranges, and a root script turns them into
Apache config.

**The bans.** fail2ban is a background service that follows every site's access log:

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
| Approved crawler (Google, Bing, DuckDuckGo) | Allowed, with no reCAPTCHA. Junk urls get the 404 / 410, logged `approved_404` / `approved_410`, and are **never banned** for it. |
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

The last field of each access-log line, `gate=…`:

| Value | Meaning |
| --- | --- |
| `trusted` | from this server or localhost: allowed without checks |
| `api` | an API route: allowed |
| `crawler` | an approved crawler: allowed |
| `passed` | a visitor with a valid pass cookie: allowed |
| `challenge` | shown the gate page (403). **Counted by fail2ban** (10 in 24 h bans). |
| `solved` | solved it: pass cookie set, sent back to their page (303) |
| `challenge-google-unreachable` | Google couldn't be asked, so the challenge was shown again. Not counted. |
| `gate-logo` | the gate page's logo |
| `gate-misconfigured` | the gate couldn't read its settings (503). See Troubleshooting. |
| `minimal_404` | a url no real page can match (404). **Counted by fail2ban** (10 in 24 h bans). |
| `approved_404` | the same, for an approved crawler. Not counted. |
| `attack` / `banned-bot` | the Bustamite flood / a banned bot's user agent (410). **Banned at once.** |
| `approved_410` | the same, for an approved crawler. Not counted. |
| `-` | none of the above: answered before the gate (e.g. a 301 to https), or untagged |

WordPress adds its own values on the RRUFF search routes (`logged-in`, `passed-earlier` and so on),
described in the theme's `odr_search_gate.php`.

---

## Installation: once per server

Do this **as root**, in order. Each step ends with a check: stop there if it fails. Then add each
site with [Adding a site](#adding-a-site).

### 0. Before you start

| Requirement | How to check |
| --- | --- |
| Ubuntu 24.04, Apache 2.4 with PHP | `apache2 -v`, `ls /etc/apache2/mods-enabled/ \| grep php` |
| nftables, Python 3 | `nft --version`, `python3 --version` |
| WordPress sites: theme **odr-astra-child-theme 1.2.20 or later** | `grep ^Version <wordpress>/wp-content/themes/odr-astra-child-theme/style.css` |
| One reCAPTCHA **v2 "I'm not a robot"** key pair, with **every hostname of every site** registered | https://www.google.com/recaptcha/admin |

**Values used below.** Set these in your root shell first:

```bash
MAIN=/home/odr/data-publisher                 # the ODR instance the others are linked to
BB=$MAIN/docs/server/bot-bans                 # the ban-system files in the repo
MAIN_USER=$(stat -c %U $MAIN/var)             # the user that owns it
```

### 1. Deploy the code and generate the lists

```bash
cd $MAIN
sudo -u $MAIN_USER git pull
sudo -u $MAIN_USER php app/console cache:clear --env=prod
sudo -u $MAIN_USER php app/console odr:crawler_ips:update --env=prod
sudo -u $MAIN_USER php app/console odr:banned_bot_ips:update --env=prod
```

Clear the cache of every linked instance too, e.g. `php app/console-all cache:clear --env=prod`.

**Check:**

```bash
ls $BB/apache $BB/gate $BB/bin
#   odr-gate.conf  odr-gate-local.conf.example  odr-gate-rules-rruff.conf
#   challenge.php
#   odr-ban-bot-ips  odr-gate-update-crawlers
grep -vc '^#' $MAIN/var/crawler_ips.txt          # several hundred
grep -vc '^#' $MAIN/var/banned_bot_ips.txt       # a couple of thousand
```

### 2. Shared gate settings

The gate needs one secret, shared by Apache (which checks pass cookies) and the gate page (which
signs them):

```bash
SECRET=$(openssl rand -hex 32)
```

**2.1 Apache's settings,** `/etc/apache2/odr-gate-local.conf` (root only):

```bash
install -m 600 -o root -g root $BB/apache/odr-gate-local.conf.example /etc/apache2/odr-gate-local.conf
sed -i "s#REPLACE_WITH_THE_OUTPUT_OF_openssl_rand_-hex_32#$SECRET#" /etc/apache2/odr-gate-local.conf
sed -i "s#/home/odr/data-publisher#$MAIN#g" /etc/apache2/odr-gate-local.conf
```

Then edit it and set the **free-pass** line to this server's own public address(es). Keep the
localhost line.

```apache
SetEnvIfExpr "-R '144.217.146.145'" odr_trusted=1
```

Requests from these addresses skip the gate on every site. The static render daemon, WordPress's
loopback requests (wp-cron, site health) and the gate's own logo downloads come from here, and
**break if it's wrong**.

**2.2 The shared gate settings,** `$MAIN/app/config/gate.ini`, next to `parameters.yml` in the
main instance. Like `parameters.yml` it's never committed; the template is
`app/config/gate.ini.dist`.

```bash
install -m 640 -o $MAIN_USER -g www-data $MAIN/app/config/gate.ini.dist $MAIN/app/config/gate.ini
sed -i "0,/^pass_secret = \"REPLACE\"/s##pass_secret = \"$SECRET\"#" $MAIN/app/config/gate.ini
```

Then edit it and fill in `recaptcha_site_key` and `recaptcha_secret`. `pass_hours` (default 96)
is how long a solved reCAPTCHA lasts, on every site.

**Check:** the same secret is in both files, and the permissions are right.

```bash
grep -o '"[0-9a-f]\{64\}"' /etc/apache2/odr-gate-local.conf $MAIN/app/config/gate.ini   # same value twice
ls -l /etc/apache2/odr-gate-local.conf $MAIN/app/config/gate.ini   # -rw------- root root / -rw-r----- <user> www-data
```

### 3. Approved crawlers

```bash
install -m 755 $BB/bin/odr-gate-update-crawlers /usr/local/sbin/
odr-gate-update-crawlers $MAIN/var/crawler_ips.txt
#   /etc/apache2/odr-gate-crawlers.conf: 549 IPv4 + 22 IPv6 ranges (new file)
```

It validates the list, writes `/etc/apache2/odr-gate-crawlers.conf`, checks the Apache config,
and reloads Apache.

### 4. Apache, server-wide

```bash
a2enmod macro rewrite headers setenvif alias env
cp $BB/apache/odr-gate.conf $BB/apache/odr-gate-rules-rruff.conf /etc/apache2/conf-available/
a2enconf odr-gate
```

Don't `a2enconf` the rules file: the gate includes it for the sites that ask for it.

If `grep -rn combined_gate /etc/apache2/` finds no `LogFormat` line, add this one to
`/etc/apache2/apache2.conf`, next to the other `LogFormat` lines:

```apache
LogFormat "%h %l %u %t \"%r\" %>s %O \"%{Referer}i\" \"%{User-Agent}i\" gate=%{X-ODR-Gate}o" combined_gate
```

You can add fields *after* `gate=%{X-ODR-Gate}o` (for example ` body=%B`), but nothing before it.

**Check:**

```bash
apachectl configtest && systemctl reload apache2      # Syntax OK
```

Enabling `odr-gate.conf` changes nothing yet. It only defines the gate, and each site turns it on.

### 5. fail2ban

**5.1 Install and copy in the configuration:**

```bash
apt install fail2ban
cp $BB/fail2ban/filter.d/odr-*.conf  /etc/fail2ban/filter.d/
cp $BB/fail2ban/jail.d/odr.local     /etc/fail2ban/jail.d/
cp $BB/fail2ban/fail2ban.d/odr.local /etc/fail2ban/fail2ban.d/
```

**5.2 Edit `/etc/fail2ban/jail.d/odr.local`.** Set the two values at the top:

```ini
odr_ignoreip = 127.0.0.1/8 ::1 144.217.146.145 216.220.243.246
odr_logpath = /var/log/apache2/*-access.log
```

- **`odr_ignoreip`**: this server's address(es) plus every office and VPN address. Testing a gate
  page from the office produces challenges, and ten unsolved ones in 24 hours would ban you.
- **`odr_logpath`**: the access logs of every site using the gate. The wildcard covers the usual
  `<site>-access.log` / `<site>-ssl-access.log` names. Adjust it to match your `CustomLog`
  lines, or list the files one per line, indented.

**5.3 Check and start:**

```bash
fail2ban-client -t            # must end with: OK: configuration test is successful
systemctl enable fail2ban
systemctl restart fail2ban
fail2ban-client status        # Jail list: odr-410, odr-bots, odr-challenge, odr-minimal404, sshd
```

### 6. Banned bots' published addresses

```bash
install -m 755 $BB/bin/odr-ban-bot-ips /usr/local/sbin/
odr-ban-bot-ips $MAIN/var/banned_bot_ips.txt
fail2ban-client status odr-bots                       # about 2,300 currently banned
```

### 7. Schedule the updates

As the main instance's user (`crontab -u $MAIN_USER -e`):

```cron
0 */6 * * *  cd /home/odr/data-publisher && php app/console odr:banned_bot_ips:update --env=prod
30 3 * * 1   cd /home/odr/data-publisher && php app/console odr:crawler_ips:update --env=prod
```

As root (`crontab -e`), a little later:

```cron
10 */6 * * * /usr/local/sbin/odr-ban-bot-ips /home/odr/data-publisher/var/banned_bot_ips.txt
45 3 * * 1   /usr/local/sbin/odr-gate-update-crawlers /home/odr/data-publisher/var/crawler_ips.txt
```

---

## Adding a site

Repeat for each site, **as root**. A site is a vhost (or several, for its hostnames) served by one
ODR instance.

```bash
SITE=/home/rruff/data-publisher               # the site's ODR instance
SITE_USER=$(stat -c %U $SITE/var)
```

### 1. Its branding

```bash
install -m 644 -o $SITE_USER -g www-data $SITE/app/config/gate_branding.ini.dist $SITE/app/config/gate_branding.ini
```

Edit `$SITE/app/config/gate_branding.ini`. Every setting is optional:

| Setting | |
| --- | --- |
| `site_name` | page title, and the heading when there's no logo |
| `logo_url` | the logo, by url, even one on this site (`https://www.rruff.net/wp-content/uploads/…/logo.png`). The gate downloads it and serves its own copy. |
| `logo_file` | or: an image copied into `$SITE/app/config/gate/` (e.g. `logo.svg`). Wins over `logo_url`. |
| `logo_alt`, `logo_height` | the logo's alt text and height in pixels |
| `accent_color`, `background_color`, `text_color` | `#rgb` or `#rrggbb` |
| `message` | the text under "Confirm you are human" |
| `footer`, `footer_link` | a line at the bottom, optionally a link |

The page is built when it's shown, so changes appear immediately. A changed `logo_url` is
downloaded the next time the page is shown.

### 2. Its vhost(s)

In each `<VirtualHost>` that serves the site (the :443 one, and :80 too):

1. Make its `CustomLog` use `combined_gate`, with a filename the fail2ban wildcard matches:
   ```apache
   CustomLog ${APACHE_LOG_DIR}/rruff.net-ssl-access.log combined_gate
   ```
2. Add, before `</VirtualHost>`:
   ```apache
   Use OdrGate /home/rruff/data-publisher rruff
   ```
   The second word is the rule set: `rruff` for the RRUFF site, `none` for every other site.
3. Make sure the vhost has **no `ErrorDocument 404` or `ErrorDocument 410`** pointing into
   WordPress.

```bash
apachectl configtest && systemctl reload apache2
```

### 3. Check it

From the server (it has a free pass):

```bash
curl -s -o /dev/null -w '%{http_code}\n' https://www.rruff.net/       # 200, logged gate=trusted
```

From somewhere else (your own computer, or a phone on cellular):

```bash
curl -s -o /dev/null -w '%{http_code}\n' https://www.rruff.net/                          # 403 (the gate page)
curl -s -o /dev/null -w '%{http_code}\n' https://www.rruff.net/odr-gate/challenge.php?logo # 200 if a logo is set
curl -s -o /dev/null -w '%{http_code}\n' -A 'Amazonbot/0.1' https://www.rruff.net/       # 410
```

Then open the site in a browser. You should see its branded gate page. Solve it, and you should
land on the page you asked for and be able to browse normally. The access log shows
`gate=challenge`, then `gate=solved`, then `gate=passed`. Also check that the static render
daemon still works: its requests show `gate=trusted`.

Make sure every hostname the site answers on is registered on the reCAPTCHA key. Otherwise its
gate page shows "Invalid domain for site key".

---

## Upgrading from the single-site gate

If a server runs the earlier version (one `Include …/odr-gate.conf` per vhost):

1. Deploy the code (step 1).
2. Replace `/etc/apache2/odr-gate-local.conf` with the new format (step 2.1). It now names the
   main instance (`ODR_GATE_DIR`, `ODR_GATE_CONFIG`) rather than one site. Keep the same secret.
3. Move the shared settings: the RRUFF instance's `app/config/gate.ini` becomes
   `$MAIN/app/config/gate.ini` (step 2.2).
4. Do step 4 (`a2enmod macro`, copy both files, `a2enconf odr-gate`).
5. In each vhost, replace `Include /etc/apache2/conf-available/odr-gate.conf` with
   `Use OdrGate <instance> <rules>`.
6. Set `odr_logpath` in `/etc/fail2ban/jail.d/odr.local` to the wildcard, then run
   `fail2ban-client reload`.

The pass signature now covers the hostname, so existing passes stop working once. Everyone
solves the reCAPTCHA again.

---

## Day-to-day operation

| Task | Command |
| --- | --- |
| What a site is doing | `grep -o 'gate=[a-z0-9_-]*' <access log> \| sort \| uniq -c \| sort -rn` |
| Ban overview | `fail2ban-client status` |
| One jail: counts and banned addresses | `fail2ban-client status odr-challenge` |
| Is an address banned, and by which jail? | `fail2ban-client banned 1.2.3.4` |
| Unban an address | `fail2ban-client unban 1.2.3.4` |
| Ban an address by hand (48 h) | `fail2ban-client set odr-410 banip 1.2.3.4` |
| Recent bans | `grep -E ' (Ban\|Unban) ' /var/log/fail2ban.log \| tail -20` |

**Sign everyone out of every site.** Put a new secret in both `/etc/apache2/odr-gate-local.conf`
and the main instance's `app/config/gate.ini`, then `systemctl reload apache2`.

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
- `logo_url`: look in Apache's error log for `odr-gate: could not fetch the logo`. The
  instance's `var/` must be writable by the web server, so it can keep the copy in `var/gate/`.

**The reCAPTCHA box says "Invalid domain for site key".** Register the hostname on the key in the
reCAPTCHA admin console.

**Google is being challenged** (Googlebot requests logged `gate=challenge`). The crawler ranges
aren't loaded. Check `/etc/apache2/odr-gate-crawlers.conf` exists and isn't empty, then re-run
step 3.

**The static render daemon or WordPress cron stopped working.** Their requests are being
challenged: look for `gate=challenge` from the server's own address. Put the address on the
free-pass line (step 2.1) and reload Apache.

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
crontab -e                       # delete the two root lines
crontab -u <main user> -e        # delete the two ODR lines
```

The `gate.ini` and `gate_branding.ini` files in the instances can stay or go.

---

## Files

| In the repo | Installed to | Purpose |
| --- | --- | --- |
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
| `src/ODR/AdminBundle/Command/UpdateCrawlerIpsCommand.php` | (ODR code) | `odr:crawler_ips:update` → `var/crawler_ips.php` and `.txt` |
| `src/ODR/AdminBundle/Command/UpdateBannedBotIpsCommand.php` | (ODR code) | `odr:banned_bot_ips:update` → `var/banned_bot_ips.txt` |
