# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

The Liturgical Calendar API is a PSR-7/15/17 compliant REST API written in PHP 8.4+ that generates the Roman Catholic liturgical calendar for any given year.
It calculates mobile festivities and determines the precedence of solemnities, feasts, and memorials.
The API serves calendar data for nations, dioceses, or groups of dioceses in various formats: JSON, YAML, XML, or ICS.

**Key characteristics:**

- Data is based on official sources (Roman Missal editions, Magisterial documents, Dicastery Decrees)
- Historically accurate: calendars for past years reflect rules as they existed at that time
- Supports multiple languages via gettext
- PSR-7 compliant HTTP message handling with PSR-15 middleware architecture

## Development Commands

### Starting the API Server

The API requires at least 6 PHP workers since some routes make internal requests to other routes:

```bash
# Using composer (recommended)
composer start

# Using the script directly
./start-server.sh

# Manual approach with environment
PHP_CLI_SERVER_WORKERS=6 php -S localhost:8000 -t public
```

**Stop the server:**

```bash
composer stop
# or
./stop-server.sh
```

**Environment configuration:** Copy `.env.example` to `.env.local` and configure:

- `API_PROTOCOL` (http|https)
- `API_HOST` (localhost in dev)
- `API_PORT` (8000 in dev)
- `API_BASE_PATH` (empty in dev, e.g. /api/dev in production)
- `APP_ENV` (development|test|staging|production) - **Required in non-localhost environments**
  - `development` / `test`: Allow default password if `ADMIN_PASSWORD_HASH` is unset (for testing convenience)
  - `staging` / `production`: Require `ADMIN_PASSWORD_HASH` to be configured (throws exception if missing)
  - Invalid/unset values throw exception (fail-closed security)

**JWT Authentication configuration (required for protected endpoints):**

- `JWT_SECRET` - Secret key for signing tokens (minimum 32 characters, generate with `php -r "echo bin2hex(random_bytes(32));"`)
- `JWT_ALGORITHM` - Algorithm for signing (default: HS256)
- `JWT_EXPIRY` - Access token expiry in seconds (default: 3600 = 1 hour), must be positive
- `JWT_REFRESH_EXPIRY` - Refresh token expiry in seconds (default: 604800 = 7 days), must be positive
- `ADMIN_USERNAME` - Admin username for authentication (default: admin)
- `ADMIN_PASSWORD_HASH` - Argon2id password hash (generate with `password_hash('password', PASSWORD_ARGON2ID)`)
  - Required in `staging` and `production` environments
  - Optional in `development` and `test` environments (defaults to password "password")

**Protected Routes:** The following routes require JWT authentication (via HttpOnly cookie or `Authorization: Bearer <token>` header):

- `PUT /data/{category}/{calendar}` - Create calendar data
- `PATCH /data/{category}/{calendar}` - Update calendar data
- `DELETE /data/{category}/{calendar}` - Delete calendar data

**Authentication Endpoints:**

- `POST /auth/login` - Authenticate with username/password, returns access and refresh tokens (sets HttpOnly cookies)
- `POST /auth/refresh` - Refresh access token using refresh token (reads from cookie or body)
- `POST /auth/logout` - End session and clear HttpOnly cookies
- `GET /auth/me` - Check authentication state (returns user info from token, essential for cookie-based auth)

**Cookie-Based Authentication (Phase 2.5):**

The API supports full cookie-only authentication where:

- Tokens are stored in HttpOnly cookies (not accessible to JavaScript, mitigating token theft via XSS)
- `JwtAuthMiddleware` reads token from cookie first, falls back to Authorization header
- `RefreshHandler` reads refresh token from cookie, no request body needed
- Frontend uses `credentials: 'include'` to send cookies automatically

**CORS Configuration:**

- `CORS_ALLOWED_ORIGINS` - Comma-separated list of allowed origins for credentialed CORS requests
  - Default: `*` (all origins allowed - not recommended for production with cookies)
  - Example: `CORS_ALLOWED_ORIGINS=https://example.com,https://admin.example.com`
  - Auth endpoint errors only reflect validated origins (security measure)

See [Authentication Roadmap](docs/enhancements/AUTHENTICATION_ROADMAP.md) for implementation details.

### Local Development Bootstrap

The API server runs on the **host** (not in a container). The `docker-compose.yml`
stack provides the dependent infrastructure (Postgres, Zitadel, OpenFGA,
Mailpit, Adminer) plus a one-shot `litcal-migrate` container that applies
all pending Doctrine migrations against the litcal database. Fresh-clone
setup is a three-step sequence:

```bash
# 1. Start (or restart) the infrastructure and apply migrations.
#
#    Always pass --build so newly-pulled migration files land in the
#    litcal-migrate image before it runs. --build is cheap when nothing
#    in the build context changed (cached layers).
#
#    On first run: scripts/init-db.sql creates roles, databases, the
#    pgcrypto extension, and the empty doctrine_migration_versions
#    tracking table. The litcal-migrate one-shot then applies
#    Version20260518120000 (and any later migrations) to create the
#    application schema — access_requests, applications, api_keys,
#    audit_log, ... — and exits.
#
#    On subsequent runs: db comes up healthy from the persisted volume;
#    litcal-migrate re-runs and is a no-op if everything is up-to-date,
#    or applies whatever new migrations the rebuild picked up.
docker compose up -d --build

# 2. Install PHP dependencies on the host (separate from the container).
composer install

# 3. Start the API server on the host.
composer start
```

**After-pull workflow:** re-run `docker compose up -d --build`. The
`--build` flag triggers a rebuild of the litcal-migrate image whenever
`src/Migrations/` (or any other COPY'd path) has changed, so newly-
pulled migrations apply automatically without a manual `composer
db:migrate` step.

**Manual migrate (escape hatch):** if for any reason you need to run
migrations from the host instead — debugging, running a single
migration up/down, generating a new one — the composer scripts still
work: `composer db:migrate`, `composer db:migrations:status`,
`composer db:migrations:generate`. These require `vendor/` populated on
the host (i.e. `composer install` first) and a Postgres reachable on
`localhost:5432`.

**Schema is authoritative in `src/Migrations/`, not in `init-db.sql`.** When
adding a new table or column:

- Generate a migration: `composer db:migrations:generate`, then edit it.
- Apply locally: `composer db:migrate`.
- Verify with `composer db:migrations:status`.

Do NOT add application-table DDL to `scripts/init-db.sql` — that script is
bootstrap-only (roles, databases, pgcrypto, the empty migrations tracking
table). Anything else there silently diverges from the migration history.

### Testing

```bash
# Install dependencies first
composer install

# Run all PHPUnit tests
composer test

# Run quick tests (excludes slow tests)
composer test:quick

# Static analysis (PHPStan level 10)
composer analyse

# Code style checking
composer lint

# Auto-fix code style issues
composer lint:fix

# Lint OpenAPI schema with Redocly
composer lint:openapi

# Source-data canonical-encoding check (decrees family + openapi.json)
composer lint:jsondata

# Officially supported locales have every resource they promise
composer lint:locales

# Missal naming convention and event_key identity (see "Missal folder conventions")
composer lint:missals

# Parallel syntax checking
composer parallel-lint
```

### WebSocket Test Server

For the UnitTestInterface web-based integrity checker:

```bash
# Start WebSocket server
composer ws:start

# Stop WebSocket server
composer ws:stop
```

In VSCode, use `Ctrl+Shift+B` and select `litcal-tests-websockets`.

#### Check steps, and the `covers` step

A source-data check reports `exists`, `parses` and `validates` — all three asking whether what is present
is well-formed. `covers` asks a different question: does the folder hold a file for every locale its owner
declares? It is reported only for items that carry a non-null `expected_locales` in `GET /validations`,
and those two are derived from one another in `CheckableInventory` so an item cannot advertise a step it
has nothing to compare against. A client sizes its rendering from `steps`, so **every arm of the folder
branch must emit one frame per advertised step** — including the empty-folder early return.

`covers` applies to any folder item whose expected locale set has an authority *other than the folder
itself*. That excludes exactly two families, where the declared locales are scanned from the very folder
being checked and the comparison would be a tautology: a wider region's `:i18n` and a missal's `:i18n`.
National and diocesan calendars **declare** `locales` in their own source files, so their `:i18n` folders
are covered; the rite-level corpus is measured against the General Roman Calendar's locale set, which is
`FULLY_TRANSLATED_LOCALES` (five), not the fourteen gettext folders `buildLocales()` intersects down.

The verdict is a subset test **by locale identity, never by count**: a declared locale with no file fails
the step, while a file for a locale the owner does not declare does not fail it and is named in the frame
text instead — which is how a stale `locales` declaration surfaces.

## Architecture

### Request Flow

1. **Entry Point:** `public/index.php`
   - Locates project root via `composer.json`
   - Loads environment with Dotenv
   - Configures error handling and logging
   - Instantiates `Router` and calls `route()`

2. **Router:** `src/Router.php`
   - Implements PSR-7 request/response handling
   - Determines endpoint from URL path
   - Delegates to appropriate Handler
   - Runs PSR-15 middleware pipeline (ErrorHandling, Logging)

3. **Handlers:** `src/Handlers/`
   - All extend `AbstractHandler` (implements PSR `RequestHandlerInterface`)
   - Each handler manages one primary route
   - Key handlers:
     - `CalendarHandler`: **Calculated** liturgical calendar for a specific year (`/calendar`)
       - Returns liturgical events with dates, resolved precedence, suppressions/transfers
       - Performs full calendar calculation based on the year and calendar parameters
     - `EventsHandler`: **All possible** liturgical events for a calendar (`/events`)
       - Returns event definitions with `event_key` IDs (no dates or calculations)
       - Provides a catalog of events available for a given calendar
       - Useful for frontends to populate selection lists (e.g., datalists)
     - `MetadataHandler`: Calendar metadata (`/metadata`)
     - `RegionalDataHandler`: Regional calendar data (`/calendars`)
     - `MissalsHandler`: Missal metadata (`/missals`)
     - `DecreesHandler`: Dicastery decrees (`/decrees`)
     - `TestsHandler`: Test data (`/tests`)
     - `EasterHandler`: Easter calculations (`/easter`)
     - `SchemasHandler`: JSON schemas (`/schemas`)
     - `Auth/LoginHandler`: JWT authentication (`/auth/login`)
       - POST endpoint that accepts username and password
       - Returns JWT access token and refresh token
     - `Auth/RefreshHandler`: Token refresh (`/auth/refresh`)
       - POST endpoint that accepts refresh token
       - Returns new JWT access token

4. **Response:** Handlers use `Negotiator` to determine content type (JSON/YAML/XML/ICS) based on Accept header or `return_type` parameter

### Core Architecture Components

**Models:** `src/Models/`

- `Calendar/`: Calendar generation logic and liturgical event models
  - Used by `CalendarHandler` to perform calendar calculations for a specific year
- `RegionalData/`: National/diocesan/wider region calendar data structures
- `MissalsPath/`: Roman Missal metadata
- `EventsPath/`: Event catalog models (all possible events with `event_key` IDs)
  - Used **only** by `EventsHandler` to serve event lists to frontend applications
  - NOT used by `CalendarHandler` for calendar calculation
- `Decrees/`: Decree metadata
- `Lectionary/`: Lectionary readings
- `Auth/`: Authentication models
  - `User.php`: User authentication (currently environment-based)
- `LitCalItem.php`: Individual liturgical event representation (calculated, with dates)
- `PropriumDeSanctisEvent.php`: Saints/feasts event model
- `PropriumDeTemporeEvent.php`: Temporal cycle event model

**Enums:** `src/Enum/`

- Type-safe enumerations for liturgical concepts
- `LitColor`: Liturgical colors
- `RomanMissal`: Missal editions
- `LitLocale`: Supported locales (includes manually defined locales like Latin `la`/`la_VA` plus ICU-based locales)
- `Route`, `PathCategory`: API routing
- `Ascension`, `Epiphany`, `CorpusChristi`: Movable feast configurations
- Use `EnumToArrayTrait` for common array conversions

**HTTP Layer:** `src/Http/`

- `Enum/`: HTTP-specific enums (`AcceptHeader`, `RequestMethod`, `StatusCode`, etc.)
- `Exception/`: Custom HTTP exceptions (including `UnauthorizedException`, `ForbiddenException`)
- `Middleware/`: PSR-15 middleware (ErrorHandling, Logging, JwtAuthMiddleware)
- `Server/`: Middleware pipeline implementation
- `Negotiator.php`: Content negotiation logic

**Services:** `src/Services/`

- `JwtService.php`: JWT token generation, verification, and refresh

**Params:** `src/Params/`

- Request parameter validation and processing

**Utilities:**

- `src/Utilities.php`: General utility functions
- `src/DateTime.php`: Liturgical date calculations
- `src/LatinUtils.php`: Latin text processing
- `src/Health.php`: System health checks and integrity validation

### Data Sources

**JSON Data:** `jsondata/sourcedata/`

- `missals/`: Propriums from different Roman Missal editions
  - `propriumdetempore/`: Temporal cycle events
  - `propriumdesanctis_*/`: Saints and feasts by edition (1970, 2002, 2008, US 2011, IT 1983)
- `calendars/`: Regional calendar definitions
  - `nations/`: National calendars
  - `dioceses/`: Diocesan calendars
  - `wider_regions/`: Multi-diocese regions
- `lectionary/`: Lectionary readings by cycle (ten sections, each an i18n folder of per-locale files;
  further lectionary folders live under `decrees/`, each missal, and each nation, wider region and diocese)
- `decrees/`: Dicastery decree metadata

#### Missal folder conventions

The missals tree obeys three naming rules. All three are load-bearing — path resolvers are written
against them — and `composer lint:missals` (CI job `missals_lint`) is the gate that keeps them true.

**1. `{missal_folder}/{missal_folder}.json`.** Every missal directory holds a data file named after
the directory: `propriumdesanctis_1970/propriumdesanctis_1970.json`,
`propriumdesanctis_2024/propriumdesanctis_2024.json`, and so on. The rule is already codified as
`JsonData::MISSAL_FILE` and `JsonData::AMBROSIAN_MISSAL_FILE` (use `JsonData::missalFileFor($rite)`
to pick between them). The Ambrosian sanctorale used to be the one exception, spelled
`propriumdesanctis_2024/propriumdesanctis.json`, so a resolver built on the convention returned a
nonexistent path for exactly that missal — and the symptom was a silent "this missal has no data"
rather than an error (#940). Rename the *file*, never the directory: the directory name carries the
edition year, which is what distinguishes editions.

**2. i18n and lectionary file names follow the missal's tier.** Editio typica missals are
language-generic, so their sidecars are named by bare language: `propriumdesanctis_1970/i18n/en.json`.
National missals are locale-specific — an edition approved for one country, in that country's
variety of the language — so theirs are named by full locale:
`propriumdesanctis_US_2011/i18n/en_US.json`, `propriumdesanctis_US_2011/lectionary/es_US.json`.
This is not an inconsistency; it is predictable from the tier, and `loadPropriumDeSanctisData()`
branches on exactly that (`LitLocale::$PRIMARY_LANGUAGE` for `EDITIO_TYPICA_*`,
`CalendarParams->Locale` otherwise).

**3. A shared `event_key` must denote the same saint.** Missals are delta layers merged by
`event_key`, and `LiturgicalEventCollection::addLiturgicalEvent()` is keyed on that string alone. So
re-declaring a key across missals is normal and correct — `StPeterClaver` is declared by the 2002
editio typica, by IT_1983 and by US_2011, each with its own grade for its own calendar. What must
never happen is two *different* saints sharing one key. `StIsidore` meant Isidore of Seville
(4 April) in `propriumdesanctis_1970` and Isidore the Farmer (15 May) in `propriumdesanctis_US_2011`;
the US row silently overwrote Seville's, erasing him from the US calendar, and the empty per-missal
lectionary placeholder erased his readings along with him (#939). The enforceable proxy for "same
saint" is the date: **a key declared by more than one sanctorale missal of a rite must carry the
same `month`/`day` in every one of them.** When two rows really are different saints, give the newer
one its own key (`StIsidoreFarmer`) and rename it in the structure file *and* in every `i18n/` and
`lectionary/` sidecar of that missal — a sidecar key the structure file no longer declares is itself
a lint failure, because that is what a half-finished rename looks like.

**Translations:** `i18n/`

- gettext `.po`/`.pot` files for UI strings
- Managed via Weblate integration

**Schemas:** `jsondata/schemas/`

- JSON Schema definitions for API responses and source data validation
- OpenAPI specification (`openapi.json`)
- **Source data schemas:**
  - `DiocesanCalendar.json`: Schema for diocesan calendar source files
  - `NationalCalendar.json`: Schema for national calendar source files
  - `WiderRegionCalendar.json`: Schema for wider region source files
    - Wider regions are transversal layers applied to national calendars (not standalone calendars)
  - `PropriumDeSanctis.json`: Schema for Sanctorale (Proper of Saints) events in Roman Missal
  - `PropriumDeTempore.json`: Schema for Temporale events in Roman Missal
  - `LitCalDecreesSource.json`: Schema for Dicastery for Divine Worship decrees
  - `LitCalTest.json`: Schema for test source files
  - `LitCalTranslation.json`: Schema for i18n data
  - `Lectionary.json`: Schema for lectionary source files (a map of `event_key` to that event's readings)

#### Schema roles: source vs. output

Every schema declares what it is *for*, via `SchemaRole` and `LitSchema::role()`: `SOURCE`, `OUTPUT`,
`PAYLOAD`, `PROTOCOL`, or `LIBRARY`. The distinction is load-bearing and easy to miss, because
`CommonDef.json` holds definitions used by both kinds.

The worked example is readings. **`CommonDef.json#/definitions/Readings` describes OUTPUT** — and in
output a vigil Mass is a liturgical event in its own right, with its own `EventKeyVigilMass` (`…_vigil`),
its own `is_vigil_for`, and its own flat `readings`. Nothing in output ever nests a `vigil` key.
**Source data does nest one**, because there the vigil's readings belong to the event that has the vigil.
So source schemas use `CommonDef.json#/definitions/SourceReadings` — `Readings` plus `ReadingsWithVigil`
and `ReadingsChristmasWithVigil` — and `PropriumDeSanctis.json` has long declared equivalent variants
locally for the same reason.

**Never widen `Readings` to admit a source-only shape.** Doing so lets `LitCal.json` validate a response
the API cannot emit, which is a wrong-green in the output schema. `CheckableItem` requires a `LitSchema`,
and `CheckableInventorySchemaRoleTest` asserts every checkable item's schema has role `SOURCE`, so the
`/validations` half of this is enforced rather than remembered.

## Key Development Patterns

### Adding a New Handler

1. Create handler class extending `AbstractHandler` in `src/Handlers/`
2. Implement `handle(ServerRequestInterface $request): ResponseInterface`
3. Set allowed methods, accept headers, content types in constructor
4. Add route case in `Router::route()` switch statement
5. Use `Negotiator` for content-type negotiation
6. Return PSR-7 `ResponseInterface`

### Working with Liturgical Events

Events use `LitCalItem` model with properties:

- `name`: Event name
- `date`: DateTime object
- `color`: Array of `LitColor` enums
- `type`: `LitGrade` enum (solemnity, feast, memorial, etc.)
- `common`: `LitCommon` enum array
- `grade`: Numeric precedence value

Calendar calculation in `CalendarHandler` determines:

- Movable feast dates (Easter-based)
- Event precedence and coincidence handling
- Suppression/transfer rules

### Content Negotiation

**Response Format Negotiation:**

Use `Negotiator::negotiateResponseContentType()` to respect:

1. `return_type` query parameter (json|yaml|xml|ics)
2. `Accept` header
3. Default fallback (JSON)

Return appropriate PSR-7 Response with correct `Content-Type` header.

**IMPORTANT:** The `return_type` query parameter is **only** intended for the `/calendar` endpoint.
This parameter exists to allow browser-based viewing of calendar responses without requiring control over the `Accept` header.
Admin endpoints and other API routes should use standard `Accept` header content negotiation only.
Do NOT add `return_type` handling to admin or other non-calendar endpoints.

**Language Negotiation:**

**IMPORTANT:** Always use `Negotiator::pickLanguage()` for Accept-Language header processing, **never** use PHP's `\Locale::acceptFromHttp()`.

```php
$locale = Negotiator::pickLanguage($request, [], LitLocale::LATIN);
```

**Why this matters:**

- PHP's `\Locale::acceptFromHttp()` relies on ICU (International Components for Unicode) data, which does not include Latin (`la`, `la-VA`, `la_VA`)
- Latin is not part of the Unicode CLDR because it's not a living language with modern locale conventions
- The API manually supports Latin in `LitLocale::$values = ['la', 'la_VA']`
- `Negotiator::pickLanguage()` merges these manual locales with ICU-based locales for complete coverage

**Language Tag Normalization:**

The `Negotiator` class normalizes language tags from Accept-Language headers to ensure consistent matching:

- **Hyphens → Underscores:** `en-US` becomes `en_us`
- **Lowercase conversion:** `en-US` becomes `en_us` (not `en_US`)
- **Specificity calculation:** `substr_count(tag, '_') + 1`
  - `en` (0 underscores) → specificity 1
  - `en_us` (1 underscore) → specificity 2
  - `en_us_x_custom` (3 underscores) → specificity 4
- **Sorting priority:** Tags are sorted by quality (q parameter) first, then by specificity (more specific tags first)

This normalization ensures that `la`, `la-VA`, and `la_VA` all match consistently against `LitLocale::LATIN`.

**All handlers must follow this pattern:**

```php
// CORRECT - handles Latin and all other locales properly
$locale = Negotiator::pickLanguage($request, [], LitLocale::LATIN);
if ($locale && LitLocale::isValid($locale)) {
    $params['locale'] = $locale;
}

// WRONG - will fail for Latin locales
$locale = \Locale::acceptFromHttp($request->getHeaderLine('Accept-Language'));
```

### Logging

Use `LoggerFactory::create()` to instantiate PSR-3 compliant Monolog loggers:

- Logs to `logs/` directory
- Different log files for different subsystems
- Rotation and retention configurable

## Testing Strategy

**PHPUnit Tests:** `phpunit_tests/`

Test classes extend a layered base class depending on what surface they exercise. There is NOT a single "the" base class — use the one that matches the layer
being tested:

| Layer (path)     | Base class                   | When to use                                                                                                       |
| ---------------- | ---------------------------- | ----------------------------------------------------------------------------------------------------------------- |
| `Routes/*`       | `ApiTestCase`                | Full HTTP-level integration tests. Hits the running API (Guzzle). Preflighted: see `ApiServerPreflight` below.    |
| `Handlers/*`     | `AbstractHandlerTestCase`    | In-process handler tests via direct `handle()` invocation. No HTTP server needed. 14+ existing tests follow this. |
| `Repositories/*` | `RepositoryTestCase`         | PG-only repository tests. Auto-TRUNCATEs project tables; skipped when `DB_*` env unset. 6+ existing tests.        |
| Pure-logic       | `PHPUnit\Framework\TestCase` | `Methods/`, `Enum/`, `Models/`, `Params/`, etc. — no I/O, extend the framework's `TestCase` directly.             |

Rule of thumb: use the layered base whose surface you're actually exercising. A handler test that goes through `ApiTestCase` would unnecessarily require a running
server; a route test that uses `AbstractHandlerTestCase` would bypass the Router and middleware pipeline.

Other notable test infrastructure:

- `phpunit_tests/Support/EnvIsolationTrait.php`: `withoutEnv(array $keys, callable $fn)` helper used by handler tests to exercise
  "service not configured" branches without leaking host `.env.local` values into assertions.
- `phpunit_tests/Services/OpenFgaClientTest.php`: pattern for `MockHandler`-backed `OpenFgaClient` (Guzzle `MockHandler` injected into
  the HTTP client) — reused by every test that exercises FGA-calling code with mocked responses.
- `phpunit_tests/Support/ApiServerPreflight.php`: run once per process by `ApiTestCase` (and, via `RequiresLiveApiTrait`, by the `WebSocket/*`
  classes that fan out to the API). It separates three answers a bare TCP probe conflates — nothing listening (skip / "run `composer start`"),
  something listening that is NOT this API (hard error naming what answered), and our API (proceed). A foreign responder on the port used to
  produce ~131 assertion failures that read like a branch regression (#922). It also runs one advisory build-drift comparison per run; that check
  proves a mismatch, never a match — a stale container whose `jsondata/` agrees with yours still passes it.

**Never mutate `jsondata/` in a test.** Point `Router::$apiFilePath` at a temporary copy instead — every `JsonData::…->path()` resolves against it,
for the handler AND for the test's own assertions. `phpunit_tests/Support/ShadowProjectRootTrait.php` builds that copy: `createShadowProjectRoot()`
copies `jsondata/` and symlinks the read-only gettext catalogs, and `removeTree()` is hard-fenced to `sys_get_temp_dir()` and unlinks symlinks rather
than descending them. `Handlers/DecreesHandlerWriteTest`, `Handlers/RegionalDataHandlerTest` and `Services/Locale/LocaleReadinessCheckerTest` all work
that way. Backup-and-restore in `tearDown()` is not equivalent: a run that never reaches `tearDown()` — a fatal, an OOM kill, a `timeout`, a Ctrl-C —
leaves tracked source data deleted or half-restored, and the next run then fails for reasons unrelated to the change under test (#921, #935).

Two things a class that repoints the root must get right:

- **Pin anything that memoises a path derived from the root, before the root moves.** `LoggerFactory` caches both the resolved `logs/` folder and each
  channel for the whole process, so call `LoggerFactory::create('audit', <real logs>, …)` while `Router::$apiFilePath` still points at the project
  root — otherwise every later test class in that process logs into a directory this one deletes.
- **Class-level guards must `throw`, never `self::fail()`.** PHPUnit 12 cannot render a failure raised from `setUpBeforeClass()` and crashes the runner
  with `Call to undefined method BeforeFirstTestMethodFailed::test()`. A `markTestSkipped()` there is fine, and skips the whole class.

**Test Groups:**

- Regular tests: Fast validation tests.
- `slow`: Reserved for tests with **measurable** runtime cost — e.g., multi-year calendar calculations (`Routes/Readonly/TemporaleTest`), rate-limit
  window waits (`Services/RateLimiterTest`), or full-schema-corpus validation (`Schemas/SchemaValidationTest`). Integration tests are NOT automatically slow:
  most `Routes/*` tests run in < 200 ms and are excluded from the `slow` group.

> **Most tests do not belong in the `slow` group, and adding them to it is a mistake.** The group is an *exclusion* mechanism, not a label: anything in it
> disappears from `composer test:quick`, the command developers actually run. A millisecond-scale test placed in the group silently stops guarding what it
> was written to guard. Default to leaving a new test out of the group; put it in only when you have **measured** a runtime cost worth excluding.
>
> **When — and only when — a test does belong in the group, mark it with the `#[Group('slow')]` ATTRIBUTE, never a legacy `@group slow` docblock.** That
> sentence is about the *spelling*, not about whether to apply the group at all. PHPUnit 12 honours only the attribute. Several existing suites
> (`Schemas/SchemaValidationTest`, `Routes/Readonly/TemporaleTest`) still use docblocks, with two consequences: `--exclude-group slow` does not exclude
> them, so `composer test:quick` runs them anyway; and `--group slow <path>` on those files selects **zero tests and exits successfully** — a false green.
> If you use `--group` for anything, confirm with `--list-tests` that it actually selected something. Migrating those existing docblocks to attributes is
> open work — that migration means changing the *spelling* of groups already applied, not adding the group to further tests.

**Engine cache (`engineCache/`) — a trap when comparing revisions:**

`CalendarHandler` caches assembled calendars under `engineCache/v<API_VERSION>-<dataDigest>/`. Two properties make this dangerous during verification:

- `computeEngineCacheDataVersion()` hashes **only** `jsondata/sourcedata` and `i18n/*.mo` — **never the PHP source**. A code-only change (e.g. a precedence
  fix with no data change) does not invalidate the cache, so a deploy can keep serving stale calendars, and two revisions with identical data will serve
  each other's cached output.
- The cache path is relative to the **process working directory**, so running from a different cwd silently changes which cache is consulted.

When bisecting or diffing calendar output across commits, clear `engineCache/` between runs (or use a fresh cwd per invocation) and stamp the provenance
of each dump. Two separate agents have already reached confidently wrong conclusions by skipping this.

**Integrity Checks:**
External web interface at [Liturgical-Calendar/UnitTestInterface](https://github.com/Liturgical-Calendar/UnitTestInterface) provides comprehensive calendar
data validation via WebSocket backend.

## Git Workflow

- **Stable branch:** `stable` (stable releases)
- **Development branch:** `development` (active development and testing, default branch)
- **Feature branches:** Always branch off `development`, not `stable`
- **Pull requests:** Always target `development` branch, never `stable` directly
- **Release flow:** Changes merge from feature branches → `development` → `stable` after community testing
- Test locally before submitting PR

**Creating a feature branch:**

```bash
git checkout development
git pull origin development
git checkout -b feature/your-feature-name
```

## System Requirements

- PHP >= 8.4 (uses modern syntax like `array_find`)
- Extensions: intl, zip, calendar, yaml, gettext, curl, json, xml, etc.
- System `gettext` package with language packs
- Optional: `apcu` for caching (experimental)
- Docker: Use provided `Dockerfile` for containerized deployment

## Documentation Standards

### Markdown Formatting

All markdown files must conform to rules in `.markdownlint.yml`:

- **Line length:** Maximum 180 characters (code blocks and tables excluded)
- **Lists:** Must be surrounded by blank lines (MD032)
- **Code blocks:** Must be surrounded by blank lines (MD031)
- **Code blocks in lists:** Must be indented to match the list item's content indentation
  - For numbered lists: Indent 3 spaces after the number and period
  - Example: If list item is `1. Item`, code block starts at column 4 (3 spaces indent)
- **Fenced code blocks:** Use ``` style, not indented code blocks (MD046)
- **Ordered lists:** Use sequential numbering (1, 2, 3...) not all 1's (MD029)
- **Tables:** Columns must be vertically aligned using consistent spacing (MD060)

Example of properly indented code block in a list:

`````markdown
1. **Step one**

   ```bash
   composer install
   ```

2. **Step two**

   ```php
   $router = new Router();
   ```

`````

Example of properly aligned table:

```markdown
| Column A | Column B | Column C |
|----------|----------|----------|
| Short    | Medium   | Longer   |
| Value    | Value    | Value    |
```

### Markdown Linting

**IMPORTANT:** Always lint markdown files after editing them.

**Automatic Pre-Commit Hook:**

This project uses CaptainHook to automatically lint markdown files before commit. When you stage markdown files (`.md`),
the pre-commit hook will run `composer lint:md` to check for linting issues.

To reinstall hooks after configuration changes:

```bash
vendor/bin/captainhook install --force
```

**Manual Linting Commands:**

```bash
# Lint all markdown files (via composer)
composer lint:md

# Auto-fix markdown issues (via composer)
composer lint:md:fix

# Lint a specific markdown file
markdownlint CLAUDE.md

# Lint all markdown files
markdownlint "**/*.md"

# Auto-fix issues where possible
markdownlint --fix CLAUDE.md

# Using npx (no installation required)
npx --yes markdownlint-cli CLAUDE.md
```

**Common Issues and Solutions:**

- **Nested code blocks:** When demonstrating markdown code blocks that contain other code blocks, use different fence lengths:
  - Outer block: 5 backticks (`````)
  - Inner blocks: 3 backticks (```)
  - This prevents the parser from interpreting inner blocks as actual markdown
- **Ordered lists (MD029):** Use sequential numbering (1, 2, 3...) not all 1's
- **Missing language specifiers (MD040):** Always specify language after opening code fence (e.g., `` ```bash ``, `` ```php ``, `` ```json ``)
- **Line length (MD013):** Keep lines under 180 characters (excludes code blocks and tables)
- **Blank lines around lists (MD032):** Surround lists with blank lines
- **Blank lines around code blocks (MD031):** Surround code blocks with blank lines
- **Table alignment (MD060):** Use consistent spacing to align table columns vertically

## Important Notes

- **Timezone:** Always `Europe/Vatican`
- **Year Range:** 1970-9999 (MIN_YEAR=1969 exclusive, MAX_YEAR=10000 exclusive)
- **Autoloading:** PSR-4 autoload configured in `composer.json` for `LiturgicalCalendar\Api` namespace
- **Code Quality:** PHPStan level 10, PSR-12 coding standards via PHP_CodeSniffer
- **Hooks:** CaptainHook for git hooks (see `captainhook.json`)
