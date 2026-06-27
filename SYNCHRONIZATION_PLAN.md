# SYNCHRONIZATION_PLAN.md — keeping the Symfony 7.4 branch in sync with `develop`

This document is the **repeatable process** for porting changes from `origin/develop` onto the
Symfony 7.4 upgrade branch. It is meant to be re-run periodically (the SF7 branch is tested for
weeks before it merges back, while `develop` keeps moving), so it is written to be idempotent and
resumable. The living state lives in **`DEVELOP_SYNC_LOG.md`**.

## Why this exists / why not just `git merge`

The SF7 branch forked from `master` at `d42d71a4` (2026-03-10) and carries the Symfony 3.4 → 7.4
upgrade (~890 files refactored). Since the fork, the team kept shipping on `develop`. A direct
`git merge origin/develop` is **not viable**: the large majority of files `develop` changed were
*also* structurally refactored by the upgrade, so a merge produces unresolvable conflicts where both
sides are nearly unrecognizable.

Instead we **port the *intent*** of each develop change onto the branch's refactored files, applying
the SF7 conversions below. Git is used only as a **read** tool (`git show`, `git diff`,
`git checkout <ref> -- <file>`), never as a merge.

## State: `DEVELOP_SYNC_LOG.md`

- **High-water mark** — the `origin/develop` SHA up to which we have synced (initial = the fork
  `d42d71a4`). Drives each run's delta.
- **Ported-commits table** — one row per develop commit processed:
  `dev SHA | date | PR/issue | subject | decision | branch commit(s) | notes`,
  `decision` ∈ {Ported, Skipped-obsolete, Skipped-already-handled, Deferred}.

## Environment changes: "code only + change list" (maintainer decision, 2026-06-27)

Some develop commits require changes to the live dev environment, not just code — new DB columns
(via a `*Meta.orm.yml` mapping) or new config parameters (referenced from `config.yml`). The branch
is **actively being tested**, so the sync must not silently mutate that environment. Policy:

- Port the **code** normally (entities, `*.orm.yml`, committed `*.dist` config templates, forms,
  controllers, templates).
- Do **NOT** touch the live database or the active (gitignored) `app/config/config.yml` /
  `parameters.yml`.
- Record every required env change in **`DEVELOP_SYNC_CHANGELIST.md`** (DDL + active-config entries),
  tagged by Phase, for the maintainer to apply via their own migration/deploy process.

A feature won't fully run until its changelist entries are applied; schema mappings have a wide blast
radius (Doctrine selects the new column on every hydration of that entity), so apply DDL before
testing schema-affecting features.

## Per-run procedure (idempotent — works for any delta size)

1. `git fetch origin`.
2. Read the high-water SHA `H` from `DEVELOP_SYNC_LOG.md`. Pin this run's target `T = origin/develop`
   HEAD (record it) so the run isn't chasing a moving head.
3. Compute the delta:
   - `git log --no-merges H..T` — commits to process (for grouping, priority, and the log).
   - `git diff H T` — the **authoritative "what changed"** (the *net* diff is the source of truth;
     many develop commits are WIP/restore-point/cleanup steps inside one feature arc — port the net
     result of the arc, not each intermediate step).
   - Spot-check merge commits in `H..T` for any unique conflict-resolution content.
4. **Triage** each commit/arc into a bucket: security → bug fix → feature → new file/infra → skip
   (obsolete, or already handled by the upgrade).
5. **Port** highest priority first, applying the conversion checklist. New files:
   `git checkout T -- <file>` then convert. Verify + commit per logical group (reference the develop
   SHA(s) in the commit message).
6. **Update `DEVELOP_SYNC_LOG.md`**: mark each commit's decision + branch commit, then set the
   high-water mark to `T`. Commit the log.

Assumption: `develop` advances forward (not force-pushed/rebased). If it ever is, reconcile via the
per-commit log rather than the high-water SHA alone.

## SF7 conversion checklist (apply to every ported PHP/Twig change)

Patterns established by the upgrade (see `UPGRADE_PLAN.md`, `PHASE_4_PLAN.md`, `PHASE_5_PLAN.md`):

- **Controllers**: `$this->get('x')` / container auto-injection → a constructor-injected ODR service,
  or `$this->container->get('x')` resolved through `odr.controller_locator`;
  `$this->getDoctrine()` → `$this->container->get('doctrine')`.
- **Security token**: `->getToken()->getUser()` → `->getToken()?->getUser() ?? 'anon.'`
  (the new system returns a null token for anonymous requests).
- **Templates**: `Bundle:Dir:file.html.twig` → `@Namespace/Dir/file.html.twig` (the namespace strips
  the "Bundle" suffix: `@ODRAdmin`, `@ODROpenRepositoryGraph`); `|markdown` → `|markdown_to_html`;
  `{% spaceless %}` → `{% apply spaceless %}` (do NOT wrap `.js.twig` output — use `autoescape false`).
- **Logger**: type-hint `Psr\Log\LoggerInterface`, not `Symfony\Bridge\Monolog\Logger`.
- **DBAL**: `fetchAll(` → `fetchAllAssociative(`; `executeUpdate(` → `executeStatement(`.
- **Entities**: `Bundle:Entity` short alias → fully-qualified class name.
- **Paths/kernel**: `getRootDir()` / `%kernel.root_dir%` → `getProjectDir()` (= repo root) /
  `%kernel.project_dir%`. NB: develop's *"Override getRootDir() for symlinked instances"* must be
  **re-implemented** with `getProjectDir()`, not ported verbatim (`getRootDir()` no longer exists).
- **Abandoned-dep mappings** (map develop's usage to the branch's replacements):
  `Swift_Mailer`/`Swift_Message` → `symfony/mailer` (`MailerInterface` + `Mime\Email`);
  KnpMarkdown parser → `twig/markdown-extra` (`Twig\Extra\Markdown\MarkdownInterface`);
  `Ddeboer\DataImport\Reader\CsvReader`/`Writer\CsvWriter` →
  `ODR\AdminBundle\Component\Utility\ODRCsvReader`/`ODRCsvWriter` (imported `as CsvReader`/`as CsvWriter`).
- **New console commands** → extend `ODR\AdminBundle\Command\ContainerAwareCommand`; register in the
  bundle's `console.command` glob (the glob calls `setContainer('@service_container')`).
- **New `HttpException` subclasses** overriding `getStatusCode()` → declare `: int`.
- **New gedmo/Doctrine event subscribers** → tag `doctrine.event_subscriber` as usual; the
  `AppKernel`-registered `DoctrineEventSubscriberCompatPass` rewrites them to `doctrine.event_listener`
  (SF7's doctrine-bridge no longer wires the subscriber tag).
- **DI Configuration/Extension** classes → `getConfigTreeBuilder(): TreeBuilder`, `load(): void`.
- **Mirror** gitignored config edits into the tracked `.dist` (`config.yml`, `parameters.yml`,
  `security.yml`).
- **Skip** `.php~` backup files; for docs, bring the prose but ignore stale code samples.

## Branch & integration

Work happens on `sf7-develop-sync` (branched from the SF7 branch) so the verified upgrade stays
pristine; it is a linear descendant, so folding it back into the SF7 branch is a fast-forward.
Merging the SF7 line back into `develop` is a **separate future event** (a deliberate reconciliation),
not part of this process — `DEVELOP_SYNC_LOG.md` is what makes that tractable by showing which develop
commits are already represented. **No develop freeze is assumed at any point.**

## Verification (per group / per run)

- `php app/console cache:clear --env=dev` and `--env=prod` (both must succeed).
- curl-with-session smoke (the Phase-6 pattern): log in via `tests/screenshots/auth.ts` creds →
  `/admin` → `/admin/type/list/databases` → datatype landing → a display page → `/edit/{id}` render
  → `POST /api/v5/token`; expect 200/302, no server exceptions.
- `php app/console lint:twig src/ app/Resources/` (8 pre-existing undefined-filter errors are expected).
- Targeted exercise of each ported feature; browser via chrome-devtools for interactive flows
  (avoid the heavy ~290-graph RRUFF display page — it crashes the automated browser).
