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
- `lectionary/`: Lectionary readings by cycle
- `decrees/`: Dicastery decree metadata

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

- `ApiTestCase.php`: Base test class with common functionality
- `Routes/`: Tests for each route handler
- `Methods/`: HTTP method validation tests
- `Enum/`: Enum behavior tests

**Test Groups:**

- Regular tests: Fast validation tests
- `@group slow`: Integration tests requiring API calls

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
