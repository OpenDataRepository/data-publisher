# UPGRADE_TO_SF7.md — deploying the Symfony 7 branch

This branch (`sf7-develop-sync`) moves ODR from **Symfony 3.4 / PHP 7.3 / Doctrine ORM 2.6**
to **Symfony 7.4 LTS / PHP 8.3 / Doctrine ORM 2.7 + doctrine-migrations**. Because the stack,
directory layout, and schema-management flow all changed, the old "pull code and run
`regenerate_and_update.sh`" routine **no longer applies** (see [§11](#11-why-regenerate_and_updatesh-is-retired)).

Follow the steps below in order on a staging/instance box. Each `###` step says what to run and how
to confirm it worked. Commands assume you are in the project root and use the pre-Flex console at
**`app/console`** (there is no `bin/console`).

---

## 0. Before you start — back up

```bash
# Database
mysqldump --single-transaction --routines --triggers <ODR_DB> > odr_backup_$(date +%F).sql

# Active (gitignored) config — these are NOT in version control and must survive the upgrade
cp app/config/parameters.yml   ~/odr_parameters.yml.bak
cp app/config/config.yml       ~/odr_config.yml.bak
cp app/config/routing.yml      ~/odr_routing.yml.bak
cp app/config/security.yml     ~/odr_security.yml.bak
# JWT keypair (if you have one you want to keep)
cp -r app/config/jwt           ~/odr_jwt.bak
```

Note the current commit so you can roll back: `git rev-parse HEAD`.

---

## 1. System requirements

### PHP
- **PHP 8.2+ required, 8.3 recommended** (Symfony 7.4 needs ≥ 8.2; this branch is developed/run on
  8.3.31). PHP 7.x will **not** work.
- Confirm: `php -v`

### PHP extensions
Symfony 7.4 + ODR need these loaded in **both CLI and FPM/Apache** SAPIs:

| Extension | Why |
|-----------|-----|
| `intl` | **Required.** UCA/`Collator` sorting (tags, radio options, file lists, search). Without it ODR silently falls back to a weaker natural sort — install it for correct prod behavior. |
| `pdo_mysql`, `mysqli` | Database |
| `mbstring` | String handling |
| `intl`, `xml`, `xsl`, `dom` | Form/serializer/XML-export |
| `gd` (or `imagick`) | Image thumbnails |
| `curl` | Elasticsearch seeding, API fetches |
| `zip` | Archive download/import |
| `memcached` | Doctrine result/metadata cache + sessions |
| `opcache` | Performance (opcache-gui is bundled) |
| `redis` | Optional — `predis` (pure PHP) is the fallback, but the native ext is faster |

Confirm what's loaded:
```bash
php -m | sort
# Quick check for the critical ones (esp. intl):
php -r 'foreach (["intl","pdo_mysql","mbstring","gd","curl","zip","xml","xsl","memcached"] as $e) printf("%-10s %s\n", $e, extension_loaded($e)?"OK":"MISSING");'
```
Install any that are `MISSING` (e.g. Debian/Ubuntu: `sudo apt install php8.3-intl php8.3-gd php8.3-curl php8.3-zip php8.3-xsl php8.3-mbstring php8.3-mysql php8.3-memcached`), then restart PHP-FPM/Apache.

### Services (same as before — versions unchanged)
- **MySQL / MariaDB** (5.7+ / 10.x)
- **Memcached** — `localhost:11211` (Doctrine cache + sessions)
- **Redis** — `localhost:6379` (cache, pub/sub, static-render versioning)
- **Beanstalkd** — `localhost:11300` (job queue)
- **Node.js** (18+) — for `background_services/` daemons (workers, graph/static renderers)

Confirm they're up: `systemctl status mysql memcached redis-server beanstalkd` (names vary by distro).

### Composer
- Composer 2.x. Use the checked-in **`composer.phar`** (project root) — there is no system-wide
  `composer` on the reference boxes; run everything as `php composer.phar …`.
- The repo ships a committed **`composer.lock`**, and `composer.json` sets
  `config.platform` (`php: 8.2.0`, `ext-redis: 6.1`) so dependency resolution is deterministic and
  portable to any PHP ≥ 8.2 host, regardless of the box's exact PHP/phpredis version. **Deploy with
  `composer install` (from the lock), never `composer update`** — see [§3](#3-install-php-dependencies-replaces-the-old-entityschema-regeneration).

---

## 2. Pull the branch

```bash
git fetch origin
git checkout sf7-develop-sync
git pull --ff-only origin sf7-develop-sync
```

---

## 3. Install PHP dependencies (replaces the old entity/schema regeneration)

```bash
php composer.phar install --no-interaction
```

- **`install`, not `update`.** `install` reproduces the exact versions in the committed
  `composer.lock`. `update` re-resolves every dependency to the newest allowed and rewrites the lock —
  never run it on staging/prod. (Only run `update` deliberately on a dev box when you *intend* to move
  dependency versions, then commit the regenerated lock.)
- This runs the **incenteev parameter handler** (`post-install-cmd`), which interactively reconciles
  `app/config/parameters.yml` against `parameters.yml.dist` — it will prompt for any **new** parameter
  keys and keep your existing values. See [§5](#5-reconcile-active-config).

**About the platform pin.** `composer.json` declares `config.platform` (`php: 8.2.0`,
`ext-redis: 6.1`). This makes resolution reproducible and lets `install` succeed on hosts whose actual
PHP/phpredis differs, so you should **not** need `--ignore-platform-reqs`:
- `php: 8.2.0` — the lock is resolved for PHP 8.2, so it installs on any PHP ≥ 8.2 (8.3 recommended)
  without pulling in packages that require a newer PHP than the host has.
- `ext-redis: 6.1` — `symfony/cache` 7.4 declares `conflict: ext-redis <6.1` (old phpredis had cache
  bugs). ODR's Redis client is `type: predis` (pure PHP), so the phpredis extension is never used by
  the cache adapter; the platform pin satisfies that conflict at install time on hosts with older
  phpredis (or none). If you'd rather remove the pin, upgrade phpredis to ≥ 6.1 on every host instead.

> If a build genuinely can't satisfy a platform requirement (e.g. an offline CI image), a *scoped*
> `--ignore-platform-req=ext-<name>` is acceptable for that build only; the **runtime** host must still
> satisfy [§1](#1-system-requirements).

Confirm: `php composer.phar validate` and that `vendor/` populated without errors.

> **Security advisories.** `composer` reports several advisories against the known abandoned deps
> (`php-http/guzzle6-adapter`, etc. — see CLAUDE.md's tech-debt list). Run `php composer.phar audit`
> for the current list; these are tracked for replacement and are not upgrade blockers.

---

## 4. JWT keypair (API authentication)

Lexik JWT reads `app/config/jwt/private.pem` + `public.pem` (passphrase in `config.yml`,
default `opendatarepository` — change it for prod). If those files already exist from your backup,
restore them. Otherwise generate a pair:

```bash
php app/console lexik:jwt:generate-keypair --overwrite   # writes to app/config/jwt/
# — or manually —
mkdir -p app/config/jwt
openssl genpkey -out app/config/jwt/private.pem -aes256 -algorithm rsa -pkeyopt rsa_keygen_bits:4096
openssl pkey -in app/config/jwt/private.pem -out app/config/jwt/public.pem -pubout
```
Make sure the `pass_phrase` in `app/config/config.yml` (`lexik_jwt_authentication:`) matches the key.

---

## 5. Reconcile active config

The sync deliberately **did not touch** your live (gitignored) config — only the tracked `*.dist`
templates were updated. Diff the templates against your active files and merge in the new keys. The
authoritative list of what changed is **`DEVELOP_SYNC_CHANGELIST.md`**; the essentials:

```bash
diff app/config/parameters.yml.dist app/config/parameters.yml
diff app/config/config.yml.dist     app/config/config.yml
diff app/config/routing.yml.dist    app/config/routing.yml
diff app/config/security.yml.dist   app/config/security.yml
```

Apply at minimum:

1. **`parameters.yml`**
   - `elastic_server_baseurl` must be a **scalar** string (e.g. `'http://127.0.0.1:9299'`), not a list.
   - *(optional, WordPress-integrated)* `odr_wordpress_search_redirects` — see the changelist.
2. **`config.yml`** — the `doctrine_migrations:` block (migrations path) and, if WP-integrated, the
   `config_odr_wordpress_search_redirects` twig global.
3. **`routing.yml` + `security.yml`** — the expanded API-login routes/firewalls
   (`api_login_check_v{3,4,5}[_odr[_rruff|_data]]`). Regenerate these from the updated `.dist`
   templates for whichever WordPress mode this instance runs. **This is required for the v4/v5 and
   `/odr*`-prefixed token endpoints to resolve** and must be validated with per-mode JWT tests
   ([§10](#10-post-upgrade-verification)). The `security.yml.dist` firewalls are already in SF7
   authenticator syntax (no `anonymous: true`).

> If this is a **symlinked instance** (e.g. `dev.rruff.net`), also see the `ODR_APP_DIR` /
> `setup_virtualhost.sh` notes in `DEVELOP_SYNC_CHANGELIST.md`.

### Validator for linked / WordPress-integrated instances

**Linked and WordPress-integrated instances keep their OWN copies of `app/config`** (they do not
symlink or pull it from the source tree), so upgrade fixes to the config never reach them and the
kernel fails at boot one stale file at a time. `app/config/validate_instance_config.php` diffs an
instance's config against a current source checkout in a single pass — and needs **only
`symfony/yaml`** (it never boots the app, so it works on an instance that can't start):

```bash
# from a CURRENT source checkout; point it at the instance root (or its app/config dir)
php app/config/validate_instance_config.php /home/rruff/data-publisher
```

It reports two classes of file:

- **Tracked files** (`config_{dev,prod,test}.yml`, `doctrine_extensions.yml`, `routing_dev.yml`,
  `routing_prefixed.yml`, `security.openapi`) must be **byte-identical** to source. Stale/missing
  ones are shown with a diff and can be synced automatically with `--fix` (safe — they hold no
  instance-specific values).
- **Live `.dist`-backed files** (`config.yml`, `security.yml`, `routing.yml`, `parameters.yml`) are
  compared **structurally** against their `.dist` template — missing keys (add), removed keys
  (review/delete), and changed values — so instance-specific values don't create noise
  (`parameters.yml` is compared keys-only). Each live file is also **parse-checked with the same
  YAML parser the kernel uses**, so it catches boot-blockers like an unquoted `%…%` scalar *before*
  you hit them in the browser. These must be merged by hand.

```bash
php app/config/validate_instance_config.php /home/rruff/data-publisher --fix   # sync tracked files
php app/config/validate_instance_config.php /home/rruff/data-publisher         # re-check; hand-merge the rest
```

Exit code is `0` when in sync, `1` when drift is found (usable in a deploy gate).

---

## 6. Database migrations (replaces `doctrine:schema:update`)

Schema changes are now managed by **doctrine-migrations-bundle**, not `schema:update`.

```bash
php app/console doctrine:migrations:status          # see current vs. available
php app/console doctrine:migrations:migrate --dry-run   # preview SQL, no changes
php app/console doctrine:migrations:migrate             # apply
```

The pending migration on this branch is **`Version20260629205824`**, which adds two columns:
```sql
ALTER TABLE odr_theme_element_meta ADD show_when_empty TINYINT(1) NOT NULL;
ALTER TABLE odr_data_fields_meta   ADD editable_file_extensions VARCHAR(32) NOT NULL;
```
Both mappings have a **wide blast radius** — Doctrine selects these columns on nearly every page —
so the migration must be applied before the app will render correctly. (If you manage schema outside
migrations, the equivalent DDL is in `DEVELOP_SYNC_CHANGELIST.md`, but migrations are the supported
path.)

After migrating, **flush cached entities** so stale hydrations pick up the new columns
(`odr_cache:flush` clears all memcached entries — note the underscore namespace):
```bash
php app/console odr_cache:flush
```

> First run on a DB that already matches the schema? If `doctrine:migrations:status` shows the
> migration as *not executed* but the columns already exist, mark it executed instead of re-running.
> doctrine-migrations 3.x identifies a migration by its **fully-qualified class name** (the
> `DoctrineMigrations\` namespace prefix is required — the bare `Version…` fails with
> *"Could not find migration version"*), and the backslash must be quoted for the shell:
> ```bash
> php app/console doctrine:migrations:version 'DoctrineMigrations\Version20260629205824' --add --no-interaction
> ```

---

## 7. Re-register render plugins (upgrading an older database)

ODR stores each render plugin's category (`plugin_type`) in the `odr_render_plugin` **database**
table, populated from the plugin's YAML config at *install* time — not read on every request. If the
database predates a change to a plugin's category, its stored `plugin_type` is stale and the plugin
renders incorrectly.

The common case on this branch: the **File Header Inserter**, **File Renamer**, and **RRUFF File
Header Inserter** plugins were moved into the `datafield_header` category (type 5), but an older DB
still has them as `datafield` (type 3). With the wrong type, these *header* plugins are executed as
*datafield* plugins and their icon **replaces the whole file/image field** — you see only the plugin
icon, with the filenames, download/edit icons, and upload area missing.

Re-register the plugins so the DB matches the YAML:

1. Open **`/admin/plugins/list`** (Admin → Render Plugins). Any plugin whose stored config differs
   from its YAML shows an **Update** action.
2. Click **Update** on each flagged plugin (at minimum the three above).
3. Flush caches so the cached datatype arrays rebuild with the corrected type:
   ```bash
   php app/console odr_cache:flush
   ```

Equivalent direct SQL, if you prefer (matches the Update action for these three):
```sql
UPDATE odr_render_plugin
   SET plugin_type = 5   -- 5 = datafield_header (was 3 = datafield)
 WHERE plugin_class_name IN (
   'odr_plugins.base.file_header_inserter',
   'odr_plugins.base.file_renamer',
   'odr_plugins.rruff.file_header_inserter'
 );
```
followed by `php app/console odr_cache:flush`.

> The plugin manager needs the SF7 fix that lets ODR controllers reach render-plugin services by id
> (they sit behind a restricted service locator otherwise). Without it, `/admin/plugins/list` 500s
> with *"...is missing its config file on the server"* — make sure your checkout includes that fix
> before relying on the Update action; older ones require the SQL path above.

---

## 8. Cache, assets, and permissions (new `var/` layout)

Symfony 7 moved cache and logs from `app/cache` + `app/logs` to **`var/cache` + `var/log`**.

```bash
php app/console cache:clear --env=prod
php app/console cache:clear --env=dev      # skip on prod-only boxes
php app/console assets:install web/        # publish bundle assets
sudo service memcached restart             # drop stale sessions/metadata

# ACLs for the web-server user — updated for the var/ layout:
./set_cache_permissions.sh
```
`set_cache_permissions.sh` now creates/ACLs `var/cache`, `var/cache/{dev,prod}`, `var/log`, and
`app/tmp` (it used to target `app/cache`/`app/logs`).

Confirm both environments boot:
```bash
php app/console cache:clear --env=prod && echo "prod OK"
php app/console debug:router | grep odr_api_set_record   # new API routes should appear
```

### ⚠ Linked / WordPress-integrated instances: clear the INSTANCE cache, not the source

A linked instance keeps its own `app/config`, `var/cache`, and `var/log`, selected at runtime by the
`ODR_APP_DIR` constant. **The web front controllers (`page-odr.php`, `web/app.php`) define it from the
request path; `app/console` cannot infer it.** So a plain `php app/console cache:clear` resolves
`getProjectDir()` to the **shared source tree** and rebuilds *that* container from the *source* config —
it never refreshes the instance's compiled container (and if the instance's `var/` is symlinked to the
source, it actively clobbers the good instance-built container with a source-built one). Symptom: you
edit an instance's `config.yml`, clear cache, and the change *still* doesn't take effect.

`app/console` resolves the target instance in this order (first match wins), printing
`[console] targeting instance: …` whenever it lands on one:

1. **Just `cd` into the site** — the everyday path. The console auto-detects the instance from your
   working directory (walks up looking for a tree with its own `app/config/config.yml` that isn't
   this source checkout). No env var, no flags:
   ```bash
   cd /home/rruff/data-publisher
   php /path/to/source/app/console cache:clear --env=prod     # targets /home/rruff/data-publisher
   ```
2. **Env override** (scripts / cron / ambiguous CWD):
   ```bash
   ODR_INSTANCE=/home/rruff/data-publisher php app/console cache:clear --env=prod   # instance root
   ODR_APP_DIR=/home/rruff/data-publisher/app php app/console ...                   # raw app dir
   ```

Running from the **source tree** (or anywhere with no instance above the CWD) leaves the console on the
source tree as before — behaviour there is unchanged.

**All linked sites at once** — `app/console-all` fans a single command across every instance listed in
`app/config/instances.list` (copy `instances.list.dist`, one instance root per line). It `cd`s into
each and reuses the auto-detection above, continues past a failing site, and exits non-zero if any
failed:
```bash
cp app/config/instances.list.dist app/config/instances.list   # once; then add your roots
app/console-all cache:clear --env=prod
app/console-all doctrine:migrations:migrate --no-interaction
```

Use one of these for **every** per-instance command — `cache:clear`, `doctrine:migrations:*`,
`assets:install`, plugin (re)registration. To force a clean rebuild you can also just delete the
instance's compiled container: `rm -rf /home/rruff/data-publisher/var/cache/prod` and let the next web
request rebuild it. Check which tree a path resolves to with
`readlink -f /home/rruff/data-publisher/var/cache`.

---

## 9. Background services (Node daemons)

```bash
cd background_services
npm install
cd ..
```
Restart the ODR workers/daemons however you run them (systemd units, `start_jobs.sh`, etc.):
job workers, `graph_renderer_daemon.js`, `static_render_daemon.js`, `seed_elastic_record_daemon.js`.

- Puppeteer/Chromium: on ARM64 or snap-confined boxes set `PUPPETEER_EXECUTABLE_PATH` (or install a
  Playwright/system Chromium) — the daemons auto-discover via `findChromiumExecutable()`.
- Self-signed dev cert: the static-render daemon honors `STATIC_RENDER_IGNORE_HTTPS_ERRORS=1`; the
  graph daemon honors `ODR_CHROME_IGNORE_CERT=1`. **Never set these in production.**
- The static-render daemon reads `background_services/.env` (see `background_services/.env.example`)
  for `ODR_API_USERNAME` / `ODR_API_PASSWORD`.

---

## 10. Post-upgrade verification

Smoke-test the request paths:

```bash
# App boots + key routes resolve
php app/console cache:clear --env=prod
php app/console debug:router >/dev/null && echo "router OK"
php app/console lint:twig src/ app/Resources/   # expect only the known pre-existing filter warnings
```

Then in a browser / via curl, exercise:
- Login → `/admin` → a datatype landing page → **Display** a record → **Edit** render → **Search**.
- **Per-mode JWT** (this is the item most likely to be misconfigured after §5): request a token from
  each of `/api/v5/token`, `/api/v4/token`, `/api/v3/token` — and, for WordPress-integrated installs,
  their `/odr`, `/odr_rruff`, `/odr_data` prefixed variants — and confirm each returns a JWT (not a
  404/redirect). Then call an authenticated endpoint with the token.
- File/image download, CSV export, and (if used) Elasticsearch seeding + the static-render/sitemap
  endpoints.
- **`intl` sanity:** `php -r 'var_dump(class_exists("Collator"));'` returns `true` on the prod box.

Run the PHPUnit suite once you consider the instance feature-complete (see the test config in
`app/phpunit.xml.dist`).

---

## 11. Why `regenerate_and_update.sh` is retired

The old script does two things that **break on this stack**:

- `doctrine:generate:entities ODR` — entity code generation was **removed** in modern Doctrine; ODR's
  entities are hand-maintained against their `*.orm.yml` mappings. There is nothing to regenerate.
- `doctrine:schema:update --force` — direct schema syncing is discouraged and error-prone; schema is
  now versioned through **doctrine-migrations** ([§6](#6-database-migrations-replaces-doctrineschemaupdate)).

It also cleared `app/cache/{dev,prod}`, which no longer exist (cache is under `var/`). Use the steps
in this document instead. For a routine **code-only** redeploy on an already-upgraded box, the minimal
loop is:

```bash
git pull --ff-only
php composer.phar install --no-interaction
php app/console doctrine:migrations:migrate --no-interaction
php app/console cache:clear --env=prod
php app/console odr_cache:flush
# restart background_services daemons if any of them changed
```

---

## 12. Rollback

```bash
git checkout <previous-commit>
php composer.phar install --no-interaction
# revert the DB if migrations were applied:
php app/console doctrine:migrations:migrate prev --no-interaction   # or: mysql < odr_backup_YYYY-MM-DD.sql
# restore active config from your §0 backups if you changed it
php app/console cache:clear --env=prod
```
The one migration on this branch is reversible (its `down()` drops the two added columns).

---

*See `DEVELOP_SYNC_LOG.md` for the full per-commit sync ledger and `DEVELOP_SYNC_CHANGELIST.md` for
the authoritative list of environment changes and deferred operator tasks.*
