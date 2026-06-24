# Phase 4 Plan — Remove the Symfony-5-incompatible bundles

Detailed, code-grounded plan for Phase 4 of the [`UPGRADE_PLAN.md`](UPGRADE_PLAN.md) (Symfony 4.4 → 5.4).
Phase 4 is the **gate** before bumping the framework: FOSUserBundle, FOSOAuthServerBundle and
HWIOAuthBundle are incompatible with Symfony 5 and must go first.

## Findings that de-risk this phase (scanned 2026-06-24)

- **OAuth *server* is unused** — `fos_client`, `fos_access_token`, `fos_auth_code`,
  `fos_authorized_clients` all have **0 rows**. → FOSOAuthServerBundle is a **removal**, not a
  `league/oauth2-server` rewrite.
- **GitHub/Google login is unconfigured** — HWIOAuth `resource_owners` are all commented out in
  `config.yml`; `fos_user_link_oauth` has 0 rows. → HWIOAuthBundle / OAuthClientBundle is a **removal**.
- **sensio/framework-extra-bundle** — 0 `@Route`/`@ParamConverter` annotations in code (routing is all
  YAML). Already effectively clear.
- **FOSUserBundle is the only real migration** — **271 users** in `fos_user`; `fos_user.user_manager`
  is used **×50**, `fos_user.util.token_generator` ×11.

**Gate:** do not raise `symfony/symfony` to `5.4.*` until all three bundles are removed — they will
block `composer update` and fatal at runtime.

## Recommended sequence (safest → hardest)

### 4.1 — Remove FOSOAuthServerBundle (unused OAuth server)
Smallest, self-contained, and already broken on Doctrine 3 (its `AuthCodeManager`/`ClientManager`
type-hint the removed `Doctrine\Common\Persistence\ObjectManager`), so removal also clears live errors.
- **Code:** delete the 11 `src/ODR/OpenRepository/OAuthServerBundle/` files; remove its `AppKernel`
  registration; remove the `oauth_token` + `oauth_authorize` firewalls from `security.yml`(+`.dist`);
  remove the `/oauth/v2/*` routes; remove its `composer.json` require; remove refs from AdminBundle
  `services.yml` (and the locator entry `odr.oauth_server.client_manager`).
- **DB coupling:** remove the `User::$clients` ManyToMany (→ `OAuthServerBundle\Entity\Client`); drop
  the orphaned (empty) `fos_client` / `fos_access_token` / `fos_auth_code` / `fos_authorized_clients`
  tables.
- **Verify:** app boots; form + JWT login still work; `/oauth/v2/*` 404s; no dangling service refs.

### 4.2 — Remove HWIOAuthBundle + OAuthClientBundle (dormant social login)
- **Code:** delete the 4 `OAuthClientBundle` files; remove the `main` firewall `oauth:` block +
  `oauth_user_provider`; remove the `hwi_oauth:` config; remove the `/connect/*` + `/login/check-github`
  routes; remove the composer requires (`hwi/oauth-bundle`, `php-http/*` if only used by it).
- **DB coupling:** remove the `User::$userLink` OneToMany (→ `OAuthClientBundle\Entity\UserLink`); drop
  the empty `fos_user_link_oauth` table.
- **Verify:** form login works; `/connect/*` gone.

### 4.3 — Replace FOSUserBundle (the core effort)
- **User entity** (`UserBundle/Entity/User.php`): drop `extends BaseUser`; implement `UserInterface` +
  `PasswordAuthenticatedUserInterface` (+ `LegacyPasswordAuthenticatedUserInterface`, since hashing is
  **sha512 + salt**). Keep the `fos_user` table and all custom fields; add `getUserIdentifier()`,
  `getRoles()`, `getPassword()`, `getSalt()`, `eraseCredentials()`.
- **Security config:** `providers: fos_userbundle` → native **entity** provider; `encoders:` →
  `password_hashers:` (keep `sha512` so the 271 existing hashes validate — optionally migrate-on-login
  to `auto`); `anonymous: true` → remove (lazy default in 5.x).
- **UserManager:** write a small ODR `UserManager` covering the methods actually used — `findUserBy`
  (×30), `updateUser` (×13; must hash `plainPassword` via the new hasher), `findUsers` (×8),
  `findUserByEmail` (×8), `findUserByUsernameOrEmail` (×2), `createUser` (×2) — and **alias it to the
  `fos_user.user_manager` service id** so all 50 call sites stay untouched. Same trick for
  `fos_user.util.token_generator` (generateToken/getToken).
- **Login:** native `SecurityController` (login / login_check / logout) reusing the existing
  `UserBundle/Resources/views/Security/login.html.twig`.
- **Password reset:** replace FOSUser `ResettingController` + `ChangePasswordListener` /
  `PasswordResettingListener` with **`symfonycasts/reset-password-bundle`**.
- **Registration:** already custom (`ODRUserController`); it goes through the aliased `user_manager`, so
  no change.
- **Verify:** form login; JWT login (`/api/v{3,5}/token`); password-reset email flow; user creation; and
  confirm **existing sha512 passwords still authenticate**.

### 4.4 — Bump `symfony/symfony` → 5.4 and fix fallout
- composer: `5.4.*`; drop `sensio/framework-extra-bundle`; update doctrine-bundle / snc-redis / lexik
  (its `guard` JWT authenticator → the new authenticator system).
- Resolve 5.0-removed APIs that surface; re-run the deprecation sweep from
  [`SYMFONY_5_DEPRECATION_CLEANUP.md`](SYMFONY_5_DEPRECATION_CLEANUP.md).

## Risks & guardrails
- **Auth is critical** — do 4.3 on the upgrade branch and test login/reset against **real sha512
  hashes** before merging; a hashing mistake locks out all 271 users.
- **Keep the `fos_user.user_manager` service id** via alias — that's what keeps 4.3 from becoming a
  50-call-site edit.
- **Order matters:** 4.1 and 4.2 untangle the `User` OAuth relations *before* 4.3 reshapes the `User`
  entity; the framework bump (4.4) is last.
- Net shape: **two removals and one real auth migration**, not three replacements.
