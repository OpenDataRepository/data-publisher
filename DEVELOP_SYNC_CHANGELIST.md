# DEVELOP_SYNC_CHANGELIST — required environment changes

The develop-sync follows a **"code only + change list"** policy for commits that need
dev-environment changes (new DB columns, new config parameters):

- The **code** is ported normally — entities, `*.orm.yml` mappings, committed `*.dist` config
  templates, forms, controllers, templates.
- The **live environment is NOT touched** by the sync: neither the database nor the active
  (gitignored) `app/config/config.yml` / `app/config/parameters.yml`.
- Every required environment change is recorded **here** for the maintainer to apply via their own
  migration / deploy process.

> ⚠️ A ported feature will not fully run until its entries below are applied. Schema mappings in
> particular have a wide blast radius — e.g. adding a column to a `*Meta.orm.yml` makes Doctrine
> select that column on every hydration of that entity, so the related pages error until the column
> exists. Apply the DB section before testing schema-affecting features.

Entries are added as the corresponding env-affecting commits are ported, tagged with the Phase that
introduced them.

---

## Config / parameters (active `app/config/config.yml` + `parameters.yml`)

### Phase D6 — modify-search (develop dcba252d)
Active `app/config/parameters.yml` — add under `parameters:`:
```yaml
    odr_wordpress_search_redirects: '/odr/rruff_sample:/rruff||/odr/rruff_reference:/references||/odr/amcsd:/amcsd'
```
Active `app/config/config.yml` — add under `twig: globals:` (next to `config_odr_wordpress_integrated`):
```yaml
        config_odr_wordpress_search_redirects: '%odr_wordpress_search_redirects%'
```
A `||`-separated list of `wordpress_path:odr_path` pairs for the "Modify Search" button on
WordPress-integrated searches. The templates guard the global with `is defined and is not empty`, so
the branch runs fine without it (the button just won't appear). Adjust the value to your instance's
actual WordPress→ODR path mapping.

---

## Database (DDL)

### Phase D5 — ThemeElement "show when empty" (develop 006d0e97)
```sql
ALTER TABLE odr_theme_element_meta
    ADD show_when_empty TINYINT(1) NOT NULL DEFAULT '0';
```
⚠️ Wide blast radius — `ThemeElementMeta.orm.yml` now maps `showWhenEmpty` → `show_when_empty`, so
Doctrine selects this column on **every** hydration of a ThemeElementMeta. Until the column exists,
theme rendering / the theme designer will error. Apply this before testing, then rebuild cached
theme entries (`odr:cache:flush` or equivalent). After it's applied, the new "Always Render Group
Box" toggle in the theme designer controls it; PlugExtension's `isEmptyFilter` honors it (a
show-when-empty group box is never treated as empty).

### Phase D7 — file-extension editing (develop 26dd4715)
```sql
ALTER TABLE odr_data_fields_meta
    ADD editable_file_extensions VARCHAR(32) DEFAULT '' NOT NULL;
```
⚠️ Same wide blast radius — `DataFieldsMeta.orm.yml` maps `editable_file_extensions`, so Doctrine
selects it on **every** DataFieldsMeta hydration (i.e. almost every page). Apply before testing, then
flush caches. After it's applied: a File datafield's properties gains an "Editable File Extensions"
field (comma-separated, no dots); files whose name matches get an "Edit File Contents" pencil in the
Edit view that opens an in-browser editor (routes odr_direct_edit_file_start / _save).

---

## Phase D13d — 866664e1 (routing + WordPress-integration API security) + ad11b59d/326f8158 (IMA)

**Ported (tracked templates + app code):**
- `app/config/routing.yml.dist` — adopted develop HEAD: expanded api_login_check routes to
  v3/v4/v5 × {base, /odr, /odr_rruff, /odr_data}; standard-mode prefix defaults.
- `app/config/security.yml.dist` — replaced login0–login5 firewalls with the 12 expanded
  login_v{3,4,5}[_odr[_rruff|_data]] firewalls. **Converted to SF7 authenticator syntax**:
  develop's originals used `anonymous: true` (removed in Symfony 6) — dropped. check_path names
  now match the expanded routes. access_control token exceptions were already complete (ea49a842).
- `app/config/parameters.yml.dist` — site_baseurl WP `/odr_data` comment.
- `FacadeController` — IMA update login URL: `api_login_check` → `api_login_check_v4_odr` (×3).
- `JsonExceptionSubscriber` — ad11b59d logic ported onto the branch's SF7 version: JSON-ify ANY
  exception on `/api/v\d` requests, resolve the real HTTP status, log, stopPropagation, priority 64.

**⚠️ DEFERRED — operator action required before/at end-testing (per the 866664e1 flag: "do not
port mechanically; needs per-mode JWT testing"):**
1. **Active `app/config/routing.yml` (gitignored)** — regenerate/merge from the updated
   `routing.yml.dist` so the expanded `api_login_check_v*` routes exist at runtime. Until then, the
   ported FacadeController IMA-update path (`api_login_check_v4_odr`) will throw RouteNotFound on this
   box (WP-only code path; not exercised in standard mode).
2. **Active `app/config/security.yml` (local)** — mirror the expanded login firewalls from
   `security.yml.dist` for whichever WP mode this deployment runs.
3. **`app/config/routing_prefixed.yml` (tracked, WP-mode)** — NOT reconciled. develop HEAD re-adds
   `@FOSUserBundle` / `@FOSOAuthServerBundle` / `@HWIOAuthBundle` route imports that the SF7 upgrade
   deliberately removed (routes relocated to `@ODROpenRepositoryUser`). Porting verbatim would break
   WP-mode routing. Needs manual SF7-aware reconciliation + per-mode boot test.
4. **Per-mode JWT testing** — token issuance on base + `/odr` + `/odr_rruff` + `/odr_data` for v3/v4/v5.

Live box unaffected: active routing.yml/security.yml unchanged; prod cache:clear boots clean.
