# GitHub Actions Deployment to Plesk-hosted Production — Design

- **Status**: Draft
- **Date**: 2026-05-10
- **Issue**: [#545](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/issues/545)
- **Reference implementation**: [BibleGet-I-O/endpoint `deploy.yaml`](https://github.com/BibleGet-I-O/endpoint/blob/development/.github/workflows/deploy.yaml)

## 1. Goals and non-goals

### Goals

- Automate deployment of LiturgicalCalendarAPI to the Plesk-hosted production
  server using GitHub Actions over SSH.
- Two deploy lanes from a single workflow:
  - **staging** → `${VPS_APP_DIR}/dev/`, manually triggered, runs from
    `development` branch.
  - **production** → `${VPS_APP_DIR}/v<MAJOR>/`, triggered automatically on
    GitHub release publish (non-prerelease), or manually via dispatch with
    a tag input.
- Run Doctrine database migrations as part of every deploy, idempotently
  and self-bootstrappingly.
- Verify the deployed code is healthy before the workflow reports success.
- Adopt the security and operational hardening already proven in the
  BibleGet-I-O/endpoint workflow: SHA-pinned actions, pinned host keys
  with DNS SSHFP drift detection, `StrictHostKeyChecking=yes`,
  `IdentitiesOnly=yes`, GitHub Environments for production gating,
  concurrency control.

### Non-goals

- Automatic rollback on failed deploy. Manual rollback is supported (re-run
  workflow on a previous commit/tag, optionally with `?to=` migration target).
- Pushes to `development` triggering automatic deploys. Every deploy is
  intentional; the workflow is `workflow_dispatch` + `release` only.
- Server-side `composer install`. The chrooted SSH user does not get PHP CLI
  or Composer. `vendor/` is built on the GitHub runner and rsynced.
- Server-side `bin/doctrine-migrations` invocation. Migrations run via an
  HTTP endpoint inside the deployed application, hit by `curl` from the
  runner. The chroot is preserved.
- Multi-server / blue-green / canary. Single server, single live tree per
  lane.

## 2. Architecture overview

```text
GitHub Actions runner
  ├── checkout (branch or tag)
  ├── setup PHP 8.4 + composer
  ├── composer install --no-dev          (vendor/ built on runner)
  ├── ssh agent + pinned known_hosts
  ├── rsync ./ → server:${VPS_APP_DIR}/${SUBDIR}/
  │   (excludes server-managed: .env*, logs/, cache/*)
  ├── curl POST {BASE}/${SUBDIR}/_ops/migrate     ──┐
  │     X-Deploy-Token header                       │ in-process
  └── curl GET  {BASE}/${SUBDIR}/calendars          │ Doctrine
                                                    ▼
                                       Plesk PHP-FPM (outside chroot)
                                         ├── DeployTokenMiddleware
                                         ├── MigrateHandler (POST /_ops/migrate)
                                         │   - migrations:sync-metadata-storage
                                         │   - migrations:migrate
                                         └── PostgreSQL (transactional DDL)
```

The chroot only restricts SSH sessions. PHP-FPM runs outside the chroot with
full access to PHP, Composer's installed `vendor/`, the database, and
runtime cache. Migrations execute in the same process that serves the API.

## 3. Triggers and concurrency

```yaml
on:
  workflow_dispatch:
    inputs:
      target:
        description: 'Deployment target'
        required: true
        type: choice
        options: [staging, production]
        default: staging
      tag:
        description: 'Release tag (production only, e.g. v5.0 or v5.1.2). Ignored for staging.'
        required: false
        type: string
  release:
    types: [published]

concurrency:
  group: deploy-${{ github.event_name == 'release' && 'production' || inputs.target }}
  cancel-in-progress: false
```

- **No `push` trigger.** Every dev/staging deploy is manual.
- **`release: published`** auto-deploys to production from the published tag.
  Skipped if `github.event.release.prerelease == true`.
- **Concurrency** is per-lane; staging and production are independent. Within
  a lane, queued runs serialize so a fast follow-up cannot race the in-flight
  rsync. `cancel-in-progress: false` because cancelling mid-rsync leaves the
  tree in an inconsistent state.

## 4. Targets and deploy paths

The Plesk SSH user is chrooted; their home directory IS the deploy base path
(`VPS_APP_DIR`). Subdirectories within that home represent lanes:

| Trigger                    | Ref         | SUBDIR    | DEPLOY_PATH                    |
|----------------------------|-------------|-----------|--------------------------------|
| dispatch, target=staging   | development | `dev`     | `${VPS_APP_DIR}/dev`           |
| dispatch, target=production| `${tag}`    | `v<MAJOR>`| `${VPS_APP_DIR}/v<MAJOR>`      |
| release: published         | release.tag | `v<MAJOR>`| `${VPS_APP_DIR}/v<MAJOR>`      |

`<MAJOR>` is extracted from a tag matching:

```text
^v[0-9]+\.[0-9]+(\.[0-9]+)?(-[A-Za-z0-9.-]+)?$
```

Example: `v5.8` → `v5`, `v5.0.3-rc1` → `v5`. Invalid tags fail the workflow
in the Resolve step before any server contact.

## 5. Workflow structure

Single job, single workflow file `.github/workflows/deploy.yaml`.

```yaml
jobs:
  deploy:
    name: Deploy ${{ github.event_name == 'release' && 'production' || inputs.target }}
    if: github.event_name != 'release' || github.event.release.prerelease == false
    runs-on: ubuntu-latest
    timeout-minutes: 15
    environment: ${{ github.event_name == 'release' && 'production' || inputs.target }}
    permissions:
      contents: read
```

`environment: ${target}` ties the run to a GitHub Environment. The production
environment can be configured (in repo Settings) with required reviewers so
prod deploys pause for manual confirmation before any SSH, without touching
this workflow file.

### Steps (in order)

1. **Resolve ref and deploy path.** Validates tag regex, computes `ref`,
   `deploy_subdir`, `deploy_path`.
2. **Checkout.** `actions/checkout@<SHA-pinned v6.0.2>`, `persist-credentials: false`.
3. **Verify modern codebase shape.** Bails (sets `skip=true`) if no
   `composer.json`. Defensive.
4. **Set up PHP.** `shivammathur/setup-php@<SHA v2>`, php-version 8.4,
   extensions: `intl, pdo_pgsql, json, simplexml, dom, calendar, zip, yaml,
   gettext, curl, xml`.
5. **Install production dependencies.**
   `composer install --no-dev --optimize-autoloader --no-interaction --no-progress --prefer-dist`.
6. **Configure SSH.** Writes `~/.ssh/deploy_key` (mode 600) and
   `~/.ssh/known_hosts`. Hard-fails if `VPS_SSH_KNOWN_HOSTS` is empty.
7. **SSHFP drift check.** DNS lookup of SHA-256 SSHFP records vs pinned
   key fingerprint. Warns only.
8. **Ensure deploy + cache + logs dirs.** SSH-exec
   `mkdir -p "${DEPLOY_PATH}" "${DEPLOY_PATH}/cache" "${DEPLOY_PATH}/logs"`.
9. **Deploy via rsync.**
   `--archive --delete --protect-args --exclude-from=.github/deploy/rsync-exclude.txt`.
   SSH options: `IdentitiesOnly=yes`, `StrictHostKeyChecking=yes`.
10. **Run database migrations.**
    `curl -fsS --max-time 600 -X POST -H "X-Deploy-Token: $TOKEN" "${BASE}/${SUBDIR}/_ops/migrate"`.
11. **Post-deploy health check.**
    `curl -fsS --retry 3 --retry-delay 5 --max-time 10 "${BASE}/${SUBDIR}/calendars" -o /dev/null`.
12. **Cleanup SSH key.** `if: always()`. `rm -f ~/.ssh/deploy_key`.

Steps 4–11 gate on `if: steps.shape.outputs.skip != 'true'`.

## 6. Secrets and variables

### Repository secrets

- `VPS_SSH_PRIVATE_KEY` — OpenSSH private key (ed25519) for the deploy user.
- `VPS_SSH_KNOWN_HOSTS` — Pinned host key(s) for `~/.ssh/known_hosts`. No TOFU.
- `VPS_USERNAME` — SSH username on the Plesk server.
- `VPS_HOST` — Production hostname. Also used for SSHFP DNS lookup.
- `DEPLOY_TOKEN` — 64+ hex chars from `openssl rand -hex 32`. Mirrored to
  server's `.env.production`.

### Repository variables

- `VPS_APP_DIR` — Deploy base path inside the chroot.
- `VPS_SSH_PORT` — Defaults to `22` if unset.
- `VPS_APP_BASE_URL` — Public base URL (no trailing slash). Workflow appends
  `/${SUBDIR}/...`.

### Environments

Two GitHub Environments:

- `staging`: no required reviewers; secrets scoped here are visible only to
  staging-target runs.
- `production`: required-reviewer protection recommended (manual confirmation
  before SSH ever happens). Configured in repo Settings, not in this workflow.

## 7. rsync exclude policy

File: `.github/deploy/rsync-exclude.txt`. Adopted from
BibleGet-I-O/endpoint with LCAPI-specific deltas (`phpunit_tests/`,
`/cache/*`, `.markdownlint.yml`, drop unused entries).

```text
# --- VCS / CI / agent metadata ---
.git/
.github/
.gitignore
.gitattributes
.editorconfig
.serena/
.worktrees/
.claude/

# --- IDE / local tooling ---
.vscode/
.idea/
.phpunit.cache/
.phpcs.cache

# --- Tests, dev-only sources ---
phpunit_tests/
coverage/
docs/

# --- Build / dev configuration with no runtime role ---
phpcs.xml
phpcs.xml.dist
phpstan.neon
phpstan.neon.dist
phpunit.xml
phpunit.xml.dist
captainhook.json
redocly.yaml
.markdownlint.yml
docker-compose.yml
docker-compose.yaml
Dockerfile
.dockerignore

# --- Local dev scripts (not used in production) ---
start-server.sh
stop-server.sh
restart-server.sh
server.pid
server.vscode.pid

# --- CLI tooling that cannot run in the SSH chroot anyway ---
# bin/ currently contains only doctrine-migrations, which is invoked
# in-process via the /_ops/migrate endpoint. Leaving CLI scripts on the
# server is needless surface area and a foot-gun if PHP CLI ever becomes
# reachable. doctrine-migrations.php (Doctrine config at the repo root)
# IS shipped — it's read by MigrateHandler via PhpFile.
bin/

# --- Composer install-time only (never read at runtime) ---
# composer.json IS shipped — Router::findProjectRoot() uses it as a
# project-root marker (file_exists, not parsed). composer.lock is only
# consumed by `composer install`, which runs on the runner.
composer.lock

# --- Project meta files ---
CLAUDE.md
README.md

# --- SERVER-MANAGED FILES (never overwrite, never --delete) ---
+ .env.example
.env
.env.*
logs/

# --- RUNTIME CACHE (preserve server-side state) ---
# Excludes contents only; the directory itself is created on the
# server in a pre-rsync SSH-exec step so PHP can still write to it.
/cache/*
```

The `+ .env.example` line uses rsync's include-override syntax (must precede
the matching exclude). It ships the example file so server admins can diff
against the live `.env.production` to spot new/removed variables.

### What ships and why

The runner-builds-vendor + HTTP-migrations model means the server never runs
`composer install` and never invokes a PHP CLI. The shipped tree therefore
differs from issue #545's original sketch (which assumed server-side
`composer install`):

- `vendor/` — **shipped**. Built on the runner in step 5; required at
  runtime for PSR-4 autoloading and the Doctrine Migrations classes used
  by `MigrateHandler`.
- `src/` (including `src/Migrations/`) — **shipped**. Application code and
  Doctrine migration classes loaded at runtime.
- `migrations/` (if present at repo root) — **shipped**. Reserved for
  future migration locations declared in `doctrine-migrations.php`.
- `public/`, `jsondata/`, `i18n/`, `cache/` (empty) — **shipped**. Web
  root, source data, translations, runtime cache directory.
- `composer.json` — **shipped**. Used by `Router::findProjectRoot()` as a
  `file_exists()` marker for path-walking; contents are not parsed at
  runtime. Excluding it would break the router's project-root detection.
- `doctrine-migrations.php` — **shipped**. Read by `MigrateHandler` via
  `Doctrine\Migrations\Configuration\Migration\PhpFile` to obtain
  `migrations_paths` and the `doctrine_migration_versions` table schema.
- `composer.lock` — **excluded**. Only consumed by `composer install` on
  the runner.
- `bin/doctrine-migrations` (whole `bin/` dir) — **excluded**. CLI script;
  PHP CLI is unreachable from the chrooted SSH user, and nothing else
  invokes it. Leaving it on disk is needless attack surface.

## 8. Deploy endpoint

A token-authenticated, framework-internal endpoint that runs Doctrine
Migrations programmatically inside the deployed PHP-FPM process.

### Routes

| Method | Path                        | Purpose                                  |
|--------|-----------------------------|------------------------------------------|
| POST   | `/_ops/migrate`             | Apply pending migrations.                |
| POST   | `/_ops/migrate?to=<version>`| Migrate to a specific version (rollback).|
| GET    | `/_ops/migrate/status`      | Plain-text current schema state.         |

### Components

**`src/Http/Middleware/DeployTokenMiddleware.php`** (PSR-15):

- Reads `DEPLOY_TOKEN` via `getenv('DEPLOY_TOKEN') ?: $_ENV['DEPLOY_TOKEN'] ?? ''`.
  Same fallback pattern as `ApiKeyRateLimitMiddleware`.
- Fail-closed if `DEPLOY_TOKEN` is empty: every request rejected with 503
  (server misconfigured, not a client error).
- Reads `X-Deploy-Token` header; compares with `hash_equals()`.
- Refuses unless `APP_ENV in {staging, production}` to keep the endpoint dark
  in dev/test environments where `.env` may carry a long-forgotten test token.
- On reject: 401 with no response body details (no auth-mode signaling that
  helps an attacker probe).

**`src/Handlers/Ops/MigrateHandler.php`** (extends `AbstractHandler`):

- `set_time_limit(0); ignore_user_abort(true);` at top.
- Builds `Doctrine\Migrations\DependencyFactory` from an `ExistingConnection`
  and `new PhpFile(<project-root>/doctrine-migrations.php)` (same
  construction as `bin/doctrine-migrations`, just in-process). The config
  file at the repo root supplies `migrations_paths` and the
  `doctrine_migration_versions` table schema.
- For POST: registers `MigrateCommand` with a Symfony Console `Application`,
  runs `migrations:sync-metadata-storage --no-interaction` then
  `migrations:migrate --no-interaction` (or `--no-interaction <version>` if
  `?to=` is supplied and matches the version regex).
- For GET on `/status`: runs `migrations:status --no-interaction`.
- Output streamed via `StreamOutput(fopen('php://output', 'wb'))`.
- HTTP status: 200 on Console exit code 0, 500 on non-zero. The `curl -f` in
  the workflow turns that into a workflow failure.
- Content-Type: `text/plain; charset=utf-8`.

**Routing (`src/Router.php` + `src/Enum/Route.php`)**:

- New enum cases `OPS_MIGRATE = '/_ops/migrate'` and
  `OPS_MIGRATE_STATUS = '/_ops/migrate/status'`.
- Router pipeline wraps the handler in `DeployTokenMiddleware`. Standard
  ErrorHandling and Logging middleware still applies.

### Why programmatic, not shelled?

`bin/doctrine-migrations` is a CLI script that requires a PHP binary. The
chroot blocks CLI access. The endpoint reuses the same Doctrine Migrations
classes in-process; no `shell_exec`, no second PHP install needed.

### Hardening summary

- **Long random token** — 64 hex chars from `openssl rand -hex 32`.
- **Constant-time compare** — `hash_equals()` in middleware.
- **Method restriction** — Handler `allowedMethods`; AbstractHandler returns
  405.
- **Long-running protection** — `set_time_limit(0); ignore_user_abort(true);`.
  Plesk FPM `request_terminate_timeout` ≥ 600s required (checklist item).
- **Rate limit** — Existing `ApiKeyRateLimitMiddleware` IP-bucket applies to
  the unauthenticated path.
- **Environment gate** — Refuse outside `staging`/`production`.
- **Logging** — `LoggingMiddleware` records every request through the Router.
- **Path obscurity** — `/_ops/` prefix; not advertised in `openapi.json` or
  `/calendars`. Defense in depth, not the primary control.

## 9. Error handling and rollback

### Per-step failure behaviour

- **Resolve ref** (bad tag regex, missing `VPS_APP_DIR`) — `exit 1` before
  any SSH.
- **Checkout** (tag missing) — `actions/checkout` fails the job.
- **Verify codebase shape** (no `composer.json`) — Sets `skip=true`; later
  steps no-op; run is green with `::notice`.
- **Setup PHP / composer** (network, dep resolution) — Job fails before any
  server contact.
- **Configure SSH** (empty `VPS_SSH_KNOWN_HOSTS`) — Hard fail, no TOFU
  fallback.
- **SSHFP drift check** (pin/DNS mismatch) — `::warning::`; doesn't block.
- **Ensure deploy dir** (SSH error) — Job fails; nothing rsynced.
- **Rsync** (network, permission) — Job fails. `--delete` is per-file;
  partial state limited to source dirs. `cache/`, `logs/`, `.env*` unaffected.
- **Migrate (HTTP POST)** (non-2xx, timeout) — `curl -f` fails the job.
  Code is on disk; schema may be partly-applied (PostgreSQL DDL is
  transactional; per-migration atomicity preserved).
- **Health check** (non-2xx) — Job fails. Code and schema are deployed;
  behaviour is broken. Fix forward or roll back manually.
- **Cleanup SSH key** — `if: always()`.

### Rollback strategy

No automatic rollback. Manual flow:

1. **Code rollback**: re-run the workflow against a previous good commit
   (staging) or republish a prior release tag (production).
2. **Schema rollback**: `POST /_ops/migrate?to=<previous-version>`.
   Doctrine's `migrations:migrate <version>` runs `down()` for each
   intervening migration. Doctrine's `down()` methods must be present and
   correct; this is a maintenance commitment going forward, not a property
   of the deploy workflow.
3. **Last-resort manual recovery**: SSH in, `psql` directly. Out of scope.

If at any point a deploy starts shipping risky migrations (data backfills,
NOT NULL adds with backfill), revisit this design — the current trade-off
favours simplicity for a small, schema-only migration cadence.

## 10. Testing strategy

### Layer 1 — handler unit tests

`phpunit_tests/Routes/Ops/MigrateHandlerTest.php`:

- POST without token → 401.
- POST with wrong token → 401 (timing-safe; assertion on response, not on
  log timing — the property is in the implementation).
- GET status without token → 401.
- POST with correct token, `APP_ENV=staging`, against an in-memory sqlite
  DBAL connection seeded with two fake migration classes →
  200, body contains "Migrating up" entries for both versions.
- POST with no migrations pending → 200, body contains "Already at the
  latest version".
- POST with `?to=<known-version>` → 200, schema reflects target.
- POST with malformed `?to=` → 400.
- `APP_ENV=development` → 503 with "deploy endpoint disabled".
- Concurrent POSTs (simulated) — out of scope for unit tests; covered by
  Doctrine's tracking-table guarantee plus first-deploy smoke test.

### Layer 2 — middleware unit tests

`phpunit_tests/Http/DeployTokenMiddlewareTest.php`:

- Empty `DEPLOY_TOKEN` env → 503 for any request (fail-closed).
- Token absent in header → 401.
- Token mismatch → 401.
- Token match → request passes to next handler unchanged.
- `getenv()` + `$_ENV` fallback symmetry (matches `ApiKeyRateLimitMiddleware`).
- Refuses when `APP_ENV` outside `{staging, production}`.

### Layer 3 — workflow dry-run

Manual, run once before merging:

1. Set up secrets/variables/environments per the checklist (§11).
2. `workflow_dispatch` with `target=staging` against an empty `dev/` lane.
   Verify rsync delivers, migrate endpoint returns 200, health check passes.
3. Trigger again with the same parameters; verify idempotency
   (`--delete` doesn't break anything, migrate is no-op).
4. Roll forward: add a trivial migration, run workflow again, verify schema.
5. Roll back: trigger with `?to=<previous-version>` via a one-off manual
   curl, verify schema is restored. (Future enhancement: workflow input
   for rollback target.)

## 11. Out-of-band setup checklist

User does this once per environment, before the first workflow run.

1. Generate `DEPLOY_TOKEN`: `openssl rand -hex 32`.
2. Add as GitHub repository secret `DEPLOY_TOKEN`.
3. Add same value to server's `.env.production` for each lane:
   `${VPS_APP_DIR}/dev/.env.production`,
   `${VPS_APP_DIR}/v5/.env.production`. Server-managed; never rsynced.
4. Generate deploy SSH keypair:

   ```bash
   ssh-keygen -t ed25519 -f deploy_key -C "github-actions@lcapi" -N ""
   ```

5. Append `deploy_key.pub` contents to the deploy user's
   `~/.ssh/authorized_keys` on the server.
6. Capture pinned host key:

   ```bash
   ssh-keyscan -p $PORT $HOST 2>/dev/null
   ```

   Paste contents into GHA secret `VPS_SSH_KNOWN_HOSTS`.
7. Add other GHA secrets: `VPS_SSH_PRIVATE_KEY` (private half of deploy
   key), `VPS_USERNAME`, `VPS_HOST`.
8. Add GHA variables: `VPS_APP_DIR`, `VPS_SSH_PORT`, `VPS_APP_BASE_URL`.
9. In repo Settings → Environments: create `staging` and `production`.
   Optionally add required reviewers to `production`.
10. In Plesk: confirm `request_terminate_timeout >= 600` for the deployed
    domain's PHP-FPM pool.
11. First trigger: `workflow_dispatch` with `target=staging`. Watch the run
    end-to-end; fix anything that breaks. The workflow self-bootstraps the
    Doctrine `doctrine_migration_versions` table.
12. Once staging is green: trigger with `target=production` + a real release
    tag, OR publish a release to trigger automatically.

## 12. Implementation outline

### New files

- `.github/workflows/deploy.yaml` — The deploy workflow.
- `.github/deploy/rsync-exclude.txt` — rsync exclude list (§7).
- `src/Handlers/Ops/MigrateHandler.php` — POST `/_ops/migrate`,
  GET `/_ops/migrate/status` (§8).
- `src/Http/Middleware/DeployTokenMiddleware.php` — `X-Deploy-Token` auth
  and APP_ENV gate (§8).
- `phpunit_tests/Routes/Ops/MigrateHandlerTest.php` — Handler unit tests
  (§10 layer 1).
- `phpunit_tests/Http/DeployTokenMiddlewareTest.php` — Middleware unit
  tests (§10 layer 2).

### Modified files

- `src/Router.php` — Route case for `/_ops/migrate` (and `/status`
  sub-path). Wraps handler in `DeployTokenMiddleware` via existing
  pipeline mechanism.
- `src/Enum/Route.php` — Enum cases `OPS_MIGRATE`, `OPS_MIGRATE_STATUS`.
- `.env.example` — `DEPLOY_TOKEN=` placeholder with comment pointing to
  `_ops/migrate`.

### Build sequence

1. `DeployTokenMiddleware` + tests (no DB; smallest unit).
2. `MigrateHandler` + tests (in-memory sqlite DBAL, fake migration classes).
3. Router wiring (`Route` enum + `Router::route()`).
4. Manual smoke test: `composer start`, hit `/_ops/migrate/status` with curl
   against a local PostgreSQL instance, with `APP_ENV=staging`.
5. `.github/workflows/deploy.yaml` + `.github/deploy/rsync-exclude.txt`.
6. Out-of-band setup (user follows §11 checklist).
7. First `workflow_dispatch` against `staging` to validate end-to-end.

## 13. Open questions and future work

- **`/_ops/migrate/status` response format**: currently plain text (Doctrine
  CLI output passthrough). If tooling consumers emerge, add a JSON variant
  via `Accept: application/json` content negotiation.
- **Rollback workflow input**: a `workflow_dispatch` input `rollback_to`
  that adds `?to=<version>` to the migrate POST. Out of scope for first
  cut; add if rollback becomes routine.
- **Slack/Discord notification on failure**: out of scope. GitHub already
  emails on workflow failure; the production environment + required
  reviewer setting is the louder channel.
- **Staging health check URL**: assumes `${VPS_APP_BASE_URL}/dev/calendars`
  is publicly reachable. If the dev lane is IP-restricted, the workflow
  needs an additional egress allowlist or a different probe path. Verify
  during first dry-run.
- **First-deploy bootstrap on a non-empty lane**: if a lane already exists
  with a hand-installed schema, the first `migrations:sync-metadata-storage`
  call creates the tracking table but doesn't mark anything as applied.
  Run `composer db:migrations:mark-applied` once on the server (manual,
  one-off) before the first `migrate` call to avoid re-running historical
  migrations against a populated schema.
