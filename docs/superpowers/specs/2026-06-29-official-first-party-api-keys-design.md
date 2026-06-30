# Official first-party API keys (read-only, rate-limit-exempt) + test-runner consumption

Date: 2026-06-29
Status: Approved design (pending spec review)

## Problem

The UnitTestInterface test runner showed ~74 "Validate calendar data" failures with the tooltip
"...response data was perhaps truncated?". Root cause: the API enforces an unauthenticated rate limit
(`UNAUTHENTICATED_RATE_LIMIT`, 3600s window) in `src/Http/Middleware/ApiKeyRateLimitMiddleware.php`. The
WebSocket Health server (`src/Health.php`) fetches calendars over the **public** hostname
(`.env.staging`: `API_HOST=litcal.johnromanodorazio.com`), so its self-calls are not loopback-exempt and
get HTTP 429 after the first N requests. `Health.php` uses `http_errors => false` and never checks the
status code; the 429 problem+json body decodes to a valid stdClass missing
`litcal/settings/metadata/messages`, hitting the misleading "perhaps truncated?" branch (~`Health.php:1165`).

A naive "fetch via localhost" does not work: on the live box the API is an nginx/Plesk vhost, not a
standalone localhost:8000 listener.

## Key finding that shapes the design

Public read routes (`/calendar`, `/calendars`, `/events`, `/easter`, `/missals` GET, …) get only
`ApiKeyMiddleware` + `ApiKeyRateLimitMiddleware` in `src/Router.php` — **no FGA authorization**. FGA
(`OpenFgaAuthorizationMiddleware`/`AuthorizationMiddleware`) gates only the `/data` + admin *management*
routes (OIDC user + role + per-resource tuples). Therefore a "universal" key for the official read-only
UIs (frontend, components, LiturgyOfTheDay, the test runner) is simply **a valid key with a high
`rate_limit_per_hour` and `scope: read`** — no FGA tuples, no resource scoping, no governance request.

We deliberately do NOT add a "viewer on all resources" self-service scope: it would bypass the federated
local-admin approval that makes per-resource requests valuable. First-party official-UI keys are minted
out-of-band by the project owner instead.

## Goal / Non-goals

Goal: (1) a reusable mechanism to mint first-party read-only, rate-limit-exempt API keys; (2) make the
test runner consume one; (3) stop `Health.php` mislabeling non-2xx responses; (4) make the design
forward-compatible with future FGA-gated reads — a system key bypasses resource governance while
user-requested keys remain subject to it.

Non-goals: building the future FGA read-authorization middleware itself (only the flag + the bypass
contract are added now — YAGNI until read gating actually lands); write/admin keys that bypass FGA; a
"viewer on all" portal scope; wiring keys into the other official UIs (frontend/components) — the
mechanism supports them later but that's separate work.

## Part A — Minting mechanism: `scripts/mint-official-key.php`

A CLI admin script (matches existing `scripts/` tooling: seed-*, reconcile-*, migrate-*). Behavior:

1. Resolve the official application by looking it up by `name` + `is_system`. If it already exists, reuse
   it (its owner is already set — no `--owner` needed). If it does not exist yet, `--owner=<zitadel_user_id>`
   must be passed explicitly to create it. The owner is never inferred or hardcoded.
2. Idempotent create-or-reuse: if a system application is found, reuse it as-is. Otherwise insert a new
   first-party row (`name='LitCal Official UIs'`, `status='approved'`, `requested_scope='read'`,
   `is_active=true`, `is_system=true`, `zitadel_user_id=<owner>`) via
   `INSERT ... ON CONFLICT (name) WHERE is_system DO NOTHING`, re-selecting on conflict so concurrent runs
   converge on one row. A partial unique index (`uq_applications_system_name`, added by the migration)
   enforces single-system-app-per-name at the DB level.
3. Mint a key via the existing `ApiKeyRepository::generate($appId, $name, 'read', $rateLimit)` — reuses
   the repo's sha256 `key_hash` + `key_prefix` logic so the key cannot be malformed.
4. Print the plaintext key ONCE (never stored), plus the key prefix/id for later identification.

Args: `--name=<label>` (e.g. `test-runner`), `--rate-limit=<int>` (default `1000000`, effectively
unlimited since the middleware has no "unlimited" sentinel; 0/null falls back to the default).

Rejected alternatives: an admin HTTP endpoint (extra authenticated surface, overkill for occasional
minting); raw one-off SQL (must hand-replicate hashing/prefix; not reusable/documented).

## Part B — Test-runner consumption (`src/Health.php` + live env)

1. `cachedGet()` injects `X-Api-Key: <WS_API_KEY>` (from `$_ENV['WS_API_KEY']`, only when set & non-empty)
   into the request headers for internal fetches — but **only for URLs targeting our own API host**
   (relative URLs, or absolute URLs whose host matches `API_HOST`), so the key is never leaked to an
   arbitrary external URL validated via `executeValidation`. Merge into `$options['headers']` without
   clobbering an existing `Accept`.
2. `cachedGet()`'s resolve closure rejects the deferred with a descriptive error (status; `Retry-After`
   when present) for **rate-limit (429) and server-error (5xx)** responses, instead of resolving with a
   body that downstream mislabels as "truncated". Other statuses (e.g. a 404 for an unknown calendar)
   still flow through, so per-format validation reports them at the json-valid phase as before.
3. Live (`.env.staging` on the Plesk box): add `WS_API_KEY=<minted key>`; restart
   `litcal-websocket.service`. Keep `UNAUTHENTICATED_RATE_LIMIT=100` (interactive portal headroom).

## Part C — Forward-compatibility: future read gating & the system bypass

Reads are not FGA-gated today, but governance may gate them soon (dioceses / bishops' conferences
deciding which developers and applications may read their calendar data). The design must let a
first-party **system** key stay ungated while user-requested keys become gated.

Model first-party-ness at the **application** level:

1. Migration: `ALTER TABLE applications ADD COLUMN is_system BOOLEAN NOT NULL DEFAULT FALSE`, plus a
   partial unique index `uq_applications_system_name ON applications (name) WHERE is_system`.
2. Only the `mint-official-key.php` script sets `is_system=true`. The user-facing paths
   (`ApplicationsHandler`, the access-request flow) never set it — so a user can **never** mint an
   ungated key. The privilege boundary is the application, settable only by an admin out-of-band.
3. `ApiKeyMiddleware` adds `is_system` to the `api_key` request attribute (alongside id, scope,
   rate_limit_per_hour, …), sourced from the joined application row and **strictly normalized** — only
   native `true` or the PostgreSQL string `'t'` count as system, so a false-like `'f'` can never become
   a trusted key. `isSystem()` checks `=== true`.
4. **Bypass contract (documented, not built now):** when the future FGA read-authorization middleware is
   introduced, it MUST short-circuit (skip the per-resource FGA check) when
   `api_key.is_system === true`, and enforce the check otherwise. `is_system` bypasses only the resource
   gate; `scope` still constrains read vs write (a system read key = ungated reads, no writes).

This keeps `is_system` orthogonal to `scope`, and means today the flag is dormant-but-correct: the official
key is marked system now, so read gating can be switched on later without re-minting.

## Decisions

- Official application owner: project owner's real Zitadel ID (resolved at mint time).
- First-party/bypass flag: `applications.is_system` (boolean, default false); set only by the mint script.
- Default key rate limit: `1000000`/hour (≈ unlimited), scope `read`.
- Header: `X-Api-Key`. WS env var: `WS_API_KEY`.
- Staging `UNAUTHENTICATED_RATE_LIMIT` stays at 100 after the key lands.

## Testing

- Local: run the WS server against a local API with a minted test key; confirm a full test run passes with
  no 429 and no "truncated" messages; confirm a forced 429 (low limit) now surfaces an honest error.
- Staging: after deploy + key, run the full suite from the test UI and confirm green.

## Rollout / ops

API changes land via a feature branch + PR (CodeRabbit review). Minting runs once on the server to produce
the test-runner key; the key value goes only into `.env.staging` (not the repo). WS service restart picks
it up. Backup of `.env.staging` already exists from the rate-limit change.

## Out of scope

Write/admin first-party keys; portal "viewer on all" scope; minting keys for the frontend/components UIs.
