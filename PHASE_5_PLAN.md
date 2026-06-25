# Phase 5 Plan — Symfony 5.4 → 6.4 (LTS path)

Code-grounded plan for upgrading ODR from Symfony **5.4.53** to **6.4 LTS**. Companion to
[`UPGRADE_PLAN.md`](UPGRADE_PLAN.md); mirrors the structure of [`PHASE_4_PLAN.md`](PHASE_4_PLAN.md).

## Entry state (verified 2026-06-25)
- Symfony **5.4.53**, Twig **3.27**, PHP **8.3.31**. App boots, serves requests, error pages render.
- All **56 `odr_*` console commands** work (the `ContainerAwareCommand` shim injects the full container).
- Security still runs on the **legacy** system (`anonymous` / `guard` / `form_login`) — works on 5.4,
  **removed in 6.0**.
- App depends on the **`symfony/symfony` metapackage** (`composer.json` → `"symfony/symfony": "5.4.*"`),
  which is unsupported (dev-toolbar warns *"Using symfony/symfony is NOT supported"*) and **does not
  exist for 6.0+**.

## Strategy
Symfony 5.4 supports both the metapackage→components split **and** both security systems
simultaneously. So perform every structural / auth-critical change **on 5.4 first** — where the app
already boots and changes are reversible — verify each, and only then bump the version. This isolates
the risky migrations from version-bump breakage (the same approach that made the 4.4→5.0 deprecation
prep and the 4.4 bump go smoothly). Each workstream is its own commit.

---

## 5.1 — Decompose `symfony/symfony` → individual components (on 5.4) — **PREREQUISITE**
**Why:** the metapackage is unsupported on 5.4 and absent in 6.0. Hard blocker for the bump; also
clears the dev-toolbar warning.

**How:**
- Replace `"symfony/symfony": "5.4.*"` with the explicit set of `symfony/*` packages the app uses,
  each pinned `5.4.*` (so behaviour is unchanged — this step is *only* the package split).
- Package set, derived from registered bundles + a `use Symfony\Component\*` scan:
  - Bundles: `framework-bundle`, `security-bundle`, `twig-bundle`, `web-profiler-bundle` (dev),
    `monolog-bridge`, `twig-bridge`, `doctrine-bridge`.
  - Components seen in `src/`: `console`, `config`, `dependency-injection`, `error-handler`,
    `event-dispatcher`, `finder`, `form`, `http-foundation`, `http-kernel`, `intl`, `lock`,
    `options-resolver`, `routing`, `security-core`/`security-http`/`security-csrf`, `validator`,
    `yaml`, plus framework-implied: `asset`, `expression-language`, `mime`, `property-access`,
    `property-info`, `serializer`, `string`, `translation`, `process`, `var-dumper`, `dotenv`.
  - Resolve the final/complete list during execution via `composer require` + the errors composer
    reports for any missing component; the FrameworkBundle pulls most transitively.
- `composer update`, `cache:clear`, verify boot + `GET /login` 200.

**Risk:** LOW–MED (completeness of the package list). Fully reversible via git.

---

## 5.2 — Migrate Security to the authenticator system (on 5.4) — **AUTH-CRITICAL**
Current `app/config/security.yml` uses the legacy system. Changes:
- `enable_authenticator_manager: true`.
- `encoders:` → `password_hashers:` (keep `sha512` so existing hashes validate — same as Phase 4.3).
- Remove all **4** `anonymous: true` (anonymous access is implicit in the new system; gated by
  `access_control`).
- `api` firewall: replace the lexik `guard:` JWT authenticator with the native `jwt: ~`
  (lexik-jwt **v2.21** already supports the authenticator system).
- `login0` / `login` firewalls: `json_login` + the lexik success/failure handlers carry over.
- `main` firewall: `form_login` + `remember_me` + `logout` carry over (verify renamed keys, e.g.
  `csrf_token_generator` → `enable_csrf: true` under form_login).

**Verify on 5.4 (user tests immediately):** username/password login; JWT issuance at
`/api/v5/token`; one authenticated API call; logout; password-reset still reachable.

**Risk:** HIGH (authentication). Rollback point: the 5.1 commit.

---

## 5.3 — Remove `sensio/framework-extra-bundle`
Incompatible with SF6; its features moved to core attributes. A scan found **zero** annotations in use
(`@ParamConverter` / `@Template` / `@Cache` / `@Security` / `@Route` — none; routing is YAML).
- Unregister from `AppKernel`, remove the composer dep, drop any `sensio_framework_extra` config.

**Risk:** LOW.

---

## 5.4 — swiftmailer → `symfony/mailer`
`swiftmailer` / `swiftmailer-bundle` are abandoned and **not SF6-compatible**. Scope is small —
only the password-reset flow added in Phase 4.3:
- `src/ODR/OpenRepository/UserBundle/Controller/ResettingController.php` (`Swift_Mailer` / `Swift_Message`).
- `src/ODR/OpenRepository/UserBundle/Resources/config/services.yml`.
- `swiftmailer:` config block → `framework.mailer` (DSN from existing `%mailer_*%` params).
Replace with `MailerInterface` + `Symfony\Component\Mime\Email`. Unregister `SwiftmailerBundle`.

**Verify:** a password-reset email sends (or is spooled) without error.

**Risk:** MED (email flow).

---

## 5.5 — Bump components 5.4 → 6.4 + fix fallout
- Bump every `symfony/*` to `6.4.*`; `composer update`; fix BC breaks iteratively
  (`cache:clear` loop + the in-process request smoke test from Phase 4.4).
- Watch for: declared return types on overridden methods (signature changes), removed APIs,
  service/`get()` privacy tightening, `Request`/`Response` changes, `Security` helper relocations.
- Other deps needing bumps for 6.x: `knplabs/knp-markdown-bundle` ^1.9 → ^3 (or swap to
  `twig/markdown-extra`), `php-http/*`, `doctrine/doctrine-bundle`.
- **Verify:** boot, login, JWT, datatype/record render, `odr_*` console commands, `lint:twig`.

---

## Out of scope (defer to Phase 6/7)
- Doctrine ORM 2 → 3 (if any remains), full Flex migration, annotations → attributes everywhere,
  PHPUnit 7 → 11, dropping `knp-markdown` for `twig/markdown-extra`.

## Rollback points
- **5.4 working baseline:** commit `5e6439ad`. Each workstream (5.1–5.5) is committed separately so any
  can be reverted independently.
