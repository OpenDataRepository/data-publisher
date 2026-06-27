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

_(none applied yet — no env-affecting feature ported under this policy so far)_

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
