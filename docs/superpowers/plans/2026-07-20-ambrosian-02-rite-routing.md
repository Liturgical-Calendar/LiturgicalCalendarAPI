# Ambrosian Rite — Plan 2: Rite routing & validation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or
> superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an optional leading `ambrosian` path segment to the `/calendar` route (absence = Roman, every existing
URL unchanged), validate rite↔calendar compatibility and the 1976 year floor (→ 400), and return a clean **501 Not
Implemented** for valid Ambrosian requests until the engine and data land in Plans 3–5.

**Architecture:** Plan 1 merged the `Rite → RiteProfile → TemporaleEngine` seam. This plan wires the *request side*:
`Router` strips the `ambrosian` segment and records the `Rite`; `CalendarHandler` threads it into `CalendarParams`;
`CalendarParams` validates the combination; and the handler short-circuits Ambrosian with `ImplementationException`
(501) before doing any Roman pipeline work. The Ambrosian whitelist lives as a constant on a new
`AmbrosianRiteProfile` (data-driven in Plan 5). Roman output stays byte-identical.

**Tech Stack:** PHP 8.4, PHPUnit 12, PSR-7/15 (`nyholm/psr7`), `swaggest/json-schema`, Redocly CLI (`composer
lint:openapi`), phpcs (PSR-12 + custom ruleset), PHPStan level 10, CaptainHook git hooks.

## Global Constraints

- **Working directory:** the git worktree
  `/tmp/claude-1000/-home-johnrdorazio-development-LiturgicalCalendar-LiturgicalCalendarAPI/6218483e-ad13-46d0-a0c6-2b582615e843/scratchpad/wt-ambrosian-p2`
  on branch `feature/ambrosian-rite-routing` (based on `development`, which already contains Plan 1). **Verify with
  `git rev-parse --show-toplevel` before each task's first command** — it MUST print that worktree path, never
  `/home/johnrdorazio/development/LiturgicalCalendar/LiturgicalCalendarAPI` (the shared main checkout is off-limits).
- **`vendor/` is a real, worktree-local install** (git-excluded via the worktree's `.git/info/exclude`).
  `vendor/bin/phpunit`, `vendor/bin/phpcs`, `composer analyse` operate on the worktree's own `src/`. Do NOT
  replace it with a symlink to the main checkout's `vendor/` — a symlinked vendor makes Composer's autoload
  `$baseDir` resolve (through the symlink) to the MAIN checkout's `src/`, so `vendor/bin/phpunit` silently tests
  the wrong code (worktree-only classes come back "not found"). If `vendor/` is missing or a symlink, fix it with
  `rm -f vendor && composer install --no-interaction` from the worktree. Never `git add` vendor; stage explicit paths.
- **`.env.local` must exist** for in-process handler tests to run rather than skip: if `vendor/bin/phpunit
  phpunit_tests/Handlers/...` reports the target as skipped, run `cp .env.example .env.local` (gitignored) once, then
  re-run. A "Skipped" target is NOT a pass — report real tallies.
- **Backward compatibility is absolute.** Every existing `/calendar` URL (no `ambrosian` segment) must behave
  byte-identically. The golden-master gate `phpunit_tests/Handlers/CalendarGoldenMasterTest.php` (9 cases) must stay
  **9/9** after any task touching the Router/handler/params. It runs in-process and constructs the handler directly,
  so it does not exercise the Router — Router changes need their own tests (Task 3).
- **Never bypass git hooks.** No `--no-verify`. Commits are GPG-signed; on `gpg: signing failed: Timeout`, STOP and
  report BLOCKED (ask the user to unlock GPG; never disable signing).
- **PHP standards:** short array `[]`, 4-space indent, single quotes unless interpolating. New code passes
  `vendor/bin/phpcs <file>` and `composer analyse` (PHPStan level 10, scans `src`). Production classes →
  `LiturgicalCalendar\Api\...` (`src/`); tests → `LiturgicalCalendar\Tests\...` (`phpunit_tests/`, per
  `autoload-dev`).
- **Commit trailer:** end every commit body with `Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>`.
- **Error semantics (fixed contract for this plan):**
  - Ambrosian + a `nation` calendar → **400** (Ambrosian has no national layer).
  - Ambrosian + a diocese not in the whitelist → **400**.
  - Ambrosian + year `< 1976` → **400**.
  - Ambrosian + otherwise-valid request → **501** (planned, not yet implemented).
  - No `ambrosian` segment → Roman, unchanged.
- **Whitelist (provisional):** the four Ambrosian diocese IDs are `milano_it`, `bergam_it`, `novara_it`, `lugano_ch`.
  These are the intended IDs for the diocese calendar files created in **Plan 5**; the whitelist constant here MUST
  match those files when they are created. Until then, no Ambrosian diocese file exists, so a whitelisted-diocese
  request still 400s as "unknown diocese" from the existing diocesan-existence validation — the *positive* whitelist
  path (→ 501) is only reachable/testable once Plan 5 lands. Plan 2's testable Ambrosian-success case is the bare
  comune (`/calendar/ambrosian`, `/calendar/ambrosian/{year}`) → 501.

---

### Task 1: `AmbrosianRiteProfile` + factory wiring

Add the Ambrosian rite profile that owns the diocese whitelist, and make the factory return it instead of throwing.

**Files:**

- Create: `src/Models/Calendar/Rite/AmbrosianRiteProfile.php`
- Modify: `src/Models/Calendar/Rite/RiteProfileFactory.php`
- Test: `phpunit_tests/Models/Calendar/Rite/AmbrosianRiteProfileTest.php`, and extend `phpunit_tests/Models/Calendar/Rite/RiteProfileFactoryTest.php`

**Interfaces:**

- Consumes: `RiteProfile` interface, `Rite` enum, `TemporaleEngine` interface (all from Plan 1).
- Produces:
  - `final class AmbrosianRiteProfile implements RiteProfile` with `public const SUPPORTED_DIOCESES = ['milano_it',
    'bergam_it', 'novara_it', 'lugano_ch']` (a `list<string>`), `rite(): Rite` → `Rite::AMBROSIAN`, and
    `temporaleEngine(): TemporaleEngine` throwing `\LogicException` (unreachable — the handler 501-guards before
    calling it; the real engine replaces this in Plan 3).
  - `RiteProfileFactory::forRite(Rite::AMBROSIAN)` now returns `new AmbrosianRiteProfile()` (no longer throws `\InvalidArgumentException`).

- [ ] **Step 1: Write the failing test**

Create `phpunit_tests/Models/Calendar/Rite/AmbrosianRiteProfileTest.php`:

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Models\Calendar\Rite;

use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Models\Calendar\Rite\AmbrosianRiteProfile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AmbrosianRiteProfile::class)]
final class AmbrosianRiteProfileTest extends TestCase
{
    public function testRiteIsAmbrosian(): void
    {
        self::assertSame(Rite::AMBROSIAN, (new AmbrosianRiteProfile())->rite());
    }

    public function testWhitelistIsTheFourDioceses(): void
    {
        self::assertSame(
            ['milano_it', 'bergam_it', 'novara_it', 'lugano_ch'],
            AmbrosianRiteProfile::SUPPORTED_DIOCESES
        );
    }

    public function testTemporaleEngineIsNotYetImplemented(): void
    {
        $this->expectException(\LogicException::class);
        (new AmbrosianRiteProfile())->temporaleEngine();
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit phpunit_tests/Models/Calendar/Rite/AmbrosianRiteProfileTest.php -v`
Expected: FAIL — `Class "…AmbrosianRiteProfile" not found`.

- [ ] **Step 3: Implement the profile**

Create `src/Models/Calendar/Rite/AmbrosianRiteProfile.php`:

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Models\Calendar\Rite;

use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Models\Calendar\Temporale\TemporaleEngine;

/**
 * Ambrosian rite profile. Plan 2 wires only the diocese whitelist and rite
 * identity; the temporale engine, precedence resolver, missal resolver, and
 * vocabularies arrive in later plans.
 */
final class AmbrosianRiteProfile implements RiteProfile
{
    /**
     * Diocesan calendars that support the Ambrosian rite. Provisional constant
     * until Plan 5 creates these diocese files with `supported_rites` metadata,
     * at which point the whitelist becomes data-driven. These IDs MUST match the
     * diocese calendar files created in Plan 5.
     *
     * @var list<string>
     */
    public const SUPPORTED_DIOCESES = ['milano_it', 'bergam_it', 'novara_it', 'lugano_ch'];

    public function rite(): Rite
    {
        return Rite::AMBROSIAN;
    }

    public function temporaleEngine(): TemporaleEngine
    {
        // Unreachable in normal flow: CalendarHandler returns 501 for the
        // Ambrosian rite before any temporale computation. Replaced by the
        // real AmbrosianTemporale in Plan 3.
        throw new \LogicException('The Ambrosian temporale engine is not yet implemented (Plan 3).');
    }
}
```

- [ ] **Step 4: Update the factory**

In `src/Models/Calendar/Rite/RiteProfileFactory.php`, replace the throwing arm:

```php
return match ($rite) {
    Rite::ROMAN     => new RomanRiteProfile(),
    Rite::AMBROSIAN => new AmbrosianRiteProfile(),
};
```

- [ ] **Step 5: Update the factory test**

In `phpunit_tests/Models/Calendar/Rite/RiteProfileFactoryTest.php`, the existing `testAmbrosianNotYetWired` asserts
`\InvalidArgumentException`. Replace it with an assertion that the factory now returns an `AmbrosianRiteProfile`:

```php
public function testAmbrosianProfileIsReturned(): void
{
    $profile = RiteProfileFactory::forRite(Rite::AMBROSIAN);
    self::assertInstanceOf(AmbrosianRiteProfile::class, $profile);
    self::assertSame(Rite::AMBROSIAN, $profile->rite());
}
```

Add `use LiturgicalCalendar\Api\Models\Calendar\Rite\AmbrosianRiteProfile;` to that test's imports. (Read the file
first to match its exact structure and remove the now-obsolete `InvalidArgumentException` test.)

- [ ] **Step 6: Run to verify pass + lint + analyse**

Run: `vendor/bin/phpunit phpunit_tests/Models/Calendar/Rite/ -v && vendor/bin/phpcs src/Models/Calendar/Rite/ phpunit_tests/Models/Calendar/Rite/ && composer analyse`
Expected: all tests PASS; phpcs clean; PHPStan level 10 clean.

- [ ] **Step 7: Confirm the golden master is unaffected (Roman path untouched)**

Run: `vendor/bin/phpunit phpunit_tests/Handlers/CalendarGoldenMasterTest.php`
Expected: 9/9 (this task only adds an Ambrosian profile the Roman path never uses).

- [ ] **Step 8: Commit**

```bash
git add src/Models/Calendar/Rite/ phpunit_tests/Models/Calendar/Rite/
git commit -m "feat(rite): AmbrosianRiteProfile with diocese whitelist; factory returns it

Adds the Ambrosian rite profile (rite identity + provisional SUPPORTED_DIOCESES
whitelist constant; temporale engine throws until Plan 3). RiteProfileFactory
now returns it instead of throwing. Roman path and golden master unchanged.

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 2: Rite property + compatibility validation in `CalendarParams`

Add the `Rite` to `CalendarParams` and the cross-field validation (national-layer / whitelist / year-floor →
`ValidationException` → 400). No wiring into the live request path yet (that is Task 3) — this task is additive and
unit-tested in isolation.

**Files:**

- Modify: `src/Params/CalendarParams.php`
- Test: `phpunit_tests/Params/CalendarParamsRiteValidationTest.php`

**Interfaces:**

- Consumes: `Rite` enum, `AmbrosianRiteProfile::SUPPORTED_DIOCESES` (Task 1), existing `ValidationException`.
- Produces (on `CalendarParams`):
  - `public Rite $Rite = Rite::ROMAN;` (new public property).
  - `public const AMBROSIAN_YEAR_LOWER_LIMIT = 1976;`
  - `public function setRite(Rite $rite): void` (assigns `$this->Rite`).
  - `public function validateRiteCompatibility(): void` — no-op for Roman; for Ambrosian throws `ValidationException`
    when `NationalCalendar !== null`, when `DiocesanCalendar` is set but not in
    `AmbrosianRiteProfile::SUPPORTED_DIOCESES`, or when `Year < AMBROSIAN_YEAR_LOWER_LIMIT`.

- [ ] **Step 1: Write the failing test**

`validateRiteCompatibility()` reads only the public `$Rite`, `$NationalCalendar`, `$DiocesanCalendar`, `$Year`
properties (never `$this->calendars`), so the test builds a `CalendarParams` without its metadata-loading constructor
(via reflection) and sets those properties directly — a pure, no-I/O unit test. Create
`phpunit_tests/Params/CalendarParamsRiteValidationTest.php`:

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Params;

use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use LiturgicalCalendar\Api\Params\CalendarParams;
use PHPUnit\Framework\TestCase;

final class CalendarParamsRiteValidationTest extends TestCase
{
    /** Build a CalendarParams with the given fields set, bypassing the metadata-loading constructor. */
    private function params(Rite $rite, ?string $national, ?string $diocesan, int $year): CalendarParams
    {
        $p = (new \ReflectionClass(CalendarParams::class))->newInstanceWithoutConstructor();
        $p->Rite             = $rite;
        $p->NationalCalendar = $national;
        $p->DiocesanCalendar = $diocesan;
        $p->Year             = $year;
        return $p;
    }

    public function testRomanAcceptsEverything(): void
    {
        $this->params(Rite::ROMAN, 'US', null, 1970)->validateRiteCompatibility();
        $this->params(Rite::ROMAN, null, 'romamo_it', 1970)->validateRiteCompatibility();
        $this->addToAssertionCount(1); // no exception thrown
    }

    public function testAmbrosianComuneBaseIsValid(): void
    {
        $this->params(Rite::AMBROSIAN, null, null, 2025)->validateRiteCompatibility();
        $this->addToAssertionCount(1);
    }

    public function testAmbrosianWhitelistedDioceseIsValid(): void
    {
        $this->params(Rite::AMBROSIAN, null, 'milano_it', 2025)->validateRiteCompatibility();
        $this->addToAssertionCount(1);
    }

    public function testAmbrosianRejectsNationalCalendar(): void
    {
        $this->expectException(ValidationException::class);
        $this->params(Rite::AMBROSIAN, 'US', null, 2025)->validateRiteCompatibility();
    }

    public function testAmbrosianRejectsNonWhitelistedDiocese(): void
    {
        $this->expectException(ValidationException::class);
        $this->params(Rite::AMBROSIAN, null, 'romamo_it', 2025)->validateRiteCompatibility();
    }

    public function testAmbrosianRejectsYearBelow1976(): void
    {
        $this->expectException(ValidationException::class);
        $this->params(Rite::AMBROSIAN, null, null, 1975)->validateRiteCompatibility();
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit phpunit_tests/Params/CalendarParamsRiteValidationTest.php -v`
Expected: FAIL — `$Rite`/`setRite`/`validateRiteCompatibility` do not exist (Error or failure).

- [ ] **Step 3: Add the property, constant, setter, and validator**

In `src/Params/CalendarParams.php`:

1. Add the import near the other `use` statements: `use LiturgicalCalendar\Api\Enum\Rite;` and `use LiturgicalCalendar\Api\Models\Calendar\Rite\AmbrosianRiteProfile;`
2. Add the property alongside the other public typed properties (near `$NationalCalendar`/`$DiocesanCalendar`, ~line 45): `public Rite $Rite = Rite::ROMAN;`
3. Add the constant near `YEAR_LOWER_LIMIT` (~line 107): `public const AMBROSIAN_YEAR_LOWER_LIMIT = 1976;`
4. Add the setter and validator as public methods (e.g. after `initParamsFromRequestPath()`):

```php
public function setRite(Rite $rite): void
{
    $this->Rite = $rite;
}

/**
 * Cross-field validation of the rite against the requested calendar and year.
 * Roman accepts every calendar shape and the full year range. The Ambrosian
 * rite has no national layer, is restricted to its whitelisted dioceses (plus
 * the comune ambrosiano when no diocese is given), and starts at 1976 (the
 * first reformed Ambrosian Missal). Throws ValidationException (HTTP 400) on
 * mismatch. Must be called after the rite, calendar, and year are all set.
 */
public function validateRiteCompatibility(): void
{
    if ($this->Rite === Rite::ROMAN) {
        return;
    }

    if ($this->NationalCalendar !== null) {
        throw new ValidationException(
            'The Ambrosian rite has no national calendars; request the comune ambrosiano (`/calendar/ambrosian`) or one of its dioceses.'
        );
    }

    if ($this->DiocesanCalendar !== null && !in_array($this->DiocesanCalendar, AmbrosianRiteProfile::SUPPORTED_DIOCESES, true)) {
        throw new ValidationException(sprintf(
            'Diocesan calendar `%s` does not support the Ambrosian rite. Ambrosian dioceses are: %s',
            $this->DiocesanCalendar,
            implode(', ', AmbrosianRiteProfile::SUPPORTED_DIOCESES)
        ));
    }

    if ($this->Year < self::AMBROSIAN_YEAR_LOWER_LIMIT) {
        throw new ValidationException(sprintf(
            'The Ambrosian rite is only available from %d onward (the first reformed Ambrosian Missal); requested year %d.',
            self::AMBROSIAN_YEAR_LOWER_LIMIT,
            $this->Year
        ));
    }
}
```

Verify the exact `ValidationException` FQCN by reading the existing `use` block in `CalendarParams.php` (Task-map
shows validators already throw `ValidationException`, so it is already imported — reuse it).

- [ ] **Step 4: Run to verify pass + lint + analyse**

Run: `vendor/bin/phpunit phpunit_tests/Params/CalendarParamsRiteValidationTest.php -v && vendor/bin/phpcs
src/Params/CalendarParams.php phpunit_tests/Params/CalendarParamsRiteValidationTest.php && composer analyse`
Expected: 6 tests PASS; phpcs clean; PHPStan level 10 clean.

- [ ] **Step 5: Confirm golden master unaffected**

Run: `vendor/bin/phpunit phpunit_tests/Handlers/CalendarGoldenMasterTest.php`
Expected: 9/9 (the new property defaults to `Rite::ROMAN` and `validateRiteCompatibility` is not yet called from the handler).

- [ ] **Step 6: Commit**

```bash
git add src/Params/CalendarParams.php phpunit_tests/Params/CalendarParamsRiteValidationTest.php
git commit -m "feat(params): Rite property + validateRiteCompatibility (400 rules)

Adds CalendarParams::\$Rite, setRite(), AMBROSIAN_YEAR_LOWER_LIMIT=1976, and
validateRiteCompatibility() enforcing: Ambrosian has no national layer, only
whitelisted dioceses, and year >= 1976. Additive; not yet wired into the
request path. Golden master unchanged.

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 3: Wire the rite live — Router segment + handler threading + 501 short-circuit

Tie it together so `/calendar/ambrosian/...` is parsed, validated, and returns 400/501 as specified, while every Roman URL is unchanged.

**Files:**

- Modify: `src/Router.php` (the `calendar` case in `route()`)
- Modify: `src/Handlers/CalendarHandler.php` (constructor + `handle()` wiring + the `RiteProfileFactory::forRite(...)` call site)
- Test: `phpunit_tests/Handlers/CalendarRiteRoutingTest.php` (in-process handler tests); extend `phpunit_tests/Routes/Readonly/CalendarTest.php` if present (live-server, optional)
- Gate: `phpunit_tests/Handlers/CalendarGoldenMasterTest.php`

**Interfaces:**

- Consumes: `Rite` (Plan 1), `CalendarParams::setRite`/`validateRiteCompatibility` (Task 2), `ImplementationException`
  (existing, `src/Http/Exception/ImplementationException.php`, → 501).
- Produces:
  - `CalendarHandler::__construct(array $requestPathParams = [], Rite $rite = Rite::ROMAN)` storing `private Rite $rite`.
  - Router passes the parsed rite: `new CalendarHandler($requestPathParts, $rite)`.

- [ ] **Step 1: Confirm the golden-master baseline is green**

Run: `vendor/bin/phpunit phpunit_tests/Handlers/CalendarGoldenMasterTest.php`
Expected: 9/9. (Do not proceed on red.)

- [ ] **Step 2: Write the failing routing tests**

These exercise the handler as the Router constructs it — `new CalendarHandler($strippedPathParts, $rite)`. Create `phpunit_tests/Handlers/CalendarRiteRoutingTest.php`:

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Handlers\CalendarHandler;
use LiturgicalCalendar\Api\Http\Enum\StatusCode;

final class CalendarRiteRoutingTest extends AbstractHandlerTestCase
{
    private function handle(array $pathParts, Rite $rite, string $uri): int
    {
        $handler = new CalendarHandler($pathParts, $rite);
        $handler->setAllowedReturnTypes(['json', 'yaml', 'xml', 'ics']);
        return $handler->handle($this->requestFor('GET', $uri, ['Accept' => 'application/json']))->getStatusCode();
    }

    public function testRomanDefaultStillWorks(): void
    {
        self::assertSame(200, $this->handle(['2025'], Rite::ROMAN, '/calendar/2025'));
    }

    public function testAmbrosianComuneBaseReturns501(): void
    {
        self::assertSame(StatusCode::NOT_IMPLEMENTED->value, $this->handle([], Rite::AMBROSIAN, '/calendar/ambrosian'));
    }

    public function testAmbrosianComuneWithYearReturns501(): void
    {
        self::assertSame(StatusCode::NOT_IMPLEMENTED->value, $this->handle(['2008'], Rite::AMBROSIAN, '/calendar/ambrosian/2008'));
    }

    public function testAmbrosianRejectsNationalCalendarWith400(): void
    {
        self::assertSame(StatusCode::BAD_REQUEST->value, $this->handle(['nation', 'US'], Rite::AMBROSIAN, '/calendar/ambrosian/nation/US'));
    }

    public function testAmbrosianRejectsNonWhitelistedDioceseWith400(): void
    {
        self::assertSame(StatusCode::BAD_REQUEST->value, $this->handle(['diocese', 'romamo_it'], Rite::AMBROSIAN, '/calendar/ambrosian/diocese/romamo_it'));
    }

    public function testAmbrosianRejectsYearBelow1976With400(): void
    {
        self::assertSame(StatusCode::BAD_REQUEST->value, $this->handle(['1975'], Rite::AMBROSIAN, '/calendar/ambrosian/1975'));
    }
}
```

- [ ] **Step 3: Run to verify it fails**

Run: `vendor/bin/phpunit phpunit_tests/Handlers/CalendarRiteRoutingTest.php -v`
Expected: FAIL — `CalendarHandler::__construct` does not accept a `Rite` argument (ArgumentCountError / TypeError), or the Ambrosian cases return 500 instead of 501/400.

- [ ] **Step 4: Add the rite to the handler constructor**

In `src/Handlers/CalendarHandler.php`:

1. Ensure imports: `use LiturgicalCalendar\Api\Enum\Rite;` (already present per Plan 1) and add `use LiturgicalCalendar\Api\Http\Exception\ImplementationException;`
2. Add a property near the other instance properties: `private Rite $rite = Rite::ROMAN;`
3. Change the constructor to accept and store it:

```php
public function __construct(array $requestPathParams = [], Rite $rite = Rite::ROMAN)
{
    parent::__construct($requestPathParams);
    $this->rite      = $rite;
    $this->startTime = hrtime(true);
}
```

- [ ] **Step 5: Thread the rite through `handle()` and add the 501 short-circuit**

In `handle()`, locate the block where `CalendarParams` is built and `initParamsFromRequestPath()` is called (Task-map:
around lines 4996–5011). Insert `setRite` right after the params object is constructed, and the compatibility check +
501 short-circuit right after `initParamsFromRequestPath()` and `validateRequestMethod()`, **before**
`loadDiocesanCalendarData()` (so no Roman-national/diocesan data loading or cache work happens for an Ambrosian
request):

```php
$this->CalendarParams = new CalendarParams();
$this->CalendarParams->setAllowedReturnTypes($this->allowedReturnTypes);
$this->CalendarParams->setRite($this->rite);            // NEW
$this->CalendarParams->setParams($params);
// ... existing lines up to and including:
$this->CalendarParams->initParamsFromRequestPath($this->requestPathParams);
$this->CalendarParams->validateRiteCompatibility();     // NEW (throws 400 on mismatch)
$this->validateRequestMethod($request);
if ($this->CalendarParams->Rite === Rite::AMBROSIAN) {  // NEW (501 until Plans 3-5)
    throw new ImplementationException(
        'The Ambrosian rite is planned but not yet available; only the Roman rite is currently implemented.'
    );
}
$this->loadDiocesanCalendarData();
// ... rest unchanged
```

Read the current `handle()` around those lines first and preserve the exact existing statements/order; you are only inserting the three NEW lines at the indicated points.

- [ ] **Step 6: Route the temporale-engine selection through the parsed rite**

In `calculateUniversalCalendar()` (Task-map: ~line 3882), change the hardcoded default to the request's rite. (This is
only reached for Roman now — Ambrosian 501s earlier — but it makes the seam honest for Plan 3.)

```php
// before:
$riteProfile = RiteProfileFactory::forRite(Rite::default());
// after:
$riteProfile = RiteProfileFactory::forRite($this->CalendarParams->Rite);
```

- [ ] **Step 7: Parse the `ambrosian` segment in the Router**

In `src/Router.php`:

1. Add `use LiturgicalCalendar\Api\Enum\Rite;` to the imports.
2. Immediately after `$route = array_shift($requestPathParts);` (Task-map: ~line 120), strip an optional leading `ambrosian` segment for the calendar route and record the rite:

   ```php
   $rite = Rite::default();
   if (($route === 'calendar' || $route === '') && ($requestPathParts[0] ?? null) === 'ambrosian') {
       $rite = Rite::AMBROSIAN;
       array_shift($requestPathParts);
   }
   ```

3. In the `case 'calendar':` block, pass the rite to the handler — change `new CalendarHandler($requestPathParts)` to
   `new CalendarHandler($requestPathParts, $rite)`. The existing 0/1/2/3-part shape `if/elseif` chain now runs on the
   already-stripped `$requestPathParts`, reproducing today's shapes for the remainder (e.g.
   `/calendar/ambrosian/diocese/milano_it` → `['diocese','milano_it']` → the existing 2-part `PathCategory` branch).

- [ ] **Step 8: Run the routing tests + golden master**

Run: `vendor/bin/phpunit phpunit_tests/Handlers/CalendarRiteRoutingTest.php phpunit_tests/Handlers/CalendarGoldenMasterTest.php -v`
Expected: routing tests 6/6 PASS (Roman 200; Ambrosian comune → 501; nation/non-whitelist/year<1976 → 400); golden master 9/9 (Roman byte-identical).
If an Ambrosian case returns 500 instead of 501, the short-circuit is placed after
`RiteProfileFactory::forRite(Rite::AMBROSIAN)` is reachable — move the 501 guard earlier (Step 5), before any code
that could call the factory or load Roman data.

- [ ] **Step 9: phpcs + PHPStan on all touched files**

Run: `vendor/bin/phpcs src/Router.php src/Handlers/CalendarHandler.php phpunit_tests/Handlers/CalendarRiteRoutingTest.php && composer analyse`
Expected: phpcs clean; PHPStan level 10 clean. Fix violations without changing behaviour; re-run Step 8 after any change.

- [ ] **Step 10: Handler-group regression**

Run: `vendor/bin/phpunit phpunit_tests/Handlers/ -v`
Expected: no NEW failures versus the base (compare tallies; the generator group is config-excluded so it will not run).

- [ ] **Step 11: Commit**

```bash
git add src/Router.php src/Handlers/CalendarHandler.php phpunit_tests/Handlers/CalendarRiteRoutingTest.php
git commit -m "feat(rite): parse /calendar/ambrosian, validate, return 501 (not yet impl)

Router strips an optional leading 'ambrosian' segment and threads the Rite
into CalendarHandler; the handler validates rite<->calendar/year (400 on
mismatch) and returns 501 for valid Ambrosian requests until the engine
lands (Plans 3-5). Every existing /calendar URL is unchanged; golden master
9/9. Temporale-engine selection now goes through the parsed rite.

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 4: OpenAPI — Ambrosian path items, 501 response, rite-conditional params

Document the new URL shapes and the 501, and record (from spec §3) that `epiphany`/`ascension`/`corpus_christi` are
inert under the Ambrosian rite. This is documentation; it must stay consistent with the Router logic from Task 3.

**Files:**

- Modify: `jsondata/schemas/openapi.json`
- (No test file — validated by `composer lint:openapi`.)

**Interfaces:**

- Consumes: existing `#/components/responses/*` and `#/components/parameters/*` refs; `ImplementationException`'s problem+json shape.
- Produces: new path items `/calendar/ambrosian`, `/calendar/ambrosian/{year}`,
  `/calendar/ambrosian/diocese/{calendar_id}`, `/calendar/ambrosian/diocese/{calendar_id}/{year}`; a reusable
  `#/components/responses/NotImplemented501`.

- [ ] **Step 1: Add the reusable 501 response component**

Read the existing `#/components/responses/BadRequest400` block in `jsondata/schemas/openapi.json` to copy its exact
shape (it references `application/problem+json`). Add a sibling `NotImplemented501` response next to it, describing
the RFC 9457 problem+json body returned by `ImplementationException` (status 501, title "Not Implemented"). Keep the
2-space indentation and hand-formatting — make surgical text edits; do NOT round-trip the whole file through a JSON
serializer.

- [ ] **Step 2: Add the four Ambrosian path items**

Read one existing calendar path item in full as a template: `/calendar` (Task-map: lines ~105–232) for the no-arg
shape, `/calendar/{year}` for the year shape, and `/calendar/diocese/{calendar_id}` /
`/calendar/diocese/{calendar_id}/{year}` for the diocese shapes. Add four new literal path keys mirroring those, but:

- **No `nation` variant** (Ambrosian has no national layer).
- Each `get`/`post` keeps the shared parameter refs, but add a description note on the operation (and/or on the
  `EpiphanyParam`/`AscensionParam`/`CorpusChristiParam` usage) stating these parameters are **ignored under the
  Ambrosian rite** (spec §3): Epiphany is fixed to Jan 6, Ascension to the 40th day (Thursday), Corpus Christi to its
  rite-fixed placement.
- Replace the success/response set so each includes `"501": { "$ref": "#/components/responses/NotImplemented501" }`
  and `"400": { "$ref": "#/components/responses/BadRequest400" }`. Since the Ambrosian calendar is not yet computed,
  mark the operation `summary`/`description` as "planned; currently returns 501 Not Implemented".
- Add a top-of-operation note that `/calendar/ambrosian/diocese/{calendar_id}` accepts only the Ambrosian dioceses (Milano, Bergamo, Novara, Lugano).

- [ ] **Step 3: Lint the OpenAPI schema**

Run: `composer lint:openapi`
Expected: passes (Redocly). Fix any structural errors (missing refs, duplicate keys, indentation) until clean.

- [ ] **Step 4: Sanity-check the JSON is well-formed and refs resolve**

Run: `php -r '$d=json_decode(file_get_contents("jsondata/schemas/openapi.json"), false, 512, JSON_THROW_ON_ERROR);
echo "paths: ", implode(\", \", array_values(array_filter(array_keys((array)$d->paths),
fn($k)=>str_contains($k,\"ambrosian\")))), \"\n\";'`
Expected: prints the four new `/calendar/ambrosian…` path keys.

- [ ] **Step 5: Run any OpenAPI-reconciliation tests (if present)**

Run: `vendor/bin/phpunit --filter OpenApi phpunit_tests 2>&1 | tail -5` (there is an OpenAPI response-schema
reconciliation test suite from issue #709; confirm it still passes with the new paths, or that it does not assert an
exhaustive path list that the additions would break).
Expected: PASS, or clearly not applicable. If a reconciliation test enumerates calendar paths and now fails because
the Ambrosian paths lack a live 200 contract, report it — the 501-only paths are intentional and the test may need a
documented exclusion (surface to the controller, do not weaken the test silently).

- [ ] **Step 6: Commit**

```bash
git add jsondata/schemas/openapi.json
git commit -m "docs(openapi): document /calendar/ambrosian paths (501) + rite-conditional params

Adds the four Ambrosian path items (no national layer), a reusable
NotImplemented501 response, and notes that epiphany/ascension/corpus_christi
are inert under the Ambrosian rite (spec 3). Paths are marked planned /
currently 501 until the engine lands.

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Self-Review

**Spec coverage (this plan = spec §3 request-side: routing, validation, discovery-of-inert-params; interim 501):**

- §3 optional `ambrosian` path segment, Roman default, existing URLs unchanged → Task 3 (Router) + golden master. ✓
- §3 whitelist (four dioceses), data-driven-later → Task 1 (`AmbrosianRiteProfile::SUPPORTED_DIOCESES`), consumed by Task 2 validation. Provisional-constant approach documented. ✓
- §3 no national layer / non-whitelisted diocese → 400 → Task 2 + Task 3 tests. ✓
- §6/scope decision: year floor 1976 → explicit 400 → Task 2 (`AMBROSIAN_YEAR_LOWER_LIMIT`) + Task 3 test. ✓
- §3 rite-conditional parameters (epiphany/ascension/corpus_christi inert) documented in OpenAPI → Task 4. ✓
- Interim behaviour = 501 (user decision) → Task 3 short-circuit via existing `ImplementationException`. ✓
- **Deferred (stated):** `supported_rites` metadata on `/calendars` and the data-driven whitelist → Plan 5 (needs the
  diocese files). `AmbrosianTemporale` + flipping 501→computation → Plan 3+. The positive whitelist→501 path is only
  reachable once Plan 5 creates the diocese files (noted in Global Constraints).

**Placeholder scan:** No "TBD"/"add validation"/"similar to". The one deliberate throw
(`AmbrosianRiteProfile::temporaleEngine()` → `\LogicException`) is asserted by a test (Task 1) and documented as
Plan-3 replacement. Line numbers are marked "Task-map: ~N" and each editing step says to read the current code first
and preserve exact statements — mechanical insertion, not guesswork.

**Type consistency:** `Rite` used identically across Router, `CalendarHandler::__construct(array, Rite)`,
`CalendarParams::$Rite`/`setRite(Rite)`. `AmbrosianRiteProfile::SUPPORTED_DIOCESES` (a `list<string>` const)
referenced with the same FQCN in Task 2's validator and Task 2's test. `validateRiteCompatibility(): void` defined in
Task 2, called in Task 3 Step 5. `ImplementationException` (501) and `ValidationException` (400) match
`StatusCode::NOT_IMPLEMENTED`/`BAD_REQUEST` used in Task 3's assertions.

**Scope check:** Four tasks, each an independently testable deliverable; Tasks 1–2 are additive/unit-tested (no
live-path change), Task 3 is the single integration point (so there is no exposed broken intermediate), Task 4 is
docs. Focused enough for one plan.
