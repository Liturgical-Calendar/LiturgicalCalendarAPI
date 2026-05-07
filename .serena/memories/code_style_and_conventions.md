# Code Style & Conventions — LiturgicalCalendarAPI

## Language & standards

- **PHP >= 8.4**; uses modern features like `array_find`
- Strict typing expected (PHPStan level 10 will catch missing types)
- **PSR-12** coding standard, enforced by `phpcs` (config in `phpcs.xml`)
- **PHPStan level 10** (max strictness), config in `phpstan.neon.dist` (+ optional local `phpstan.neon`)
- PSR-7/15/17 for HTTP, PSR-3 for logging, PSR-4 for autoload (`LiturgicalCalendar\Api\` → `src/`; tests `LiturgicalCalendar\Tests\` → `phpunit_tests/`)

## Markdown

- `.markdownlint.yml` enforced; key rules:
  - Max line length **180** (excludes code blocks, tables)
  - MD031: blank lines around code blocks
  - MD032: blank lines around lists
  - MD029: ordered lists use sequential numbering (1, 2, 3…)
  - MD046: fenced code blocks (```), not indented
  - MD040: always specify language after opening fence (` ```bash `, ` ```php `, …)
  - MD060: tables vertically aligned
- Pre-commit hook auto-runs `composer lint:md` on staged `.md`
- Nested code blocks: outer fence uses 5 backticks (`````), inner uses 3 (```)

## Architectural conventions

### Adding a new handler

1. Create class extending `AbstractHandler` in `src/Handlers/`
2. Implement `handle(ServerRequestInterface $request): ResponseInterface`
3. Set allowed methods, accept headers, content types in constructor
4. Add a route case in `Router::route()` switch
5. Use `Negotiator` for content-type negotiation; return PSR-7 `ResponseInterface`

### Content negotiation rules

- `Negotiator::negotiateResponseContentType()` honors:
  1. `return_type` query param (json|yaml|xml|ics) — **only allowed on `/calendar`**
  2. `Accept` header
  3. JSON fallback
- **Do NOT add `return_type` handling to admin or non-calendar endpoints** — it exists solely so browsers can view `/calendar` without setting `Accept`.

### Language negotiation (CRITICAL)

- **ALWAYS use `Negotiator::pickLanguage($request, [], LitLocale::LATIN)`**
- **NEVER use `\Locale::acceptFromHttp()`** — ICU lacks Latin (`la`, `la-VA`, `la_VA`); the API manually merges Latin into `LitLocale::$values`.
- Language tag normalization in `Negotiator`: hyphens → underscores, lowercased; specificity = `substr_count(tag, '_') + 1`; sort by quality then specificity.

```php
// CORRECT
$locale = Negotiator::pickLanguage($request, [], LitLocale::LATIN);
if ($locale && LitLocale::isValid($locale)) {
    $params['locale'] = $locale;
}
```

### Liturgical event modeling

`LitCalItem` properties:

- `name` — string
- `date` — DateTime
- `color` — array of `LitColor`
- `type` — `LitGrade` (solemnity/feast/memorial/…)
- `common` — array of `LitCommon`
- `grade` — numeric precedence

`CalendarHandler` performs: movable feast computation, precedence + coincidence resolution, suppression/transfer rules.

### Logging

Use `LoggerFactory::create()` for PSR-3 Monolog instances. Logs go to `logs/`, separated per subsystem.

### Auth

- Protected routes (require JWT via HttpOnly cookie OR `Authorization: Bearer`):
  - `PUT /data/{category}/{calendar}`
  - `PATCH /data/{category}/{calendar}`
  - `DELETE /data/{category}/{calendar}`
- Token precedence: cookie first, then `Authorization` header.
- Cookie attributes:
  - Access: `SameSite=Lax`, `HttpOnly`, `Secure` (HTTPS), path `/`
  - Refresh: `SameSite=Strict`, `HttpOnly`, `Secure` (HTTPS), path `/auth`
- **Fail-closed env behavior**:
  - `APP_ENV` MUST be set in non-localhost; invalid/unset → `RuntimeException`
  - `staging`/`production` require `ADMIN_PASSWORD_HASH` (else `RuntimeException`)
  - `development`/`test` allow default password "password" if hash unset

### CORS

- `CORS_ALLOWED_ORIGINS` env (comma-separated). Default `*` (not safe with cookies).
- Auth endpoint errors only reflect validated origins (intentional security measure).

## Naming

- Namespaces & classes: PascalCase
- Methods/properties: camelCase
- Constants/enums: UPPER_SNAKE_CASE for class consts; enum cases follow PHP enum conventions
