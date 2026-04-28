# LiturgicalCalendarAPI — Project Overview

PSR-7/15/17 compliant **REST API in PHP 8.4+** that generates the **Roman Catholic liturgical calendar** for any given year.

## Purpose

Calculates mobile festivities (Easter-based) and the precedence of solemnities, feasts, memorials, etc. Serves calendar data for nations, dioceses, or groups of dioceses. Output formats: JSON, YAML, XML, ICS.

## Defining characteristics

- **Authoritative sources only**: Roman Missal editions (1970, 1971, 1975, 2002, 2008, US 2011, IT 1983), Magisterial documents (e.g. *Mysterii Paschalis* 1969), Decrees of the Dicastery for Divine Worship.
- **Historically accurate**: a calendar requested for 1979 reflects 1979 rules, not today's.
- Supports multiple languages via **gettext**.
- PSR-15 middleware pipeline for HTTP handling.

## Tech Stack

- **PHP >= 8.4** (uses modern syntax like `array_find`)
- Composer package: `liturgical-calendar/api`, namespace `LiturgicalCalendar\Api\` (PSR-4)
- HTTP: `nyholm/psr7`, `nyholm/psr7-server`, `laminas/laminas-httphandlerrunner`, PSR-15 server middleware
- HTTP client: `guzzlehttp/guzzle`
- JSON Schema: `swaggest/json-schema`
- ICS: `sabre/vobject`
- YAML: `symfony/yaml`
- Logging: `monolog/monolog` (PSR-3)
- Caching: `symfony/cache`, optional APCu
- Auth: `firebase/php-jwt` (JWT, Argon2id password hashing)
- WebSocket: `plesk/ratchetphp` (for UnitTestInterface integrity checks)
- Env: `vlucas/phpdotenv`
- Quality: PHPUnit 12, PHPStan **level 10**, PHP_CodeSniffer (PSR-12)
- Hooks: CaptainHook
- Required PHP extensions: intl, zip, calendar, yaml, gettext, curl, json, xml, mbstring, dom, simplexml, libxml, ctype

## Repo Location

`/home/johnrdorazio/development/LiturgicalCalendar/LiturgicalCalendarAPI`

Companion repos in same parent directory:

- `LiturgicalCalendarFrontend` — public website
- `UnitTestInterface` — web-based integrity check UI (talks to API's WebSocket server)
- `LiturgicalCalendarAPI.wiki` — wiki content

## Important constraints

- **Timezone**: always `Europe/Vatican`
- **Year range**: 1970-9999 (`MIN_YEAR=1969` exclusive, `MAX_YEAR=10000` exclusive)
- **Default branch**: `development` (PRs target this, NOT `stable`)
