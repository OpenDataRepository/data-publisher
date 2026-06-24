# Symfony 3.4 → 7.4 LTS Upgrade Plan
## Open Data Repository — Data Publisher

**Current:** Symfony 3.4, PHP 7.3, Doctrine ORM 2.6
**Target:** Symfony 7.4 LTS, PHP 8.3, Doctrine ORM 3.x
**Upgrade Strategy:** Incremental stepping (3.4 → 4.4 → 5.4 → 6.4 → 7.4)

> **Why incremental?** Jumping multiple major versions at once makes debugging impossible. Each LTS version has deprecation warnings that guide you to the next step. Fix deprecations at each step before moving forward.

> **Companion docs:** [`SYMFONY_5_DEPRECATION_CLEANUP.md`](SYMFONY_5_DEPRECATION_CLEANUP.md) — repeatable
> playbook for the 4.4 → 5.0 deprecation cleanup (route/template/templating/exception-controller/
> controller-DI work, already done; see Phase 4). [`TEST_URLS.md`](TEST_URLS.md) — regression URL list.

---

## Phase 0: Pre-Upgrade Baseline (Do This First)

### 0.1 — Create Test Baseline

Before touching any code, establish a complete test baseline to verify the site works after each upgrade step.

**Screenshot baseline** — capture screenshots of all key URLs (see `TEST_URLS.md`)
**Functional tests** — run existing PHPUnit suite and fix any failing tests
**API tests** — run all API controller tests against production-equivalent environment

### 0.2 — Environment Audit

```bash
# Check current versions
php --version                          # Should be 7.3.x
php bin/console --version             # Should be 3.4.x
php bin/console doctrine:schema:validate
php bin/console debug:router          # List all routes
php bin/console debug:container       # List all services
```

### 0.3 — Dependency Audit

```bash
composer outdated
composer audit  # Check for security vulnerabilities
```

### 0.4 — Enable Deprecation Warnings

In `app/config/config_dev.yml`, ensure deprecation logging is active:
```yaml
monolog:
    handlers:
        deprecation:
            type: stream
            path: "%kernel.logs_dir%/%kernel.environment%.deprecations.log"
            channels: [php]
```

Run the app and collect `dev.deprecations.log` — this is your migration checklist.

---

## Phase 1: Baseline Testing Infrastructure

> **Goal:** Create comprehensive tests and screenshots BEFORE any upgrade work begins. These become the regression suite for every subsequent phase.

### 1.1 — Screenshot Baseline (Playwright)

Install Playwright and capture screenshots of all routes listed in `TEST_URLS.md`.

```bash
cd tests/screenshots
npm init -y
npm install @playwright/test
npx playwright install chromium
```

**Screenshot test structure:**
```
tests/
├── screenshots/
│   ├── playwright.config.ts
│   ├── baseline/           # Pre-upgrade reference screenshots
│   └── specs/
│       ├── public.spec.ts  # Public-facing pages
│       ├── auth.spec.ts    # Login/auth flows
│       ├── admin.spec.ts   # Admin interface
│       └── api.spec.ts     # API responses
```

### 1.2 — PHPUnit Test Suite Expansion

Expand tests to cover all controllers. Target test files:

**AdminBundle:**
- `Tests/Controller/DefaultControllerTest.php` ✅ exists
- `Tests/Controller/APIControllerTest.php` ✅ exists
- `Tests/Controller/EditControllerTest.php` — create
- `Tests/Controller/DisplayControllerTest.php` — create
- `Tests/Controller/DatatypeControllerTest.php` — create
- `Tests/Controller/CSVImportControllerTest.php` — create
- `Tests/Controller/SearchAPITest.php` — create

**API Tests (all versions):**
- `Tests/Api/V3ApiTest.php` — JWT auth + CRUD via `/api/v3/*`
- `Tests/Api/V4ApiTest.php` — dataset operations via `/api/v4/*`
- `Tests/Api/V5ApiTest.php` — JSON-LD export via `/api/v5/*`

**Auth Tests:**
- `Tests/Auth/LoginTest.php` — form login flow
- `Tests/Auth/OAuthTest.php` — OAuth2 token flow
- `Tests/Auth/JwtTest.php` — JWT token generation and validation

### 1.3 — API Contract Tests

Create a set of API contract tests that verify:
- Response structure (JSON keys, types)
- HTTP status codes
- Authentication behavior (401 without token, 403 with insufficient permissions)
- Pagination parameters
- Error response format

These tests are framework-agnostic and should pass identically before and after the upgrade.

---

## Phase 2: PHP Upgrade (7.3 → 8.3)

> Symfony 7.4 requires PHP 8.2+. This can be done independently of the Symfony upgrade.

### 2.1 — PHP 8.0 Compatibility

**Breaking changes to address:**
- `str_replace()`, `strpos()`, etc. — return types changed
- `match` is now a reserved keyword — audit codebase for variables named `$match`
- Named arguments — ensure no positional argument ambiguity
- Union types are now supported (optional modernization)
- `array_key_exists()` on objects deprecated
- Removed `create_function()`
- `each()` removed (was deprecated in 7.2)

```bash
# Use PHP compatibility checker
composer require --dev phpcompatibility/php-compatibility
vendor/bin/phpcs --standard=PHPCompatibility --runtime-set testVersion 8.0 src/
```

### 2.2 — PHP 8.1 Compatibility

- `null` is no longer passed to non-nullable type parameters
- `readonly` properties supported (not required, but audit)
- Enums introduced (optional modernization)
- Intersection types

### 2.3 — PHP 8.2+ Compatibility

- Dynamic properties deprecated → removed in 8.2 (entities using `__get`/`__set` may be affected)
- `${var}` string interpolation deprecated
- `utf8_encode()`/`utf8_decode()` deprecated
- Traits with `abstract` methods — behavior change

```bash
php vendor/bin/phpcs --standard=PHPCompatibility --runtime-set testVersion 8.2 src/
```

---

## Phase 3: Symfony 3.4 → 4.4

### 3.1 — Update composer.json

```json
{
    "require": {
        "symfony/symfony": "4.4.*",
        "doctrine/orm": "^2.7",
        "doctrine/doctrine-bundle": "^2.0",
        "sensio/framework-extra-bundle": "^6.0",
        "symfony/swiftmailer-bundle": "^3.4",
        "friendsofsymfony/user-bundle": "^2.1",
        "friendsofsymfony/oauth-server-bundle": "^1.6"
    }
}
```

### 3.2 — Symfony 4.x Breaking Changes

**Directory structure** (Symfony 4 Flex structure vs Standard Edition):
- `app/` → `config/`, `src/`, `public/` (Symfony 4 uses Flex structure)
- `web/` → `public/`
- `app/AppKernel.php` → `src/Kernel.php`
- `app/config/` → `config/`
- `var/cache/`, `var/log/` (already correct)

**Configuration:**
- YAML config splits: `config/packages/*.yaml` per bundle
- `parameters.yml` → `config/services.yaml` (parameters section)
- `AppKernel.php` bundle registration → `config/bundles.php`

**Services:**
- Service autowiring and autoconfiguration now default
- Bundle `services.yml` → `config/services.yaml` or bundle-level `Resources/config/services.xml`
- Controller-as-service now default (no `$this->get()` in controllers)
- `$this->get('doctrine')` → inject `EntityManagerInterface`
- `$this->get('security.token_storage')` → inject `Security`
- `$this->get('templating')` → inject `Environment` (Twig)
- `$this->get('router')` → inject `RouterInterface`

**Routing:**
- `_controller: ODRAdminBundle:Default:index` → `ODR\AdminBundle\Controller\DefaultController::indexAction`
- Or use PHP attributes (Symfony 6+)

**Templates:**
- Template references `ODRAdminBundle:Folder:template.html.twig` → `@ODRAdmin/Folder/template.html.twig`

**Forms:**
- `->add('field', 'text')` → `->add('field', TextType::class)`
- `FormEvent` type-hints

**Security:**
- User encoder config changes
- `security.yml` → `config/packages/security.yaml`

**Deprecations to fix in 4.4 before moving to 5.x:**
```bash
php bin/console debug:container --deprecations
```

### 3.3 — Bundle Compatibility for 4.4

| Bundle | Action |
|--------|--------|
| `symfony/symfony` 4.4 | Update composer constraint |
| `doctrine/doctrine-bundle` ^2.0 | Update |
| `gedmo/doctrine-extensions` ^3.0 | Update (v3 for Doctrine 2.7+) |
| `sensio/framework-extra-bundle` ^6.0 | Update |
| `friendsofsymfony/user-bundle` | Still works in 4.4 |
| `jms/security-extra-bundle` | May break — audit |
| `snc/redis-bundle` ^3.0 | Update |
| `lexik/jwt-authentication-bundle` ^2.10 | Update |

---

## Phase 4: Symfony 4.4 → 5.4

> **The 4.4 → 5.0 deprecation cleanup is documented as a repeatable playbook in
> [`SYMFONY_5_DEPRECATION_CLEANUP.md`](SYMFONY_5_DEPRECATION_CLEANUP.md)** — route notation, template
> notation + templating engine, the exception controller, and the controller container-injection
> refactor are already done and committed. That guide is written to be re-run if the work diverges as
> the codebase changes. What remains for Phase 4 is the **abandoned-bundle removals** (FOSUserBundle,
> FOSOAuthServerBundle, sensio-extra), covered below.

### 4.1 — Remove All 4.x Deprecations First

```bash
# Symfony Upgrade Checker
composer require symfony/upgrade-checker --dev
php bin/console lint:yaml config/
```

### 4.2 — Symfony 5.x Breaking Changes

**Security system overhaul:**
- `security.yaml` `encoders:` → `password_hashers:`
- `providers:` syntax changes
- `guard` authenticator → `custom_authenticator` (passport system)
- `anonymous: true` → `anonymous: lazy` or removed

**FOSUserBundle:**
- FOSUserBundle 2.x is **not compatible with Symfony 5**
- **Options:**
  a. Use `symfonycasts/reset-password-bundle` + custom User entity
  b. Use `scheb/two-factor-bundle` for 2FA
  c. Use `nelmio/security-bundle`
  - **Recommended:** Remove FOSUserBundle, implement registration/login manually with Symfony Security component

**FOSOAuthServerBundle:**
- Not compatible with Symfony 5+
- **Replace with:** `trikoder/oauth2-bundle` or `league/oauth2-server` + `thephpleague/oauth2-server-bundle`

**JMS Security Extra Bundle:**
- Incompatible with Symfony 5+
- Replace annotations with Symfony's `#[IsGranted]` attribute or `security.yaml` access_control rules

**Twig:**
- `twig/extensions` abandoned — replace with:
  - `twig/extra-bundle` (`twig/markdown-extra`, `twig/html-extra`, etc.)
  - `knplabs/knp-markdown-bundle` updated version

### 4.3 — Sensio Bundles Deprecation

- `sensio/framework-extra-bundle` — Symfony 5.2+ has built-in replacements:
  - `@Route` → `#[Route]` attribute (PHP 8)
  - `@ParamConverter` → `#[MapEntity]` (Symfony 6.2+) or ValueResolver
  - `@Security` → `#[IsGranted]`
  - `@Cache` → `#[Cache]`
- `sensio/distribution-bundle` — Remove entirely (Flex handles this)
- `sensio/generator-bundle` — Remove (use `symfony/maker-bundle`)

---

## Phase 5: Symfony 5.4 → 6.4

### 5.1 — PHP 8.0 Minimum

Symfony 6 requires PHP 8.0+. Ensure PHP 8.x compatibility is complete (Phase 2).

### 5.2 — Symfony 6.x Changes

**Routing:**
- YAML/XML route definitions can stay, but PHP attributes are now standard
- Consider migrating to `#[Route]` attributes on controllers

**Dependency Injection:**
- Remove XML/YAML service definitions for autowired services
- Use `#[Autowire]` attribute for non-standard injection

**Doctrine ORM:**
- Doctrine ORM 2.13+ required, then migrate to 3.x
- Remove `doctrine/annotations` dependency (replaced by PHP attributes)
- Entity annotations `@ORM\Entity` → `#[ORM\Entity]` attributes

**Forms:**
- No major breaking changes from 5.4

**Console:**
- `Command::execute()` return type must be `int`

### 5.3 — HWIOAuthBundle

- Update to HWIOAuthBundle ^2.0 (Symfony 6 compatible)
- Configuration syntax changes

---

## Phase 6: Symfony 6.4 → 7.4

### 6.1 — PHP 8.2 Minimum

Symfony 7 requires PHP 8.2+.

### 6.2 — Symfony 7.x Changes

**Security:**
- `symfony/security-bundle` uses new passport-based authenticator system (complete)
- Hash algorithms updated

**Doctrine:**
- Doctrine ORM 3.x required
- `EntityManager::flush($entity)` removed — always flush without args
- `Query::iterate()` removed — use `toIterable()`
- Lazy ghost objects for proxies (PHP 8.1+ required)
- `doctrine/annotations` removed — PHP 8 attributes only

**Twig:**
- Twig 3.x (should already be on this from Symfony 5+)

**Mailer:**
- `symfony/swiftmailer-bundle` is **abandoned**
- **Replace with:** `symfony/mailer` + appropriate transport

**HttpClient:**
- `php-http/guzzle6-adapter` → use `symfony/http-client` or `guzzlehttp/guzzle` ^7.0

**Type safety:**
- Strict type declarations throughout
- Nullsafe operator `?->` usage

### 6.3 — Final Bundle Status for 7.4

| Bundle | Status | Action |
|--------|--------|--------|
| `symfony/symfony` 7.4 | ✅ Target | Update |
| `doctrine/orm` ^3.0 | ✅ Available | Update |
| `doctrine/doctrine-bundle` ^2.11 | ✅ Available | Update |
| `gedmo/doctrine-extensions` ^3.14 | ✅ Available | Update |
| `lexik/jwt-authentication-bundle` ^3.0 | ✅ Available | Update |
| `snc/redis-bundle` ^4.0 | ✅ Available | Update |
| `predis/predis` ^2.0 | ✅ Available | Update |
| `ramsey/uuid` ^4.0 | ✅ Available | Update |
| `knplabs/knp-markdown-bundle` | Check | Update or replace |
| `league/commonmark` ^2.0 | ✅ Available | Update |
| `hwi/oauth-bundle` ^2.0 | ✅ Available | Update |
| `nyholm/psr7` ^1.8 | ✅ Available | Update |
| `ml/json-ld` | Check | Audit |
| `friendsofsymfony/user-bundle` | ❌ Abandoned | **Remove — implement custom** |
| `friendsofsymfony/oauth-server-bundle` | ❌ Abandoned | **Remove — use league/oauth2-server** |
| `jms/security-extra-bundle` | ❌ Incompatible | **Remove — use #[IsGranted]** |
| `sensio/distribution-bundle` | ❌ Removed | **Remove** |
| `sensio/generator-bundle` | ❌ Removed | **Remove** |
| `twig/extensions` | ❌ Abandoned | **Remove — use twig/extra-bundle** |
| `symfony/swiftmailer-bundle` | ❌ Abandoned | **Remove — use symfony/mailer** |
| `drymek/pheanstalk-bundle` | ❌ Abandoned | **Remove — use pda/pheanstalk directly** |
| `ddeboer/data-import` | ❌ Outdated | **Remove — use league/csv or custom** |
| `sensio/framework-extra-bundle` | ⚠️ Deprecated | **Remove — use native attributes** |
| `php-http/guzzle6-adapter` | ⚠️ Old | **Replace with symfony/http-client** |
| `dterranova/crypto-bundle` | ⚠️ Unmaintained | **Audit — implement custom encryption** |

---

## Phase 7: Post-Upgrade Modernization

### 7.1 — Migrate to Symfony Flex Structure

```
Before (Standard Edition):       After (Flex):
app/config/                   →  config/
app/config/routing.yml        →  config/routes.yaml
app/config/security.yml       →  config/packages/security.yaml
app/config/config.yml         →  config/packages/*.yaml
app/AppKernel.php             →  src/Kernel.php
web/                          →  public/
```

### 7.2 — Migrate Controller Routes to PHP Attributes

```php
// Before (YAML routing + old-style controller):
class DefaultController extends Controller {
    public function indexAction() { ... }
}

// After (PHP 8 attributes + AbstractController):
use Symfony\Component\Routing\Attribute\Route;

class DefaultController extends AbstractController {
    #[Route('/admin', name: 'odr_admin_homepage')]
    public function index(): Response { ... }
}
```

### 7.3 — Migrate Services to Autowiring

```yaml
# config/services.yaml
services:
    _defaults:
        autowire: true
        autoconfigure: true

    ODR\AdminBundle\:
        resource: '../src/ODR/AdminBundle/'
        exclude: '../src/ODR/AdminBundle/{Entity,Tests}'
```

### 7.4 — Migrate Doctrine Annotations to Attributes

```php
// Before:
/**
 * @ORM\Entity(repositoryClass="ODR\AdminBundle\Repository\DataTypeRepository")
 * @ORM\Table(name="odr_data_type")
 */
class DataType { ... }

// After:
#[ORM\Entity(repositoryClass: DataTypeRepository::class)]
#[ORM\Table(name: 'odr_data_type')]
class DataType { ... }
```

### 7.5 — Replace SwiftMailer with Symfony Mailer

```bash
composer remove symfony/swiftmailer-bundle
composer require symfony/mailer
```

Update all `\Swift_Message` usages to `Symfony\Component\Mime\Email`.

---

## Testing Strategy

### Regression Testing at Each Phase

After each phase upgrade:

1. **Run screenshot comparison:**
   ```bash
   npx playwright test --update-snapshots  # Update baseline (first run)
   npx playwright test                      # Compare against baseline
   ```

2. **Run PHPUnit suite:**
   ```bash
   php bin/phpunit
   ```

3. **Run API contract tests:**
   ```bash
   php bin/phpunit tests/Api/
   ```

4. **Manual smoke tests** using the URL list in `TEST_URLS.md`

### Test Coverage Goals

| Test Type | Current | Target |
|-----------|---------|--------|
| Controller tests | 6 files | 30+ files |
| API tests | 3 files | 10+ files |
| Service unit tests | 1 file | 20+ files |
| Screenshot tests | 0 | 50+ URLs |
| E2E auth flow tests | 0 | 5+ flows |

---

## Risk Assessment

| Risk | Impact | Mitigation |
|------|--------|------------|
| FOSUserBundle removal | High | Plan custom user management early |
| FOSOAuthServerBundle removal | High | Evaluate league/oauth2-server-bundle early |
| 74 entities with annotations | High | Use automated rector rules for migration |
| 55 controllers using `$this->get()` | High | Systematic service injection refactor |
| Custom DQL MATCH function | Medium | Verify Doctrine 3 compatibility |
| Beanstalkd integration | Medium | Audit pheanstalk compatibility |
| Memcached session handler | Medium | Verify Symfony 7 compatibility |
| Background Node.js workers | Low | Independent of Symfony version |
| YAML routing files | Low | Can stay YAML throughout upgrade |

---

## Recommended Tools

```bash
# PHP compatibility checker
composer require --dev phpcompatibility/php-compatibility

# Symfony upgrade checker
composer require --dev symfony/upgrade-checker

# Rector - automated PHP/Symfony upgrades
composer require --dev rector/rector
# Configure rector.php for Symfony migrations

# PHP-CS-Fixer
composer require --dev friendsofphp/php-cs-fixer
```

### Rector Configuration (rector.php)

```php
use Rector\Symfony\Set\SymfonySetList;
use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([__DIR__ . '/src'])
    ->withSets([
        SymfonySetList::SYMFONY_40,
        SymfonySetList::SYMFONY_50,
        SymfonySetList::SYMFONY_60,
        SymfonySetList::SYMFONY_70,
        SymfonySetList::ANNOTATIONS_TO_ATTRIBUTES,
    ]);
```

---

## Estimated Effort by Phase

| Phase | Description | Complexity |
|-------|-------------|------------|
| 0 | Pre-upgrade baseline | Low |
| 1 | Test infrastructure | Medium |
| 2 | PHP 7.3 → 8.3 | Medium-High |
| 3 | Symfony 3.4 → 4.4 | High |
| 4 | Symfony 4.4 → 5.4 | Very High (FOSUser, OAuth) |
| 5 | Symfony 5.4 → 6.4 | Medium (Doctrine attrs) |
| 6 | Symfony 6.4 → 7.4 | Medium (SwiftMailer, Doctrine 3) |
| 7 | Post-upgrade modernization | Medium |

---

## Next Steps (Immediate)

1. ✅ Create CLAUDE.md
2. ✅ Create this UPGRADE_PLAN.md
3. ✅ Create `TEST_URLS.md` with comprehensive URL list
4. ✅ Set up Playwright test infrastructure (42 tests across 3 spec files)
5. 🔲 Write PHPUnit tests for all controllers (baseline)
6. ✅ Capture screenshot baseline (timestamped snapshots in `tests/screenshots/snapshots/`)
7. 🔲 Run `rector` dry-run to assess automated migration scope
8. 🔲 Begin Phase 2: PHP 8.x compatibility
