# Ban System

Blocks abusive clients at the server's firewall, so their requests never reach Apache, PHP,
WordPress or ODR. It is built from three pieces that already exist on Ubuntu: Apache writes a
reason into each access-log line, **fail2ban** reads the log and decides who to ban, and
**nftables** (the kernel firewall) drops their traffic.

- [How it works](#how-it-works)
- [What gets banned](#what-gets-banned)
- [Installation](#installation)
- [Day-to-day operation](#day-to-day-operation)
- [Changing the rules](#changing-the-rules)
- [Troubleshooting](#troubleshooting)
- [Removing it](#removing-it)

---

## How it works

```
                 ┌──────────────────────────────────────────────────────────┐
 request ──────► │ nftables (kernel firewall): is the address in a ban set?  │──yes──► dropped
                 └──────────────────────────────────────────────────────────┘
                                 │ no
                                 ▼
                 Apache ── banned bot / Bustamite flood? ──► 410, logged gate=banned-bot / attack
                                 │
                                 ▼
                 WordPress / ODR ──► 404 gate=minimal_404, 403 gate=challenge, 302 gate=solved ...
                                 │
                                 ▼
                 access log: one line per request, ending in gate=<reason>
                                 │
                                 ▼
                 fail2ban: reads each new line, counts per address, bans ──► nftables
```

**Apache, the WordPress theme and ODR only label requests.** Each response carries an
`X-ODR-Gate` header (for example `challenge` or `minimal_404`), and Apache's log format writes
it at the end of the access-log line as `gate=…`. They don't count anything.

**fail2ban does the counting.** It is a background service (`systemctl status fail2ban`):

1. It **follows the access log** like `tail -f`, reading each line as Apache writes it, and keeps
   up when the log is rotated.
2. It tests each line against the **filters** (`/etc/fail2ban/filter.d/odr-*.conf`). A filter is
   a regular expression. For example, `odr-challenge` matches a line whose status is `403` and
   which ends in `gate=challenge`, and takes the client address from the start of the line.
3. For each **jail** (a filter plus its limits) it remembers, per address, the times of recent
   matches. Matches older than the jail's window (`findtime`, 1 hour) are forgotten
   automatically.
4. When an address reaches the jail's limit (`maxretry`), fail2ban **bans** it: it runs
   `nft add element …`, adding the address to a set in the firewall. From then on the kernel
   drops its packets to ports 80 and 443.
5. The ban and its end time are saved in fail2ban's database
   (`/var/lib/fail2ban/fail2ban.sqlite3`). When `bantime` (48 hours) is up, fail2ban removes the
   address again, and after a restart it restores every ban that hasn't expired.

**Banned bots' published addresses** don't come from the log. The ODR console command
`odr:banned_bot_ips:update` downloads them into a file, and a small root script
(`odr-ban-bot-ips`) hands that file to fail2ban's `odr-bots` jail. Both run from cron.

Because banned traffic is dropped before Apache, **it no longer appears in the access log**.
Bans and unbans are logged in `/var/log/fail2ban.log`.

## What gets banned

| Jail | Trigger | Ban length |
| --- | --- | --- |
| `odr-410` | any **410** response: banned bots by user agent (`gate=banned-bot`) and the `Bustamite?sample_child_id=` flood (`gate=attack`) | immediately, **48 hours** |
| `odr-minimal404` | **5** × `gate=minimal_404` within **1 hour**: urls the theme refused as not being a valid search | **48 hours** |
| `odr-challenge` | **5** × `gate=challenge` within **1 hour**: shown the reCAPTCHA five times without solving it | **48 hours** |
| `odr-bots` | the address is on a banned bot's **published list** (currently Amazonbot) | **3 days**, re-applied every 6 hours |

- These rules apply to everyone, **verified search crawlers included**. Googlebot following five
  dead links in an hour is banned for 48 hours.
- Only the theme's `minimal_404` counts toward the 404 limit. Ordinary 404s, such as a missing
  image on a real page, don't.
- `challenge-stale-form` and `challenge-google-unreachable` don't count, only `challenge`.
- Addresses in `odr_ignoreip` (see step 3.3) are never banned.
- Bans cover **ports 80 and 443 only**, so a mistaken ban can never lock anyone out of ssh.

---

## Installation

Do this on each server that serves the RRUFF site, **as root**. Run the steps in order:
each one ends with a check, so stop there if the check fails.

### 0. Before you start

**Prerequisites:**

| Requirement | How to check |
| --- | --- |
| Ubuntu 24.04 with Apache 2.4 | `apache2 -v` |
| nftables | `nft --version` |
| Python 3 | `python3 --version` |
| WordPress theme **odr-astra-child-theme 1.2.18 or later** (it writes the `gate=` values) | `grep ^Version /home/rruff/wordpress/wp-content/themes/odr-astra-child-theme/style.css` |
| This ODR code deployed (it contains `docs/server/bot-bans/`) | see step 1 |

**Values used below:** set these in your root shell first, adjusting them to the server:

```bash
ODR_DIR=/home/rruff/data-publisher                  # the ODR instance that will own the bot list
BB=$ODR_DIR/docs/server/bot-bans                    # the ban-system files in the repo
OFFICE_IPS="216.220.243.246"                        # office / VPN addresses that must never be banned
SERVER_IPS="144.217.146.145"                        # this server's own public address(es)
```

**Find the rruff site's access log and vhost file**, and note both:

```bash
grep -nE "ServerName|CustomLog" /etc/apache2/sites-enabled/*.conf
```

Find the `ServerName www.rruff.net` block for **port 443**. Its `CustomLog` line names the access
log, for example `${APACHE_LOG_DIR}/rruff.net-ssl-access.log`, which is
`/var/log/apache2/rruff.net-ssl-access.log`. The instructions below call these the **vhost file**
and the **access log**.

### 1. Deploy the code

```bash
cd $ODR_DIR
sudo -u "$(stat -c %U $ODR_DIR/var)" git pull
sudo -u "$(stat -c %U $ODR_DIR/var)" php app/console cache:clear --env=prod
```

**Check:**

```bash
ls $BB                                               # apache  bin  fail2ban  README.md
sudo -u "$(stat -c %U $ODR_DIR/var)" php app/console list odr --env=prod | grep banned_bot
#   odr:banned_bot_ips:update  Downloads the published addresses of banned bots ...
```

### 2. Apache

**2.1 Enable the modules** (harmless if already enabled):

```bash
a2enmod rewrite headers
```

**2.2 Add the log format.** If `grep -rn combined_gate /etc/apache2/` finds nothing yet, add this
line to `/etc/apache2/apache2.conf`, next to the other `LogFormat` lines:

```apache
LogFormat "%h %l %u %t \"%r\" %>s %O \"%{Referer}i\" \"%{User-Agent}i\" gate=%{X-ODR-Gate}o" combined_gate
```

You can add fields *after* `gate=%{X-ODR-Gate}o` (for example ` body=%B`), but nothing before
it, or the fail2ban filters won't match.

**2.3 Install the rule files:**

```bash
cp $BB/apache/odr-bot-bans.conf    /etc/apache2/conf-available/
cp $BB/apache/odr_minimal_404s.conf /etc/apache2/conf-available/
```

- `odr-bot-bans.conf` answers banned bots and the Bustamite flood with **410**
  (`gate=banned-bot`, `gate=attack`).
- `odr_minimal_404s.conf` answers the urls the WordPress theme would refuse with its minimal 404
  (old RRUFF urls, missing root files, bot-shaped paths like `/_/R060867` or `/chem=o/R060290`)
  with a **404** tagged `gate=minimal_404`, *without starting WordPress*. Only patterns that no
  real page can match are there. The theme still refuses everything else, and remains the
  fallback for these.

**2.4 Edit the vhost file.** Inside the `<VirtualHost *:443>` block for `www.rruff.net`:

1. Make its `CustomLog` line use `combined_gate`:
   ```apache
   CustomLog ${APACHE_LOG_DIR}/rruff.net-ssl-access.log combined_gate
   ```
2. **Delete** any earlier hand-added rules for the flood: the `RewriteCond … sample_child_id`
   lines, their `RewriteRule … [G,L,E=odr_attack:1]`, and
   `Header always set X-ODR-Gate attack env=odr_attack`. The include replaces them.
3. Add, before `</VirtualHost>`, in this order:
   ```apache
   Include /etc/apache2/conf-available/odr-bot-bans.conf
   Include /etc/apache2/conf-available/odr_minimal_404s.conf
   ```
4. Make sure the vhost has **no `ErrorDocument 404`** pointing into WordPress (for example
   `ErrorDocument 404 /index.php`). With one, each of these 404s would start WordPress after all.

The port-80 vhost can take the same two `Include` lines. Apache's rewrite rules run before its
`Redirect`, so bots probing over plain http are answered there without the extra redirect hop.
If the port-80 vhost serves the site itself rather than redirecting, it needs them.

**2.5 Check the configuration and reload:**

```bash
apachectl configtest          # must print: Syntax OK
systemctl reload apache2
```

**Check:** each should give the result shown.

```bash
curl -s -o /dev/null -w '%{http_code}\n' -A 'Amazonbot/0.1' https://www.rruff.net/            # 410
curl -s -o /dev/null -w '%{http_code}\n' 'https://www.rruff.net/Bustamite?sample_child_id=1'  # 410
curl -s -o /dev/null -w '%{http_code}\n' https://www.rruff.net/_/R060867                      # 404
curl -s -o /dev/null -w '%{http_code}\n' https://www.rruff.net/                              # 200
tail -4 /var/log/apache2/rruff.net-ssl-access.log
#   … "GET / HTTP/2.0" 410 … gate=banned-bot
#   … "GET /Bustamite?sample_child_id=1 HTTP/2.0" 410 … gate=attack
#   … "GET /_/R060867 HTTP/2.0" 404 … gate=minimal_404
#   … "GET / HTTP/2.0" 200 … gate=-
```

The `/_/R060867` 404 is Apache's own short "Not Found" page rather than the theme's. That's how
you can tell WordPress wasn't started for it.

These test requests come from your own address. That's why step 3.3 puts it in `odr_ignoreip`
**before** fail2ban starts: on startup fail2ban reads the last hour of the log, and would
otherwise ban you for them.

### 3. fail2ban

**3.1 Install:**

```bash
apt install fail2ban
```

**3.2 Copy in the ODR configuration:**

```bash
cp $BB/fail2ban/filter.d/odr-*.conf  /etc/fail2ban/filter.d/
cp $BB/fail2ban/jail.d/odr.local     /etc/fail2ban/jail.d/
cp $BB/fail2ban/fail2ban.d/odr.local /etc/fail2ban/fail2ban.d/
```

**3.3 Edit `/etc/fail2ban/jail.d/odr.local`.** Two settings at the top must match the server:

```ini
odr_ignoreip = 127.0.0.1/8 ::1 144.217.146.145 216.220.243.246
odr_logpath = /var/log/apache2/rruff.net-ssl-access.log
```

- **`odr_ignoreip`**: add this server's own address(es) and every office and VPN address,
  separated by spaces (CIDR ranges allowed). Anyone testing the search gate produces
  challenges, and five unsolved ones within an hour would ban them for 48 hours.
- **`odr_logpath`**: the access log from step 0. If several logs use `combined_gate`, list each
  on its own line, indented:
  ```ini
  odr_logpath = /var/log/apache2/rruff.net-ssl-access.log
                /var/log/apache2/beta.rruff.net-ssl-access.log
  ```

**3.4 Check the filters against the real log** (read-only):

```bash
for f in odr-410 odr-minimal404 odr-challenge; do
  echo "== $f"; fail2ban-regex /var/log/apache2/rruff.net-ssl-access.log /etc/fail2ban/filter.d/$f.conf | grep '^Lines:'
done
```

Each prints `Lines: N lines, 0 ignored, M matched, …`. Once traffic has been logged in the
`combined_gate` format for a while, `odr-410` should match your step 2 tests, and the other two
should match some lines. If everything shows `0 matched`, see [Troubleshooting](#troubleshooting).

**3.5 Check the configuration, then start:**

```bash
fail2ban-client -t            # must end with: OK: configuration test is successful
systemctl enable fail2ban
systemctl restart fail2ban
```

**Check:**

```bash
fail2ban-client status
#   Jail list:   odr-410, odr-bots, odr-challenge, odr-minimal404, sshd
fail2ban-client status odr-challenge          # shows currently failed / banned counts
nft list table inet f2b-table | head -20      # the firewall sets fail2ban manages
tail -20 /var/log/fail2ban.log
```

### 4. Banned bots' published addresses

**4.1 Install the loader script:**

```bash
install -m 755 $BB/bin/odr-ban-bot-ips /usr/local/sbin/odr-ban-bot-ips
```

**4.2 Generate the list once by hand,** as the user that owns the ODR instance's `var/`:

```bash
cd $ODR_DIR
sudo -u "$(stat -c %U $ODR_DIR/var)" php app/console odr:banned_bot_ips:update --env=prod
#   Fetching amazonbot ...
#     1292 addresses from https://developer.amazon.com/amazonbot/ip-addresses/
#     1023 addresses from https://developer.amazon.com/amazonbot/live-ip-addresses/
#   Total: 2315 addresses
#   Wrote /home/rruff/data-publisher/var/banned_bot_ips.txt
```

**4.3 Load it into fail2ban.** Do a dry run first:

```bash
odr-ban-bot-ips --dry-run $ODR_DIR/var/banned_bot_ips.txt
#   odr-bots: 2315 addresses listed, 0 already banned, 2315 to ban
odr-ban-bot-ips $ODR_DIR/var/banned_bot_ips.txt
#   banned 2315
```

**Check:** `fail2ban-client status odr-bots` shows about 2,300 currently banned.

**4.4 Schedule both.** Amazon rotates its addresses, so refresh every 6 hours.

As the ODR instance's user, run `sudo crontab -u "$(stat -c %U $ODR_DIR/var)" -e` and add:

```cron
0 */6 * * *  cd /home/rruff/data-publisher && php app/console odr:banned_bot_ips:update --env=prod
```

As root, run `crontab -e` and add (ten minutes later):

```cron
10 */6 * * * /usr/local/sbin/odr-ban-bot-ips /home/rruff/data-publisher/var/banned_bot_ips.txt
```

### 5. Final check

After an hour or so of normal traffic:

```bash
fail2ban-client status | tail -1
for j in odr-410 odr-minimal404 odr-challenge odr-bots; do
  printf '%-16s ' $j; fail2ban-client status $j | grep 'Currently banned'
done
grep -c ' Ban ' /var/log/fail2ban.log
```

Also confirm from a phone on cellular (not the office network) that https://www.rruff.net/
and a normal search such as https://www.rruff.net/Bustamite still work.

---

## Day-to-day operation

| Task | Command |
| --- | --- |
| Overview | `fail2ban-client status` |
| One jail: counts and banned addresses | `fail2ban-client status odr-challenge` |
| Is an address banned, and by which jail? | `fail2ban-client banned 1.2.3.4` |
| Unban an address everywhere | `fail2ban-client unban 1.2.3.4` |
| Unban from one jail | `fail2ban-client set odr-minimal404 unbanip 1.2.3.4` |
| Ban an address by hand (48 h) | `fail2ban-client set odr-410 banip 1.2.3.4` |
| Recent bans | `grep -E ' (Ban\|Unban) ' /var/log/fail2ban.log \| tail -20` |
| What the site is refusing (still reaching Apache) | `grep -o 'gate=[a-z0-9_-]*' /var/log/apache2/rruff.net-ssl-access.log \| sort \| uniq -c` |

**Never-ban list.** To add an address, edit `odr_ignoreip` in `/etc/fail2ban/jail.d/odr.local`,
then run `fail2ban-client reload`. If it's already banned, unban it as above.

## Changing the rules

All limits are in `/etc/fail2ban/jail.d/odr.local`. Apply changes with
`fail2ban-client reload`.

| Setting | Meaning |
| --- | --- |
| `maxretry` | matches needed for a ban (5; 1 for `odr-410`) |
| `findtime` | the window they must fall in (`1h`) |
| `bantime` | how long a ban lasts (`48h`; `3d` for `odr-bots`) |

Copy changes back into `docs/server/bot-bans/` in the repo, so the next server gets them too.

**Ban another bot by user agent.** This catches it from any address, including ones it hasn't
published yet. Add its name to the list in `/etc/apache2/conf-available/odr-bot-bans.conf`, then
reload Apache:

```apache
RewriteCond %{HTTP_USER_AGENT} (Amazonbot|Bytespider|PetalBot) [NC]
```

**Ban another bot by its published addresses.** This stops it at the firewall before it
connects. Add an entry to `SOURCES` in
`src/ODR/AdminBundle/Command/UpdateBannedBotIpsCommand.php`. The url must publish
`"ipv4Prefix"` / `"ipv6Prefix"` entries, either as JSON or embedded in a page, as Google, Bing,
DuckDuckGo and Amazon do. The command deliberately never takes "any address-looking text" from
a page: Amazon's page also contains the *visitor's* address, which would ban the server that ran
the update.

Doing both is best.

## Troubleshooting

**Filters match 0 lines.**
- **Wrong log format.** Look at the end of a recent line: `tail -1 <access log>`. It must end in
  `gate=…`. If it doesn't, the vhost's `CustomLog` isn't using `combined_gate` (step 2.4).
- **Wrong file.** Check that `odr_logpath` names the file that line came from.

**A real person, or the office, got banned.**
1. `fail2ban-client banned <address>` shows which jail banned it.
2. `fail2ban-client unban <address>` lifts the ban.
3. Add the address to `odr_ignoreip`, then run `fail2ban-client reload`.

**fail2ban won't start.** Look at `fail2ban-client -t` and `journalctl -u fail2ban -n 50`. They
name the file and line at fault.

**`odr-ban-bot-ips` says "no list" or the list is old.** Run the ODR command by hand (step 4.2)
and read its output. If a source fails to download, the command keeps that source's previous
addresses and exits non-zero, so cron reports it.

**`odr:banned_bot_ips:update` says it can't write the file.** Run it as the user that owns
`$ODR_DIR/var` (`stat -c %U $ODR_DIR/var`).

**Something is blocked and you're not sure it's the ban system.** `fail2ban-client unban --all`
lifts every ban at once. Watch whether the problem goes away, then look at
`/var/log/fail2ban.log`.

## Removing it

To switch it off temporarily: `systemctl stop fail2ban`. That removes all of fail2ban's firewall
rules until it starts again.

To remove it:

```bash
systemctl disable --now fail2ban
rm /etc/fail2ban/jail.d/odr.local /etc/fail2ban/fail2ban.d/odr.local /etc/fail2ban/filter.d/odr-*.conf
crontab -e                                   # delete the odr-ban-bot-ips line
sudo crontab -u <ODR user> -e                # delete the odr:banned_bot_ips:update line
rm /usr/local/sbin/odr-ban-bot-ips
```

In the vhost file(s), delete the two `Include …/odr-bot-bans.conf` / `…/odr_minimal_404s.conf`
lines, then run
`apachectl configtest && systemctl reload apache2`.

---

## Files

| In the repo | Installed to | Purpose |
| --- | --- | --- |
| `docs/server/bot-bans/apache/odr-bot-bans.conf` | `/etc/apache2/conf-available/` | 410 for banned bots and the Bustamite flood |
| `docs/server/bot-bans/apache/odr_minimal_404s.conf` | `/etc/apache2/conf-available/` | the theme's minimal 404s, answered before WordPress starts |
| `docs/server/bot-bans/fail2ban/filter.d/odr-410.conf` | `/etc/fail2ban/filter.d/` | matches any 410 |
| `docs/server/bot-bans/fail2ban/filter.d/odr-minimal404.conf` | `/etc/fail2ban/filter.d/` | matches `gate=minimal_404` |
| `docs/server/bot-bans/fail2ban/filter.d/odr-challenge.conf` | `/etc/fail2ban/filter.d/` | matches `gate=challenge` |
| `docs/server/bot-bans/fail2ban/filter.d/odr-manual.conf` | `/etc/fail2ban/filter.d/` | placeholder for `odr-bots` (never matches) |
| `docs/server/bot-bans/fail2ban/jail.d/odr.local` | `/etc/fail2ban/jail.d/` | the four jails and their limits |
| `docs/server/bot-bans/fail2ban/fail2ban.d/odr.local` | `/etc/fail2ban/fail2ban.d/` | keeps ban records 4 days, so restarts restore them |
| `docs/server/bot-bans/bin/odr-ban-bot-ips` | `/usr/local/sbin/` | loads the bot list into `odr-bots` |
| `src/ODR/AdminBundle/Command/UpdateBannedBotIpsCommand.php` | (ODR code) | `odr:banned_bot_ips:update` |
| `var/banned_bot_ips.txt` | (generated, per instance) | the downloaded bot addresses |
