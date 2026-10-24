# Ban System and Gate

Protects the RRUFF site from automated traffic in two layers:

- **The gate** (Apache). Every visitor must solve a reCAPTCHA before any page is served.
  Exceptions: API routes, approved search crawlers, and the server itself.
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
 Apache, odr-gate.conf (checked in this order):
   1. localhost / this server                 ──► straight through          gate=trusted
   2. banned bot (user agent), Bustamite flood ──► 410                       gate=banned-bot / attack
   3. url no real page can match               ──► 404                       gate=minimal_404
   4. /api/v<n>, /<word>/api/v<n>              ──► straight through          gate=api
      approved crawler (Google, Bing, DDG)     ──► straight through          gate=crawler
   5. valid pass cookie                        ──► straight through          gate=passed
   6. anything else                            ──► reCAPTCHA (challenge.php) gate=challenge
                  │
                  ▼
 WordPress / ODR  (the theme refuses the remaining junk urls itself: gate=minimal_404)
                  │
                  ▼
 access log, every line ending in gate=<value>
                  │
                  ▼
 fail2ban: reads each new line, counts per address, bans ──► nftables
```

**The gate.** `odr-gate.conf` is a set of Apache rewrite rules, included in the site's vhost.
A visitor without a valid **pass cookie** is handed, by internal rewrite, to `challenge.php`, a
small standalone PHP script that doesn't load WordPress or ODR. It shows the reCAPTCHA. When the
visitor solves it:

1. The script asks Google to confirm the answer.
2. It sets the `odr_pass` cookie.
3. It sends the visitor back to the page they asked for.

The cookie contains its expiry time and a signature made from that expiry, the visitor's
address and a server secret. Apache checks the signature on every request. That means:

- a pass only works from the address that solved it;
- it can't be forged or extended without the secret;
- it expires after `pass_hours` (24 hours by default).

Approved crawlers are recognised by address, from the ranges Google, Bing and DuckDuckGo publish,
**never by user agent**. ODR downloads those ranges, and a root script turns them into Apache
config.

**The bans.** fail2ban is a background service that follows the Apache access log:

1. It reads each new line and tests it against its **filters**, which are regular expressions
   matching the `gate=…` value at the end of the line.
2. For each **jail** (a filter plus its limits), it counts the recent matches per address.
3. When an address reaches the jail's limit, fail2ban adds it to a firewall set. The kernel then
   drops its packets to ports 80 and 443 until the ban expires.

Bans are saved in fail2ban's database and survive restarts. Banned traffic never reaches Apache,
so it isn't in the access log. Bans are recorded in `/var/log/fail2ban.log`.

**Banned bots' published addresses.** The ODR command `odr:banned_bot_ips:update` downloads them
(currently Amazonbot's), and a root script hands them to fail2ban.

## What happens to a request

| Who | Result |
| --- | --- |
| This server or localhost (the static render daemon, WordPress cron and loopback) | Everything allowed. No rule applies. |
| A visitor who solved the reCAPTCHA recently, from the same address | Allowed. Junk urls still get a 404, and banned bots a 410. |
| A logged-in WordPress user | The same as any visitor: they solve the reCAPTCHA once per pass. |
| Approved crawler (Google, Bing, DuckDuckGo) | Allowed, with no reCAPTCHA. Junk urls get the 404 / 410, logged `approved_404` / `approved_410`, and are **never banned** for it. |
| API route (`/api/v1/…`, `/odr/api/v3/…`) | Allowed. Queries need an API token anyway. |
| Anyone else | The reCAPTCHA page (403), for every url, until they solve it. |

## What gets banned

| Jail | Trigger | Ban |
| --- | --- | --- |
| `odr-410` | any 410: banned bots by user agent, the `Bustamite?sample_child_id=` flood | immediately, 48 hours |
| `odr-minimal404` | 5 × `gate=minimal_404` within 1 hour | 48 hours |
| `odr-challenge` | 5 × `gate=challenge` within 1 hour: five page requests without solving the reCAPTCHA | 48 hours |
| `odr-bots` | on a banned bot's published address list (Amazonbot) | 3 days, re-applied every 6 hours |

- Bans cover ports 80 and 443 only, so a mistaken ban can't lock anyone out of ssh.
- Addresses in fail2ban's `odr_ignoreip` (the server, the office) are never banned.
- `approved_404`, `approved_410`, `challenge-google-unreachable` and ordinary 404s (a missing
  image on a real page) are never counted.

## Log values

The last field of each access-log line, `gate=…`:

| Value | Meaning |
| --- | --- |
| `trusted` | from this server or localhost: allowed without checks |
| `api` | an API route: allowed |
| `crawler` | an approved crawler: allowed |
| `passed` | a visitor with a valid pass cookie: allowed |
| `challenge` | shown the reCAPTCHA (403). **Counted by fail2ban.** |
| `solved` | solved it: pass cookie set, sent back to their page (303) |
| `challenge-google-unreachable` | Google couldn't be asked, so the challenge was shown again. Not counted. |
| `gate-misconfigured` | `challenge.php` couldn't read its settings (503). See Troubleshooting. |
| `minimal_404` | a url no real page can match (404). **Counted by fail2ban.** |
| `approved_404` | the same, for an approved crawler. Not counted. |
| `attack` / `banned-bot` | the Bustamite flood / a banned bot's user agent (410). **Banned at once.** |
| `approved_410` | the same, for an approved crawler. Not counted. |
| `-` | none of the above: a request answered before the gate (e.g. a 301 to https), or an untagged WordPress response |

WordPress adds its own values on the search routes (`logged-in`, `passed-earlier` and so on),
described in the theme's `odr_search_gate.php`.

---

## Installation

Do this on each server that serves the RRUFF site, **as root**, in order. Each step ends with a
check: stop there if it fails.

### 0. Before you start

| Requirement | How to check |
| --- | --- |
| Ubuntu 24.04, Apache 2.4 with PHP | `apache2 -v`, `ls /etc/apache2/mods-enabled/ \| grep php` |
| nftables, Python 3 | `nft --version`, `python3 --version` |
| WordPress theme **odr-astra-child-theme 1.2.20 or later** | `grep ^Version /home/rruff/wordpress/wp-content/themes/odr-astra-child-theme/style.css` |
| reCAPTCHA **v2 "I'm not a robot"** keys with **every hostname the site answers on** registered | https://www.google.com/recaptcha/admin |

**Values used below.** Set these in your root shell first, adjusting them to the server:

```bash
ODR_DIR=/home/rruff/data-publisher           # the ODR instance on this server
BB=$ODR_DIR/docs/server/bot-bans             # the ban-system files in the repo
ODR_USER=$(stat -c %U $ODR_DIR/var)          # the user that owns the instance
SERVER_IPS="144.217.146.145"                 # this server's public address(es)
OFFICE_IPS="216.220.243.246"                 # office / VPN addresses (never banned)
```

**Find the rruff vhost file and access log:**

```bash
grep -nE "ServerName|CustomLog|ErrorDocument" /etc/apache2/sites-enabled/*.conf
```

- Note the `<VirtualHost *:443>` for `www.rruff.net`: its file (the **vhost file**) and its
  `CustomLog` (the **access log**, e.g. `/var/log/apache2/rruff.net-ssl-access.log`).
- That vhost must have **no `ErrorDocument 404` or `ErrorDocument 410`** pointing into WordPress.
  With one, every refusal would start WordPress after all.

### 1. Deploy the code and generate the lists

```bash
cd $ODR_DIR
sudo -u $ODR_USER git pull
sudo -u $ODR_USER php app/console cache:clear --env=prod
sudo -u $ODR_USER php app/console odr:crawler_ips:update --env=prod
sudo -u $ODR_USER php app/console odr:banned_bot_ips:update --env=prod
```

**Check:**

```bash
ls $BB/apache $BB/gate $BB/bin
#   odr-gate.conf  odr-gate-local.conf.example
#   challenge.php
#   odr-ban-bot-ips  odr-gate-update-crawlers
grep -vc '^#' $ODR_DIR/var/crawler_ips.txt          # several hundred
grep -vc '^#' $ODR_DIR/var/banned_bot_ips.txt       # a couple of thousand
```

### 2. Gate settings

The gate needs one secret, shared by Apache (which checks pass cookies) and `challenge.php`
(which signs them). Generate it:

```bash
SECRET=$(openssl rand -hex 32)
```

**2.1 Apache's settings file:**

```bash
install -m 600 -o root -g root $BB/apache/odr-gate-local.conf.example /etc/apache2/odr-gate-local.conf
sed -i "s#REPLACE_WITH_THE_OUTPUT_OF_openssl_rand_-hex_32#$SECRET#" /etc/apache2/odr-gate-local.conf
sed -i "s#/home/rruff/data-publisher#$ODR_DIR#" /etc/apache2/odr-gate-local.conf
```

The second `sed` points it at this server's ODR instance (`ODR_DIR`). The challenge script is
served from that checkout, and reads its settings from that instance's `app/config/gate.ini`.

Then edit `/etc/apache2/odr-gate-local.conf` and set the **free-pass** line to this server's own
address(es). Keep the localhost line as it is.

```apache
SetEnvIfExpr "-R '144.217.146.145'" odr_trusted=1
```

Requests from these addresses skip every rule. The static render daemon and WordPress's loopback
requests (wp-cron, site health) come from here, and **break if it's wrong**. If the static
renderer runs on a different machine, add that machine's address too.

**2.2 The challenge script's settings,** in the ODR instance's `app/config`, next to
`parameters.yml`. Like `parameters.yml` it's per instance and never committed. The committed
template is `app/config/gate.ini.dist`.

```bash
install -m 640 -o $ODR_USER -g www-data $ODR_DIR/app/config/gate.ini.dist $ODR_DIR/app/config/gate.ini
sed -i "0,/^pass_secret = \"REPLACE\"/s##pass_secret = \"$SECRET\"#" $ODR_DIR/app/config/gate.ini
```

Then edit `$ODR_DIR/app/config/gate.ini` and fill in `recaptcha_site_key` and
`recaptcha_secret`. `pass_hours` (default 24) is how long a solved reCAPTCHA lasts.

**Check:** the same secret is in both files, and the permissions are right. The web server reads
`gate.ini`; only root reads the Apache file.

```bash
grep -o '"[0-9a-f]\{64\}"' /etc/apache2/odr-gate-local.conf $ODR_DIR/app/config/gate.ini   # same value twice
ls -l /etc/apache2/odr-gate-local.conf $ODR_DIR/app/config/gate.ini   # -rw------- root root / -rw-r----- <ODR user> www-data
```

### 3. Approved crawlers

```bash
install -m 755 $BB/bin/odr-gate-update-crawlers /usr/local/sbin/
odr-gate-update-crawlers --dry-run $ODR_DIR/var/crawler_ips.txt
#   /etc/apache2/odr-gate-crawlers.conf: 549 IPv4 + 22 IPv6 ranges (new file)
odr-gate-update-crawlers $ODR_DIR/var/crawler_ips.txt
```

It validates the list, writes `/etc/apache2/odr-gate-crawlers.conf`, checks the Apache config,
and reloads Apache. Until the gate is included (step 4), the file has no effect.

### 4. Apache

**4.1 Modules and log format:**

```bash
a2enmod rewrite headers setenvif alias env
```

If `grep -rn combined_gate /etc/apache2/` finds nothing, add this line to
`/etc/apache2/apache2.conf`, next to the other `LogFormat` lines:

```apache
LogFormat "%h %l %u %t \"%r\" %>s %O \"%{Referer}i\" \"%{User-Agent}i\" gate=%{X-ODR-Gate}o" combined_gate
```

You can add fields *after* `gate=%{X-ODR-Gate}o` (for example ` body=%B`), but nothing before it.

**4.2 Install the gate:**

```bash
cp $BB/apache/odr-gate.conf /etc/apache2/conf-available/
```

**4.3 Edit the vhost file.** Inside the `<VirtualHost *:443>` block for `www.rruff.net`:

1. Make its `CustomLog` use `combined_gate`:
   ```apache
   CustomLog ${APACHE_LOG_DIR}/rruff.net-ssl-access.log combined_gate
   ```
2. **Delete** anything from earlier versions of this system:
   - `Include …/odr-bot-bans.conf` and `Include …/odr_minimal_404s.conf`;
   - any hand-added `sample_child_id` rewrite rules and their `Header … odr_attack` line.
   `odr-gate.conf` replaces all of them.
3. Add, before `</VirtualHost>`:
   ```apache
   Include /etc/apache2/conf-available/odr-gate.conf
   ```

Add the same `Include` line to the port-80 vhost too. Its rules run before that vhost's redirect
to https, so bots probing over plain http are answered there.

**4.4 Check the configuration and reload:**

```bash
apachectl configtest          # must print: Syntax OK
systemctl reload apache2
```

**Check from the server** (it's trusted, so the site is open to it):

```bash
curl -s -o /dev/null -w '%{http_code}\n' https://www.rruff.net/     # 200
tail -1 /var/log/apache2/rruff.net-ssl-access.log                   # … 200 … gate=trusted
```

**Check from somewhere else** (your own computer, or a phone on cellular):

```bash
curl -s -o /dev/null -w '%{http_code}\n' https://www.rruff.net/                            # 403  (the challenge)
curl -s -o /dev/null -w '%{http_code}\n' https://www.rruff.net/_/R060867                   # 404
curl -s -o /dev/null -w '%{http_code}\n' -A 'Amazonbot/0.1' https://www.rruff.net/         # 410
curl -s -o /dev/null -w '%{http_code}\n' https://www.rruff.net/odr/api/v1/                 # not 403
```

Then open https://www.rruff.net/ in a browser. You should see "Confirm you are human". Solve it,
and you should land on the home page and be able to browse normally. The access log shows the
sequence:

```
… "GET / HTTP/2.0" 403 … gate=challenge
… "POST / HTTP/2.0" 303 … gate=solved
… "GET / HTTP/2.0" 200 … gate=passed
```

Finally, check that the static render daemon still works: its requests should show
`gate=trusted`.

These test requests are in the log. That's why step 5.3 puts your addresses in `odr_ignoreip`
**before** fail2ban starts: on startup it reads the last hour of the log, and would otherwise
ban you for the challenges.

### 5. fail2ban

**5.1 Install:**

```bash
apt install fail2ban
```

**5.2 Copy in the configuration:**

```bash
cp $BB/fail2ban/filter.d/odr-*.conf  /etc/fail2ban/filter.d/
cp $BB/fail2ban/jail.d/odr.local     /etc/fail2ban/jail.d/
cp $BB/fail2ban/fail2ban.d/odr.local /etc/fail2ban/fail2ban.d/
```

**5.3 Edit `/etc/fail2ban/jail.d/odr.local`.** Set the two values at the top:

```ini
odr_ignoreip = 127.0.0.1/8 ::1 144.217.146.145 216.220.243.246
odr_logpath = /var/log/apache2/rruff.net-ssl-access.log
```

- **`odr_ignoreip`**: this server's address(es) plus every office and VPN address, separated by
  spaces. CIDR ranges are allowed.
- **`odr_logpath`**: the access log, or several, each on its own indented line.

**5.4 Check the filters against the real log, then start:**

```bash
for f in odr-410 odr-minimal404 odr-challenge; do
  printf '%-16s' $f; fail2ban-regex /var/log/apache2/rruff.net-ssl-access.log /etc/fail2ban/filter.d/$f.conf | grep '^Lines:'
done
fail2ban-client -t            # must end with: OK: configuration test is successful
systemctl enable fail2ban
systemctl restart fail2ban
```

**Check:**

```bash
fail2ban-client status        # Jail list: odr-410, odr-bots, odr-challenge, odr-minimal404, sshd
tail /var/log/fail2ban.log
```

### 6. Banned bots' published addresses

```bash
install -m 755 $BB/bin/odr-ban-bot-ips /usr/local/sbin/
odr-ban-bot-ips --dry-run $ODR_DIR/var/banned_bot_ips.txt
odr-ban-bot-ips $ODR_DIR/var/banned_bot_ips.txt
fail2ban-client status odr-bots                       # about 2,300 currently banned
```

### 7. Schedule the updates

As the ODR user (`crontab -u $ODR_USER -e`):

```cron
0 */6 * * *  cd /home/rruff/data-publisher && php app/console odr:banned_bot_ips:update --env=prod
30 3 * * 1   cd /home/rruff/data-publisher && php app/console odr:crawler_ips:update --env=prod
```

As root (`crontab -e`), a little later:

```cron
10 */6 * * * /usr/local/sbin/odr-ban-bot-ips /home/rruff/data-publisher/var/banned_bot_ips.txt
45 3 * * 1   /usr/local/sbin/odr-gate-update-crawlers /home/rruff/data-publisher/var/crawler_ips.txt
```

### 8. Final check, after an hour or so

```bash
grep -o 'gate=[a-z0-9_-]*' /var/log/apache2/rruff.net-ssl-access.log | sort | uniq -c | sort -rn
for j in odr-410 odr-minimal404 odr-challenge odr-bots; do
  printf '%-16s ' $j; fail2ban-client status $j | grep 'Currently banned'
done
```

Expect `challenge` and `passed` to dominate, some `crawler`, and a steady trickle of bans.

---

## Day-to-day operation

| Task | Command |
| --- | --- |
| What the site is doing | `grep -o 'gate=[a-z0-9_-]*' <access log> \| sort \| uniq -c \| sort -rn` |
| Ban overview | `fail2ban-client status` |
| One jail: counts and banned addresses | `fail2ban-client status odr-challenge` |
| Is an address banned, and by which jail? | `fail2ban-client banned 1.2.3.4` |
| Unban an address | `fail2ban-client unban 1.2.3.4` |
| Ban an address by hand (48 h) | `fail2ban-client set odr-410 banip 1.2.3.4` |
| Recent bans | `grep -E ' (Ban\|Unban) ' /var/log/fail2ban.log \| tail -20` |

**Sign everyone out of the gate.** Put a new secret in both `/etc/apache2/odr-gate-local.conf`
and `pass_secret` in `app/config/gate.ini`, then `systemctl reload apache2`. Every visitor solves the reCAPTCHA
again.

**Turn the gate off in an emergency.** Comment out the `Include …/odr-gate.conf` line(s) and
run `apachectl configtest && systemctl reload apache2`. This also switches off the 410 and
minimal-404 rules. fail2ban keeps working from the theme's own tags.

## Changing the rules

| Change | Where | Then |
| --- | --- | --- |
| How long a solved reCAPTCHA lasts | `pass_hours` in `app/config/gate.ini` (default 24, minimum 1) | applies to passes solved from then on |
| Free-pass addresses | `/etc/apache2/odr-gate-local.conf` | `systemctl reload apache2` |
| Never-ban addresses | `odr_ignoreip` in `/etc/fail2ban/jail.d/odr.local` | `fail2ban-client reload` |
| Ban limits (`maxretry`, `findtime`, `bantime`) | `/etc/fail2ban/jail.d/odr.local` | `fail2ban-client reload` |
| Ban a bot by user agent | the `(Amazonbot)` list in `/etc/apache2/conf-available/odr-gate.conf`, e.g. `(Amazonbot\|Bytespider)` | `systemctl reload apache2` |
| Ban a bot by its published addresses | `SOURCES` in `src/ODR/AdminBundle/Command/UpdateBannedBotIpsCommand.php` | next cron run |

Copy changes to `odr-gate.conf` and the fail2ban files back into `docs/server/bot-bans/` in the
repo, so the next server gets them too.

A bot's published-address source must publish `"ipv4Prefix"` / `"ipv6Prefix"` entries. The
command deliberately never takes "any address-looking text" from a page: Amazon's page also
contains the *visitor's* address, which would ban the server that ran the update.

## Troubleshooting

**Every page shows "Confirm you are human" again, even after solving it.** The pass cookie isn't
being accepted. Check:
- Do the secrets match? Compare `ODR_GATE_SECRET` in `/etc/apache2/odr-gate-local.conf` with
  `pass_secret` in `app/config/gate.ini`.
- Is the browser keeping the cookie? Look for `odr_pass` in its cookies for the site.
- Did the visitor's address change? Phones moving between networks do, and the pass is per
  address.

**"Temporarily unavailable" (503, `gate=gate-misconfigured`).** `challenge.php` can't read
`app/config/gate.ini`, or a value is missing. Check it exists and is group `www-data`, mode
640, and look in Apache's error log for `odr-gate:`.

**The reCAPTCHA box says "Invalid domain for site key".** Add the hostname in the reCAPTCHA
admin console.

**Google is being challenged** (Googlebot requests logged `gate=challenge`). The crawler
ranges aren't loaded. Check `/etc/apache2/odr-gate-crawlers.conf` exists and isn't empty, then
re-run step 3.

**The static render daemon or WordPress cron stopped working.** Their requests are being
challenged: look for `gate=challenge` from the server's own address. Put the address in the
free-pass line (step 2.1) and reload Apache.

**A real person or the office got banned.**
1. `fail2ban-client banned <address>` shows which jail banned it.
2. `fail2ban-client unban <address>` lifts the ban.
3. Add the address to `odr_ignoreip`, then run `fail2ban-client reload`.

**fail2ban won't start.** Look at `fail2ban-client -t` and `journalctl -u fail2ban -n 50`.

**`apachectl configtest` fails after an update.** It names the file and line. If the problem is
`odr-gate-crawlers.conf`, re-run `odr-gate-update-crawlers`. It restores the previous file
whenever the new one fails the check.

## Removing it

1. In the vhost file(s), delete the `Include …/odr-gate.conf` line(s), then run
   `apachectl configtest && systemctl reload apache2`.
2. Then:

```bash
systemctl disable --now fail2ban
rm /etc/fail2ban/jail.d/odr.local /etc/fail2ban/fail2ban.d/odr.local /etc/fail2ban/filter.d/odr-*.conf
rm /etc/apache2/odr-gate-local.conf /etc/apache2/odr-gate-crawlers.conf /etc/apache2/conf-available/odr-gate.conf
rm <ODR dir>/app/config/gate.ini
rm /usr/local/sbin/odr-ban-bot-ips /usr/local/sbin/odr-gate-update-crawlers
crontab -e                     # delete the two root lines
crontab -u <ODR user> -e       # delete the two ODR lines
```

---

## Files

| In the repo | Installed to | Purpose |
| --- | --- | --- |
| `docs/server/bot-bans/apache/odr-gate.conf` | `/etc/apache2/conf-available/` | the gate: free pass, 410s, minimal 404s, api / crawler / cookie checks, the challenge |
| `docs/server/bot-bans/apache/odr-gate-local.conf.example` | `/etc/apache2/odr-gate-local.conf` (root, 600) | this server's secret, gate directory and free-pass addresses |
| `docs/server/bot-bans/gate/challenge.php` | (served from the repo) | the reCAPTCHA page; sets the pass cookie |
| `app/config/gate.ini.dist` | `app/config/gate.ini` (per instance, never committed; group www-data, 640) | reCAPTCHA keys, secret, pass length |
| `docs/server/bot-bans/bin/odr-gate-update-crawlers` | `/usr/local/sbin/` | crawler list → `/etc/apache2/odr-gate-crawlers.conf` |
| `docs/server/bot-bans/bin/odr-ban-bot-ips` | `/usr/local/sbin/` | banned-bot list → fail2ban `odr-bots` |
| `docs/server/bot-bans/fail2ban/filter.d/odr-*.conf` | `/etc/fail2ban/filter.d/` | what each jail counts |
| `docs/server/bot-bans/fail2ban/jail.d/odr.local` | `/etc/fail2ban/jail.d/` | the jails and their limits |
| `docs/server/bot-bans/fail2ban/fail2ban.d/odr.local` | `/etc/fail2ban/fail2ban.d/` | keeps ban records long enough to survive restarts |
| `src/ODR/AdminBundle/Command/UpdateCrawlerIpsCommand.php` | (ODR code) | `odr:crawler_ips:update` → `var/crawler_ips.php` and `.txt` |
| `src/ODR/AdminBundle/Command/UpdateBannedBotIpsCommand.php` | (ODR code) | `odr:banned_bot_ips:update` → `var/banned_bot_ips.txt` |
