# Symfony 4.4 → 5.0 Deprecation Cleanup — Process Guide

This document is a **repeatable playbook** for the deprecation-cleanup work that prepares ODR's
controllers/templates/config for Symfony 5.0. It records exactly what was done (and the dead-ends),
so it can be re-applied if the work is lost, diverges, or needs redoing on a branch that other people
have changed in the meantime.

> **Designed to survive codebase drift.** Every step starts with a *detection command* that
> re-scans the current code, and the transformations are *scripts that re-derive their inputs from
> grep* — they are NOT hardcoded file lists. If another coder adds a new controller or template, the
> same scripts pick it up. Re-run detection first; only run a transform on what detection reports.

**Reference commits (the canonical implementation):**

| Step | Commit | What |
|------|--------|------|
| B  | `45d98d02` | `Bundle:Controller:action` route notation → FQCN |
| A1 | `51c4d42d` | `Bundle:Folder:file.twig` template notation → `@Namespace/...` |
| A2 | `76f98284` | FrameworkBundle templating engine → `Twig\Environment` |
| A3 | `ec79c5e5` + `9c71fe3e` | custom exception controller → `framework.error_controller` (+ `.dist`) |
| C  | `65ecee19`, `99a64b22` | controllers → constructor-injected services |
| C-tail | `f074c3ac` | the direct-`AbstractController` controllers |

Earlier prerequisites already in history: `829b2840` (PHP 8.3 + rector), `6b132a3e` (Twig `spaceless`→`apply`).

---

## 0. Environment & how to measure

The whole process is **deprecation-driven**: you watch the per-request deprecation count fall to zero
(for ODR's own code).

- Run the app via **`app_dev.php`** (the dev front controller) so deprecations are logged and the web
  debug toolbar shows them. The browser is driven via the chrome-devtools MCP tools against
  `https://www.odr.io/app_dev.php/...`.
- **Console is `php app/console`** (SF3-style, pre-Flex), *not* `bin/console`.
- **`app/config/config.yml` is gitignored** — the tracked template is `app/config/config.yml.dist`.
  Any config change must be mirrored into the `.dist` file to be version-controlled/deployed.
- Deprecation logging is configured (see `config_dev.yml`) to write `var/log/dev.deprecations.log`.

**The core measurement loop:**

```bash
# 1. truncate the log
: > var/log/dev.deprecations.log
# 2. warm the container with one request (cache:clear logs everyone out and the FIRST request after
#    recompiles, emitting one-time compile-time deprecations — so warm, then truncate, then measure)
#    (navigate the browser to any page once)
: > var/log/dev.deprecations.log
# 3. load the page under test (navigate browser), then aggregate:
grep -ohE 'User Deprecated:.*?(deprecated|removed)[^.]*\.' var/log/dev.deprecations.log \
  | grep -v '\\\\' | sed -E 's/"[^"]*"/"X"/g; s/[0-9]+/N/g' | sort | uniq -c | sort -rn
```

> The log **double-logs** each entry (escaped + unescaped copy) — halve the raw counts. Filtering
> `grep -v '\\\\'` keeps one copy.

**Always after any config/services.yml change:** `php app/console cache:clear --env=dev`. This
rebuilds the container and **invalidates the security session (logs you out)** — expect to re-login.

---

## 1. Triage the deprecations into buckets

Aggregate the log (command above) and sort each distinct deprecation into one of two groups:

- **App work (must fix by hand)** — the buckets below: route notation (B), template notation +
  engine (A1/A2/A3), container auto-injection (C).
- **Auto-resolved by later upgrades (do NOT hand-fix)** — exception-listener internals, sensio-extra
  routing loaders, `AdvancedUserInterface` (FOSUser), `league/commonmark` config. These vanish when
  the relevant bundle/framework is bumped (Phase 4 / Phase 6), so chasing them now is wasted effort.

Rule of thumb that held here: of ~400 per-request deprecations, only **B, A1/A2/A3, and C** were
real hand-work; everything else was a side effect of bundles already slated for removal.

---

## 2. Bucket B — route `_controller` notation → FQCN

**Deprecation:** `Referencing controllers with Bundle:Controller:action is deprecated since 4.1`
(removed in 5.0).

**Detect (current scope):**
```bash
# distinct controllers referenced via the old notation:
grep -rhoE '_controller: [A-Za-z]+Bundle:[A-Za-z]+:[A-Za-z0-9_]+' \
  $(grep -rlE '_controller:' src --include='*.yml') | sort -u
# files involved:
grep -rlE '_controller: [A-Za-z]+Bundle:' src --include='*.yml'
```

**Transform:** `Bundle:Controller:action` → `Namespace\Controller\ControllerController::actionAction`.
- Bundle short-name → namespace: read each bundle class (`grep -rhE 'class ODR.*Bundle extends'`) and
  its `namespace` line. (e.g. `ODRAdminBundle` → `ODR\AdminBundle`,
  `ODROpenRepositorySearchBundle` → `ODR\OpenRepository\SearchBundle`.)
- Controller short-name `Foo` → `FooController`; action `bar` → `barAction`.

The converter (`.upgrade_convert_routes.py` in the commit) builds a `BUNDLE_NS` map, regex-replaces
`_controller:\s*([A-Za-z]+Bundle):([A-Za-z0-9_]+):([A-Za-z0-9_]+)`, and **validates every produced
controller class file exists on disk** before writing. Also check `forward()` / Twig
`render(controller())` for the same notation (there were none here).

**Verify:**
```bash
php app/console cache:clear --env=dev
php app/console debug:router | grep -c ANY        # route count, must not drop
php app/console debug:router odr_search           # spot-check a converted route resolves to its FQCN
```
Then load a page and confirm `grep -c 'Referencing controllers' var/log/dev.deprecations.log` == 0.

---

## 3. Bucket A1 — template notation → `@Namespace/...`

**Why:** the `Bundle:Folder:name.twig` notation uses the deprecated FrameworkBundle templating
component and is **rejected by `Twig\Environment`** (needed in A2). The *old* `templating` engine
still accepts `@`-notation, so A1 is safe to land on its own.

**Detect:**
```bash
# PHP render() args + Twig include/extends/embed/from/import/use:
grep -rhoE "[A-Za-z]+Bundle:[A-Za-z0-9_/]*:[A-Za-z0-9_.\-]+\.twig" src --include='*.php' --include='*.twig' | sort -u
```

**Transform rule:** strip trailing `Bundle`, prepend `@`. Two forms:
- `ODRAdminBundle:Default:index.html.twig` → `@ODRAdmin/Default/index.html.twig`
- `ODRAdminBundle::navigation.html.twig` (empty folder) → `@ODRAdmin/navigation.html.twig`

Confirm the real namespace names with `php app/console debug:twig` (they match the rule, incl.
`@FOSUser`, `@ODROpenRepositorySearch`, etc.). The converter regex is
`([A-Za-z][A-Za-z0-9_]*)Bundle:([A-Za-z0-9_/]*):([A-Za-z0-9_.\-]+\.twig)` (requiring the `.twig`
suffix keeps it from touching route notation). It processes both `.php` and `.twig`.

**Verify:** `php app/console lint:twig src app/Resources` (pre-existing errors here: 5 mock fixtures
with custom filters `date_now`/`mock_internal_id`, a stray `.` in a Metadata Person plugin template,
and removed `form_enctype()` in FOSUser `reset_content.twig` — all unrelated, leave them). Then
`cache:clear`, load a page, confirm it still renders. Deprecation count is *unchanged* by A1 — the
templating service is still alive; A2 is what clears it.

---

## 4. Bucket A2 — templating engine → `Twig\Environment`

**Deprecations:** `templating`, `templating.name_parser`, `templating.locator`, `TwigEngine`,
`EngineInterface`, `TemplateNameParser`, `TemplateLocator`, `TemplateReference`, `FilesystemLoader`.

**Detect:**
```bash
grep -rln 'use [A-Za-z\\]*Templating\\EngineInterface;' src --include='*.php' | wc -l   # stale imports
grep -rnE "get\(\s*['\"]templating['\"]\s*\)" src --include='*.php' | wc -l              # service fetches
grep -rhoE "get\('templating'\)->[a-zA-Z]+|\\\$templating->[a-zA-Z]+" src --include='*.php' | sort -u
```
The only method ever called on the engine here was `->render()`, whose signature is identical on
`Twig\Environment` — so the swap is behaviour-preserving once A1 made all template names `@`-notation.

**Transform (`.upgrade_a2.py` in the commit):**
- `get('templating')` → `get('twig')` (every call site).
- Remove now-unused `use ...Templating\EngineInterface;` imports; retarget any `@var TwigEngine`/
  `@param ...EngineInterface` docblocks to `\Twig\Environment`.
- Constructor type-hints were already `\Twig\Environment` from the rector pass; if any remain as
  `EngineInterface`, change them too.

**The `framework.templating` config stays** (with a comment) because **FOSOAuthServerBundle's authorize
controller still type-hints the old `EngineInterface`**. It's lazy and no longer instantiated on
normal ODR requests; remove it together with FOSOAuthServer in Phase 4.

**Verify:** `php -l` every changed file; confirm `grep -rE "get\('templating'\)" src` == 0; cache:clear;
load a page (login renders). Mirror nothing here (no `.dist` change in A2 itself).

---

## 5. Bucket A3 — custom exception controller → `framework.error_controller`

**Deprecation:** `twig.exception_listener` / `twig.exception_controller` (removed 5.0). It was the
*last per-request consumer of the templating engine* (it eagerly instantiated `templating` to render
error pages).

**Changes** (`src/ODR/AdminBundle/Controller/ODRExceptionController.php`):
- Stop `extends Symfony\Bundle\TwigBundle\Controller\ExceptionController`; make it a plain class.
- Inline the three helper methods it used from that parent: `findTemplate()`, `templateExists()`,
  `getAndCleanOutputBuffering()`.
- Switch the exception type-hint to `Symfony\Component\ErrorHandler\Exception\FlattenException`
  (the `Symfony\Component\Debug\...` namespace is deprecated). The new `ErrorListener` injects a
  `FlattenException` argument by type, so the `showAction(Request, FlattenException, ?DebugLoggerInterface)`
  signature keeps working.

**Config** (apply to BOTH `app/config/config.yml` and `app/config/config.yml.dist`):
```yaml
framework:
    error_controller: 'ODR.exception_controller::showAction'
twig:
    exception_controller: ~        # GOTCHA: must be null. Omitting it falls back to the default and
                                   # the deprecated listener stays registered.
```

**Verify:** trigger a 404 (any bogus URL — no login needed). It should render ODR's custom error
(HTTP 404, no 500, no `FlattenException` namespace error), and `twig.exception_listener` should no
longer appear in deprecation traces. Remaining templating deprecations now come only from
FOSUser/FOSOAuthServer controllers (Phase 4).

---

## 6. Bucket C — controllers → constructor-injected services (the big one)

**Deprecation:** `Auto-injection of the container for "...Controller" is deprecated since 4.2`
(removed in 5.0). Fires for any `AbstractController` that is **instantiated by the resolver rather
than fetched as a service**. ODR controllers extend `ODRCustomController extends AbstractController`
and were never services, so they all auto-inject.

### 6.1 The dead-ends (do not repeat these)

In this **pre-Flex** app, the idiomatic fixes do **not** work:
- `autoconfigure: true` on the controller service tags it but **does not wire `setContainer`** — the
  compiled factory has no `setContainer` call, so `getParameter()`/helpers throw "missing a parameter
  bag". (`registerForAutoconfiguration(AbstractController)` only adds the `controller.service_arguments`
  tag in FrameworkBundle.)
- `parent:` (for DRY args) + `autoconfigure:` is rejected by Symfony outright.
- Injecting `@service_container` doesn't fix helpers, because `parameter_bag`/`security.token_storage`/
  `twig` are **private** — `$container->has()` returns false for them.

### 6.2 The working pattern

1. **`ODRCustomController` base** keeps `extends AbstractController` and gets a constructor injecting
   its own `odr.*` services as **`protected readonly`** promoted props (so subclasses inherit them).
   Property name = service id minus `odr.`. Replace its `get('odr.x')`→`$this->x`; its `get('session'|'twig')`
   → `$this->container->get(...)` (locator); its `$this->container->getParameter(...)` →
   `$this->getParameter(...)` (the AbstractController method, which uses the locator's `parameter_bag`).
   **Leave `getDoctrine()`/`generateUrl()`/`render()`/`getParameter()` alone** — they work via the locator.

2. **One shared service locator** in AdminBundle `services.yml`, holding exactly the services
   `AbstractController::getSubscribedServices()` needs that exist here, **plus** the extra framework/
   vendor services controllers fetch directly:
   ```yaml
   odr.controller_locator:
       class: Symfony\Component\DependencyInjection\ServiceLocator
       tags: ['container.service_locator']
       arguments:
           - { router: '@router', request_stack: '@request_stack', http_kernel: '@http_kernel',
               session: '@session', security.authorization_checker: '@security.authorization_checker',
               twig: '@twig', doctrine: '@doctrine', form.factory: '@form.factory',
               security.token_storage: '@security.token_storage',
               security.csrf.token_manager: '@security.csrf.token_manager', parameter_bag: '@parameter_bag',
               event_dispatcher: '@event_dispatcher', logger: '@logger', pheanstalk: '@pheanstalk',
               fos_user.user_manager: '@fos_user.user_manager',
               fos_user.util.token_generator: '@fos_user.util.token_generator',
               snc_redis.default: '@snc_redis.default',
               hwi_oauth.security.oauth_utils: '@hwi_oauth.security.oauth_utils',
               security.authentication_utils: '@security.authentication_utils',
               fos_oauth_server.client_manager.default: '@fos_oauth_server.client_manager.default',
               odr_custom_controller: '@odr_custom_controller', ... }
   ```
   (Re-derive this list from detection — see 6.4 — and verify each id with
   `php app/console debug:container <id>`.)

3. **The 12 base args as a YAML anchor** `&odr_base_args` on an abstract `odr.controller_base` service
   (same file — anchors are file-scoped). **Hybrid scope decision:** only `odr.*` *domain* services go
   through the constructor; framework/vendor services stay on the locator (pure injection of Symfony
   internals = ~500 extra edits, `security.token_storage` alone is 367 uses, for no benefit).

4. **Each controller** is registered as a service:
   ```yaml
   ODR\AdminBundle\Controller\FooController:
       public: true
       arguments:                       # use `arguments: *odr_base_args` if no extras
           <<: *odr_base_args
           $entity_creation_service: '@odr.entity_creation_service'   # its own extras
       calls:
           - [setContainer, ['@odr.controller_locator']]   # explicit setContainer = no auto-injection deprecation
       tags: ['controller.service_arguments']
   ```
   **Put ALL controller service defs in AdminBundle `services.yml`** (where the anchor lives), even for
   controllers in other bundles — the `@odr.controller_locator` ref is global, but the `*odr_base_args`
   anchor is not.

5. **In each subclass**: replace `get('odr.x')`/`container->get('odr.x')` → `$this->x` (base-12 use the
   inherited prop; extras are injected into the subclass constructor, which **forwards the 12 base
   services to `parent::__construct()`** and adds its own as promoted props). Normalize framework
   `$this->get('x')` → `$this->container->get('x')`. `container->getParameter` → `getParameter`.

### 6.3 Edge cases that bit us

- **Two `LinkController`s** (AdminBundle + OAuthClientBundle) — a basename-based skip list hits both.
  Key your skip/include logic on the full path.
- **`OAuthClientBundle\LinkController`** uses *dynamic* ids (`get('hwi_oauth.resource_owner.'.$name)`)
  that can't live in a locator → give it `setContainer(['@service_container'])` (full container). It's
  a Phase-4 bundle anyway.
- **`XSDController`** referenced a **non-existent** service `odr.datatype_info_service` (dead code behind
  `throw ODRNotImplementedException()`). Don't try to inject services that don't exist — verify each
  with `debug:container` and leave dead refs as `container->get(...)`.
- **Dotted `odr.*` ids** (`odr.jupyterhub_bridge.username_service`, `odr.oauth_server.client_manager`)
  don't make clean property names — kept them on the locator instead.
- **`odr_custom_controller`** is the base registered as a service (SearchBundle/Default fetches it to
  reuse utility methods). Now that the base has a constructor, give that service its 12 args.

### 6.4 The codegen approach (re-runnable)

Build a `service-id → FQCN` map by scanning the bundles' `services.yml` line-by-line (a service id is a
4-space-indented `name:`; capture the following `class:`), and hardcode the framework/vendor types.
Then for each controller (re-derived from `grep -rl 'extends ODRCustomController'`):

1. Find its `get()` ids (`\$this->(?:container->)?get\(\s*['"]([\w.]+)['"]\s*\)`).
2. Partition odr-base-12 vs odr-extras vs framework.
3. Rewrite call sites; build the forwarding constructor + imports for extras.
4. Emit the `services.yml` block.

> **Regex gotcha:** FQCNs contain `\`, which `re.sub` treats as escape sequences in the *replacement*
> string — use a **function/lambda replacement**, not a string, when inserting `use ...;` lines.

The actual scripts (`.upgrade_c_*.py`) live in commits `99a64b22` / `f074c3ac` if you need to diff;
they were scratch files, so reconstruct from this spec against the *current* code rather than reusing
verbatim.

### 6.5 Verify C

```bash
# every changed controller parses:
for f in $(grep -rl 'extends ODRCustomController' src --include='*Controller.php') \
         src/ODR/AdminBundle/Controller/ODRCustomController.php; do php -l "$f"; done
php app/console lint:yaml src/ODR/AdminBundle/Resources/config/services.yml
php app/console cache:clear --env=dev    # MUST build with no "non-existent service" errors
# no leftover odr gets (except intentional dotted/dead-code):
grep -rhoE "get\('odr\.[\w.]+'\)" $(grep -rl 'extends ODRCustomController' src --include='*Controller.php') | sort | uniq -c
```
Then in the browser (logged in) exercise **one controller from each category** — e.g. database list
(DatatypeController), `/admin` (DefaultController), `/view/{id}` (DisplayController), a search slug
(SearchBundle/DefaultController). For each: confirm it renders and
`grep -c 'Auto-injection' var/log/dev.deprecations.log` == 0.

---

## 7. Bucket C-tail — direct-`AbstractController` controllers

The few controllers that extend `AbstractController` **directly** (not `ODRCustomController`) are NOT
affected by the base constructor, so they can be done as a separate batch (the app works without them,
they just keep auto-injecting until converted).

**Detect:** `grep -rlE 'class \w+Controller extends.*AbstractController' src --include='*Controller.php' | grep -v ODRCustomController`

Same pattern as C but **no base-12 forwarding** — each just injects its own `odr.*` services as
promoted props (no `parent::__construct`) and gets `setContainer(['@odr.controller_locator'])`. Add any
framework/vendor services they use that aren't already in the locator. A controller that uses *no*
services still needs registering (with just `calls: setContainer` + tag) to drop the deprecation.

---

## 8. Re-running on a changed codebase — checklist

If other coders have modified things since, **do not blindly replay**. Instead:

1. **Re-baseline:** confirm you're on Symfony 4.4 (`php app/console --version`), the app boots, and
   capture a fresh deprecation snapshot per §0.
2. For each bucket, **run the detection command first.** If it returns matches, the bucket is (partly)
   undone or has new cases — re-run that bucket's transform; the scripts re-derive from grep so new
   files are handled automatically.
3. **New controllers** added by others: `grep -rl 'extends ODRCustomController'` and the direct-
   `AbstractController` detection will list them. New ones won't have service defs → they'll throw the
   auto-injection deprecation (and, once the base has a constructor, an `ArgumentCountError`). Run the
   C codegen over the full current list and re-generate ALL service defs (the anchor makes this cheap).
4. **New services** fetched via `get()`: rebuild the `service-id→class` map from the current
   `services.yml` files; any id missing from the map shows up as a codegen warning — add it to the map
   or the locator.
5. **Always** finish with: `cache:clear` (clean build), `php -l` sweep, `lint:yaml`, and the
   browser/deprecation verification in §6.5.
6. Mirror any `config.yml` change into `config.yml.dist`.

---

## 9. Known-unrelated issues (don't get distracted)

- **`league/commonmark`**: `convertToHtml(): ...null given` on pages that render markdown. This is the
  `commonmark ^0.18` upgrade (a separate bucket), not DI.
- **Pre-existing template bugs** left untouched: stray `.` in a Metadata Person plugin twig; removed
  `form_enctype()` in FOSUser `reset_content.twig`; `odr.datatype_info_service` dead reference.
- **FOSUser / FOSOAuthServer / sensio-extra** deprecations remain by design — they disappear with the
  **Phase 4** bundle removals (see `UPGRADE_PLAN.md`), which is the real next milestone toward bumping
  the framework to 5.4.
