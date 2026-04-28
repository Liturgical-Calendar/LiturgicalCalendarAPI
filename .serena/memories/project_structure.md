# Codebase Structure — LiturgicalCalendarAPI

## Top-level layout

```
LiturgicalCalendarAPI/
├── public/             # Web entry point (index.php)
├── src/                # PHP source (PSR-4: LiturgicalCalendar\Api\)
├── jsondata/           # Source data + schemas (sourcedata/, schemas/)
├── i18n/               # gettext .po/.pot files (Weblate-managed)
├── phpunit_tests/      # PHPUnit tests (PSR-4: LiturgicalCalendar\Tests\)
├── migrations/         # Data migrations
├── scripts/            # Misc scripts
├── docs/               # Documentation (incl. enhancements/AUTHENTICATION_ROADMAP.md)
├── infrastructure/     # Infra config
├── literature/         # Reference material
├── logs/               # Runtime logs
├── cache/              # Runtime cache
├── vendor/             # Composer deps
├── composer.json / composer.lock
├── Dockerfile / docker-compose.yml
├── phpstan.neon / phpstan.neon.dist     # static analysis (level 10)
├── phpcs.xml                             # PSR-12 enforcement
├── phpunit.xml.dist
├── captainhook.json                      # git hooks
├── start-server.sh / stop-server.sh      # API dev server
├── ws-start-server.sh / ws-stop-server.sh # WebSocket server (for UnitTestInterface)
├── allowedOrigins.txt                    # CORS allow-list (legacy/static)
├── redocly.yaml                          # OpenAPI lint config
├── parseOpenAPI.js                       # OpenAPI helper
├── .markdownlint.yml / .markdownlint-wiki.yml
├── .env.example, .env.local
└── CLAUDE.md, README.md, CHANGELOG.md, CODE_OF_CONDUCT.md, CONTRIBUTING.md, DATA_RETENTION.md
```

## Request flow

1. **Entry point**: `public/index.php`
   - Locates project root via `composer.json`
   - Loads env via Dotenv
   - Configures error/logging
   - Instantiates `Router`, calls `route()`
2. **`src/Router.php`** — PSR-7 request dispatch, runs PSR-15 middleware (ErrorHandling, Logging, JwtAuth)
3. **Handlers** (`src/Handlers/`) — each extends `AbstractHandler` (implements PSR `RequestHandlerInterface`)
4. **Response** — handlers use `Negotiator` to pick JSON/YAML/XML/ICS

## Key handlers (`src/Handlers/`)

- `CalendarHandler` — `/calendar` — **calculated** liturgical calendar for a specific year
- `EventsHandler` — `/events` — **all possible** event definitions (catalog with `event_key` IDs, no dates)
- `MetadataHandler` — `/metadata`
- `RegionalDataHandler` — `/calendars`
- `MissalsHandler` — `/missals`
- `DecreesHandler` — `/decrees`
- `TestsHandler` — `/tests`
- `EasterHandler` — `/easter`
- `SchemasHandler` — `/schemas`
- `Auth/LoginHandler` — `POST /auth/login`
- `Auth/RefreshHandler` — `POST /auth/refresh`
- (also `/auth/logout`, `/auth/me`)

## Models (`src/Models/`)

- `Calendar/` — calendar generation logic + event models (used by `CalendarHandler`)
- `RegionalData/` — national/diocesan/wider region data
- `MissalsPath/` — Roman Missal metadata
- `EventsPath/` — event catalog (used **only** by `EventsHandler`, NOT by `CalendarHandler`)
- `Decrees/` — decree metadata
- `Lectionary/` — lectionary readings
- `Auth/User.php` — env-based user model
- `LitCalItem.php` — calculated event (with date)
- `PropriumDeSanctisEvent.php`, `PropriumDeTemporeEvent.php`

## Enums (`src/Enum/`)

Type-safe liturgical concepts: `LitColor`, `RomanMissal`, `LitLocale` (incl. manually defined Latin `la`/`la_VA`), `Route`, `PathCategory`, `Ascension`, `Epiphany`, `CorpusChristi`. Common helper: `EnumToArrayTrait`.

## HTTP layer (`src/Http/`)

- `Enum/` — `AcceptHeader`, `RequestMethod`, `StatusCode`, …
- `Exception/` — `UnauthorizedException`, `ForbiddenException`, etc.
- `Middleware/` — `ErrorHandling`, `Logging`, `JwtAuthMiddleware`
- `Server/` — middleware pipeline
- `Negotiator.php` — content + language negotiation

## Services / utilities

- `src/Services/JwtService.php` — JWT generation/verify/refresh
- `src/Params/` — request param validation
- `src/Utilities.php`, `src/DateTime.php`, `src/LatinUtils.php`, `src/Health.php`

## Data sources (`jsondata/sourcedata/`)

- `missals/propriumdetempore/` — temporal cycle
- `missals/propriumdesanctis_*/` — sanctorale per Missal edition
- `calendars/nations/` — national calendars
- `calendars/dioceses/` — diocesan calendars
- `calendars/wider_regions/` — multi-diocese transversal layers (NOT standalone calendars)
- `lectionary/` — readings by cycle
- `decrees/` — Dicastery decrees

## Schemas (`jsondata/schemas/`)

- `openapi.json` — OpenAPI spec
- `DiocesanCalendar.json`, `NationalCalendar.json`, `WiderRegionCalendar.json`
- `PropriumDeSanctis.json`, `PropriumDeTempore.json`
- `LitCalDecreesSource.json`, `LitCalTest.json`, `LitCalTranslation.json`

## Tests (`phpunit_tests/`)

- `ApiTestCase.php` — base test class
- `Routes/`, `Methods/`, `Enum/`
- Group `@group slow` excluded by `composer test:quick`
