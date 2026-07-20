# Ambrosian Rite — Plan 1: Foundation (golden-master lock + Temporale extraction) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or
> superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Lock the current Roman calendar output behind a byte-identical golden-master regression suite, then extract
the Roman temporal-cycle computation out of `CalendarHandler` into a `RomanTemporale` class behind a `TemporaleEngine`
interface, selected via a `Rite` → `RiteProfile` seam — with zero change to Roman output.

**Architecture:** `CalendarHandler` currently computes everything inline (~5,430 lines). This plan introduces the
first strategy seam: a `Rite` enum selects a `RiteProfile`, which supplies a `TemporaleEngine`. `RomanTemporale`
receives the handler's shared mutable state (the `LiturgicalEventCollection`, `CalendarParams`,
`PropriumDeTemporeMap`, the message sink, and formatters) through an explicit `TemporaleContext` DTO, so the moved
methods keep behaving identically. The golden-master suite, built and frozen **before** any refactor, is the safety
net that proves behaviour preservation.

**Tech Stack:** PHP 8.4, PHPUnit 11, PSR-7 (`nyholm/psr7`), `swaggest/json-schema`, phpcs (PSR-12 + custom ruleset), PHPStan level 10, CaptainHook git hooks.

## Global Constraints

- **Working directory:** the git worktree
  `/tmp/claude-1000/-home-johnrdorazio-development-LiturgicalCalendar-LiturgicalCalendarAPI/6218483e-ad13-46d0-a0c6-2b582615e843/scratchpad/wt-ambrosian`
  on branch `feature/ambrosian-rite-integration`. Every command runs from there. **Verify with `git rev-parse
  --show-toplevel` before each task's first command** — it MUST print that worktree path, never
  `/home/johnrdorazio/development/LiturgicalCalendar/LiturgicalCalendarAPI` (the main checkout is shared by other
  agents and off-limits for commits/branch switches).
- **`vendor/` is not present in the worktree** (it is gitignored). Before running any `vendor/bin/*` command or
  committing (hooks need `vendor/bin/captainhook`), create a symlink once per session: `ln -s
  /home/johnrdorazio/development/LiturgicalCalendar/LiturgicalCalendarAPI/vendor "$(git rev-parse
  --show-toplevel)/vendor"`. Remove it (`rm -f vendor`) before finishing if you added it, so it is never staged.
- **Behaviour preservation is the prime directive.** No task in this plan may change any byte of Roman calendar
  output. The golden-master test (Task 1–2) is the gate; every extraction task ends by re-running it and confirming
  PASS.
- **Never bypass git hooks.** No `--no-verify`. Commits are GPG-signed; on `gpg: signing failed: Timeout`, STOP and
  report BLOCKED (ask the user to unlock GPG; never disable signing or edit `~/.gnupg`).
- **PHP standards:** short array syntax `[]`, 4-space indent, single quotes unless interpolating, PHP 8.1+ features.
  New code must pass `vendor/bin/phpcs <file>` and `composer analyse` (PHPStan level 10 scans `src` only). Use the
  modern `@phpstan-ignore <identifier>` form if ever needed, never bare `@phpstan-ignore-line`.
- **A phpunit run reporting "Skipped" for the target test is NOT a pass** — report exact tallies (assertions/tests).
  Handler tests run in-process (no server); do not start the server for this plan.
- **Commit message trailer:** end every commit body with `Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>`.
- **Namespaces:** PSR-4 root is `LiturgicalCalendar\Api\` → `src/`. New temporale classes live under
  `LiturgicalCalendar\Api\Models\Calendar\Temporale` → `src/Models/Calendar/Temporale/`. New profile classes under
  `LiturgicalCalendar\Api\Models\Calendar\Rite` → `src/Models/Calendar/Rite/`.

---

### Task 1: Golden-master fixture generator

Capture the current Roman calendar output for a representative matrix of requests, with volatile fields stripped, as
committed fixtures. This runs against the UNMODIFIED handler and freezes its behaviour.

**Files:**

- Create: `phpunit_tests/Support/GoldenMaster.php` (helper: normalize + fixture paths)
- Create: `phpunit_tests/Handlers/CalendarGoldenMasterGenerateTest.php` (writes fixtures; `@group golden-master-generate`)
- Create (by running it): `phpunit_tests/fixtures/golden-master/*.json`

**Interfaces:**

- Produces: `GoldenMaster::normalize(array $decoded): array` — returns the decoded response body with volatile keys
  removed (`metadata.date_time`, `metadata.request_headers`, `metadata.version`, `settings.timestamp` if present) so
  only deterministic calendar content remains. `GoldenMaster::MATRIX` — the array of `[label, method, uri, headers]`
  request cases. `GoldenMaster::fixturePath(string $label): string`.

- [ ] **Step 1: Write the normalize+matrix helper**

Create `phpunit_tests/Support/GoldenMaster.php`:

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Tests\Support;

/**
 * Shared helpers for the Roman-calendar golden-master regression suite.
 * The MATRIX exercises: missal-edition year gates (2002/2008), the two
 * national editions used by existing calendars, a diocesan overlay, and
 * both civil and liturgical year types (the double-year clone/merge pass).
 */
final class GoldenMaster
{
    /** @var list<array{label:string, uri:string, headers:array<string,string>}> */
    public const MATRIX = [
        ['label' => 'general-1997',        'uri' => '/calendar/1997',                       'headers' => ['Accept' => 'application/json']],
        ['label' => 'general-2001',        'uri' => '/calendar/2001',                       'headers' => ['Accept' => 'application/json']],
        ['label' => 'general-2005',        'uri' => '/calendar/2005',                       'headers' => ['Accept' => 'application/json']],
        ['label' => 'general-2020',        'uri' => '/calendar/2020',                       'headers' => ['Accept' => 'application/json']],
        ['label' => 'general-2024',        'uri' => '/calendar/2024',                       'headers' => ['Accept' => 'application/json']],
        ['label' => 'general-2025-litur',  'uri' => '/calendar/2025',                       'headers' => ['Accept' => 'application/json', 'X-Litcal-Year-Type' => 'LITURGICAL']],
        ['label' => 'nation-US-2023',      'uri' => '/calendar/nation/US/2023',             'headers' => ['Accept' => 'application/json']],
        ['label' => 'nation-IT-2023',      'uri' => '/calendar/nation/IT/2023',             'headers' => ['Accept' => 'application/json']],
        ['label' => 'diocese-romamo-2023', 'uri' => '/calendar/diocese/romamo_it/2023',     'headers' => ['Accept' => 'application/json']],
    ];

    /** @param array<string,mixed> $decoded */
    public static function normalize(array $decoded): array
    {
        unset(
            $decoded['metadata']['date_time'],
            $decoded['metadata']['request_headers'],
            $decoded['metadata']['version']
        );
        unset($decoded['settings']['timestamp']);
        return $decoded;
    }

    public static function fixturePath(string $label): string
    {
        return __DIR__ . '/../fixtures/golden-master/' . $label . '.json';
    }
}
```

- [ ] **Step 2: Confirm the volatile-field names against a real response**

Run one request through the existing in-process harness to see the actual `metadata` keys, so `normalize()` strips the
right ones (the map lists `version`, timestamps, `request_headers` under `metadata`; adjust the `unset()`s in
`normalize()` if the real keys differ, e.g. `date_time` vs `timestamp`):

Run: `vendor/bin/phpunit --filter testGetForCurrentYear phpunit_tests/Handlers/CalendarHandlerTest.php -v`
Expected: PASS (this is an existing test). If it references the response body, read
`phpunit_tests/Handlers/CalendarHandlerTest.php` to copy its exact `makeHandler()`/decode pattern into Step 3.

- [ ] **Step 3: Write the generator test**

Create `phpunit_tests/Handlers/CalendarGoldenMasterGenerateTest.php`. Model `makeHandler()` and the decode on the
existing `CalendarHandlerTest` + `AbstractHandlerTestCase` (`requestFor()`, `decodeJsonBody()`):

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Tests\Handlers;

use LiturgicalCalendar\Api\Handlers\CalendarHandler;
use LiturgicalCalendar\Api\Tests\Support\GoldenMaster;
use PHPUnit\Framework\Attributes\Group;

#[Group('golden-master-generate')]
final class CalendarGoldenMasterGenerateTest extends AbstractHandlerTestCase
{
    public function testWriteFixtures(): void
    {
        $dir = dirname(GoldenMaster::fixturePath('x'));
        if (!is_dir($dir)) {
            mkdir($dir, 0o775, true);
        }

        foreach (GoldenMaster::MATRIX as $case) {
            $handler = new CalendarHandler([]);
            $handler->setAllowedReturnTypes(['json', 'yaml', 'xml', 'ics']);
            $request = $this->requestFor('GET', $case['uri'], $case['headers']);
            $response = $handler->handle($request);
            self::assertSame(200, $response->getStatusCode(), $case['label']);

            $decoded = $this->decodeJsonBody($response);
            $normalized = GoldenMaster::normalize($decoded);
            file_put_contents(
                GoldenMaster::fixturePath($case['label']),
                json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n"
            );
        }

        self::assertCount(count(GoldenMaster::MATRIX), glob($dir . '/*.json') ?: []);
    }
}
```

Note: `new CalendarHandler([])` + `setAllowedReturnTypes()` mirrors `CalendarHandlerTest::makeHandler()`; if that test
constructs the handler differently (e.g. passes request path parts), copy its exact construction. The URI's path parts
are parsed by `CalendarParams::initParamsFromRequestPath`, so passing `[]` and letting the request URI drive params
matches how `AbstractHandlerTestCase::requestFor()` builds the `ServerRequest`. If `requestFor` does not populate
`requestPathParams`, construct the handler with the path parts from the URI instead (e.g. `new
CalendarHandler(['nation','US','2023'])`).

- [ ] **Step 4: Generate the fixtures**

Run: `vendor/bin/phpunit --group golden-master-generate phpunit_tests/Handlers/CalendarGoldenMasterGenerateTest.php -v`
Expected: PASS; 9 files created under `phpunit_tests/fixtures/golden-master/`. Verify: `ls phpunit_tests/fixtures/golden-master/ | wc -l` prints `9`.

- [ ] **Step 5: Sanity-check a fixture is real calendar data**

Run: `head -30 phpunit_tests/fixtures/golden-master/general-2024.json`
Expected: JSON containing `litcal`, `settings`, `metadata` with real events (e.g. an `event_key` like `Easter`), and NO `date_time`/`version`/`request_headers` under `metadata`.

- [ ] **Step 6: Commit**

```bash
git add phpunit_tests/Support/GoldenMaster.php phpunit_tests/Handlers/CalendarGoldenMasterGenerateTest.php phpunit_tests/fixtures/golden-master/
git commit -m "test(calendar): capture Roman golden-master fixtures before rite refactor

Freezes current Roman calendar output for a 9-case matrix (missal year
gates, US/IT national editions, diocesan overlay, liturgical year type)
with volatile metadata stripped, as the behaviour-preservation baseline
for the Rite strategy extraction.

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 2: Golden-master regression test

Assert the live handler still produces the frozen fixtures. This is the gate re-run after every later task.

**Files:**

- Create: `phpunit_tests/Handlers/CalendarGoldenMasterTest.php`

**Interfaces:**

- Consumes: `GoldenMaster::MATRIX`, `GoldenMaster::normalize()`, `GoldenMaster::fixturePath()` (Task 1).
- Produces: a data-provider-driven test `testMatchesFixture` used as the behaviour-preservation gate by Tasks 3–5.

- [ ] **Step 1: Write the regression test**

Create `phpunit_tests/Handlers/CalendarGoldenMasterTest.php`:

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Tests\Handlers;

use LiturgicalCalendar\Api\Handlers\CalendarHandler;
use LiturgicalCalendar\Api\Tests\Support\GoldenMaster;
use PHPUnit\Framework\Attributes\DataProvider;

final class CalendarGoldenMasterTest extends AbstractHandlerTestCase
{
    /** @return iterable<string, array{0: array{label:string, uri:string, headers:array<string,string>}}> */
    public static function caseProvider(): iterable
    {
        foreach (GoldenMaster::MATRIX as $case) {
            yield $case['label'] => [$case];
        }
    }

    /** @param array{label:string, uri:string, headers:array<string,string>} $case */
    #[DataProvider('caseProvider')]
    public function testMatchesFixture(array $case): void
    {
        $fixture = GoldenMaster::fixturePath($case['label']);
        self::assertFileExists($fixture, "Missing fixture for {$case['label']}; run the generate test first.");

        $handler = new CalendarHandler([]);
        $handler->setAllowedReturnTypes(['json', 'yaml', 'xml', 'ics']);
        $response = $handler->handle($this->requestFor('GET', $case['uri'], $case['headers']));
        self::assertSame(200, $response->getStatusCode(), $case['label']);

        $actual = GoldenMaster::normalize($this->decodeJsonBody($response));
        $expected = json_decode((string) file_get_contents($fixture), true, 512, JSON_THROW_ON_ERROR);

        self::assertEquals($expected, $actual, "Golden-master drift for {$case['label']}");
    }
}
```

- [ ] **Step 2: Run the gate — it must pass against the unmodified handler**

Run: `vendor/bin/phpunit phpunit_tests/Handlers/CalendarGoldenMasterTest.php -v`
Expected: PASS, 9 tests, 0 failures. If any case fails here, the normalize() strip-list is wrong (a volatile field
leaked) — fix `GoldenMaster::normalize()` and regenerate (Task 1 Step 4).

- [ ] **Step 3: Commit**

```bash
git add phpunit_tests/Handlers/CalendarGoldenMasterTest.php
git commit -m "test(calendar): add Roman golden-master regression gate

Re-runs the frozen 9-case matrix against the live handler and asserts
byte-identical normalized output. This is the behaviour-preservation
gate for the Rite strategy extraction.

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 3: Add the `Rite` enum

The rite selector. Additive; nothing consumes it yet.

**Files:**

- Create: `src/Enum/Rite.php`
- Test: `phpunit_tests/Enum/RiteTest.php`

**Interfaces:**

- Produces: `Rite` (string-backed enum) with cases `ROMAN = 'roman'`, `AMBROSIAN = 'ambrosian'`, and `Rite::default(): self` returning `Rite::ROMAN`.

- [ ] **Step 1: Write the failing test**

Create `phpunit_tests/Enum/RiteTest.php`:

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Tests\Enum;

use LiturgicalCalendar\Api\Enum\Rite;
use PHPUnit\Framework\TestCase;

final class RiteTest extends TestCase
{
    public function testDefaultIsRoman(): void
    {
        self::assertSame(Rite::ROMAN, Rite::default());
    }

    public function testFromValue(): void
    {
        self::assertSame(Rite::AMBROSIAN, Rite::from('ambrosian'));
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit phpunit_tests/Enum/RiteTest.php -v`
Expected: FAIL — `Class "LiturgicalCalendar\Api\Enum\Rite" not found`.

- [ ] **Step 3: Implement the enum**

Create `src/Enum/Rite.php`:

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Enum;

/**
 * The liturgical rite a calendar request is computed under.
 * ROMAN is the default and applies to every existing route; AMBROSIAN
 * is selected by an optional leading `ambrosian` path segment.
 */
enum Rite: string
{
    case ROMAN     = 'roman';
    case AMBROSIAN = 'ambrosian';

    public static function default(): self
    {
        return self::ROMAN;
    }
}
```

- [ ] **Step 4: Run to verify it passes + lint**

Run: `vendor/bin/phpunit phpunit_tests/Enum/RiteTest.php -v && vendor/bin/phpcs src/Enum/Rite.php phpunit_tests/Enum/RiteTest.php`
Expected: PASS, 2 tests; phpcs clean.

- [ ] **Step 5: Commit**

```bash
git add src/Enum/Rite.php phpunit_tests/Enum/RiteTest.php
git commit -m "feat(rite): add Rite enum (roman default, ambrosian)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 4: `TemporaleEngine` interface, `TemporaleContext` DTO, and `RomanTemporale` — extract the temporale block

Move the contiguous Roman temporale computation out of `CalendarHandler::calculateUniversalCalendar()` into
`RomanTemporale`, threading shared state through `TemporaleContext`. Gate on the golden master.

**Scope of the move (exact):** the block in `calculateUniversalCalendar()` (`src/Handlers/CalendarHandler.php`)
currently invoking, in order — `calculateEasterTriduum()` (line 970), `calculateChristmasEpiphany()` (993),
`calculateAscensionPentecost()` (1128), `calculateSundaysMajorSeasons()` (1160), `calculateAshWednesday()` (1250),
`calculateWeekdaysHolyWeek()` (1263), `calculateEasterOctave()` (1287), `calculateMobileSolemnitiesOfTheLord()` (1321)
— plus the helper `createPropriumDeTemporeLiturgicalEventByKey()` (950). `loadPropriumDeTemporeData()` (823) and
`loadPropriumDeTemporeI18nData()` (801) STAY on the handler (they populate `$this->PropriumDeTempore`, which is passed
into the context); the ferial-fill temporale methods that are interleaved with sanctorale (`calculateWeekdaysAdvent`,
`calculateWeekdaysChristmasOctave`, `calculateWeekdaysLent`, `calculateWeekdaysEaster`,
`calculateWeekdaysOrdinaryTime`, `calculateChristmasWeekdays*`, `calculateSaturdayMemorialBVM`,
`calculateSundaysChristmasOrdinaryTime`) STAY on the handler for now — they are extracted in a later plan. This keeps
the first seam contiguous and behaviour-identical.

**Files:**

- Create: `src/Models/Calendar/Temporale/TemporaleEngine.php`
- Create: `src/Models/Calendar/Temporale/TemporaleContext.php`
- Create: `src/Models/Calendar/Temporale/RomanTemporale.php`
- Modify: `src/Handlers/CalendarHandler.php` (`calculateUniversalCalendar()` around lines 4161–4171; remove the moved private methods)
- Gate: `phpunit_tests/Handlers/CalendarGoldenMasterTest.php` (Task 2)

**Interfaces:**

- Consumes: `LiturgicalEventCollection`, `CalendarParams`, `PropriumDeTemporeMap`, `LocaleDateFormatter` (existing models); `Rite` (Task 3).
- Produces:
  - `interface TemporaleEngine { public function buildTemporale(TemporaleContext $ctx): void; }`
  - `final class TemporaleContext` with readonly public props: `LiturgicalEventCollection $cal`, `CalendarParams
    $params`, `PropriumDeTemporeMap $propriumDeTempore`, `LocaleDateFormatter $localeDateFormatter`, and a mutable
    message sink `array &$messages` exposed via `addMessage(string $m): void` (append-only, preserving order).
  - `final class RomanTemporale implements TemporaleEngine`.

- [ ] **Step 1: Confirm the golden-master gate is green before touching the handler**

Run: `vendor/bin/phpunit phpunit_tests/Handlers/CalendarGoldenMasterTest.php`
Expected: PASS, 9 tests. (If not green, stop — do not refactor on a red baseline.)

- [ ] **Step 2: Create the interface**

Create `src/Models/Calendar/Temporale/TemporaleEngine.php`:

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Models\Calendar\Temporale;

/**
 * Computes a rite's temporal cycle (movable/major-season events) into the
 * shared LiturgicalEventCollection carried by the TemporaleContext.
 * Implementations MUST be re-runnable per year (the calendar handler runs
 * the pipeline twice for LITURGICAL year_type) and MUST NOT hold per-request
 * state between calls.
 */
interface TemporaleEngine
{
    public function buildTemporale(TemporaleContext $ctx): void;
}
```

- [ ] **Step 3: Create the context DTO**

Create `src/Models/Calendar/Temporale/TemporaleContext.php`. The message sink is passed by reference so the moved code
appends to the handler's existing `$this->Messages` array in the exact same order:

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Models\Calendar\Temporale;

use LiturgicalCalendar\Api\Models\Calendar\LiturgicalEventCollection;
use LiturgicalCalendar\Api\Models\PropriumDeTemporeMap;
use LiturgicalCalendar\Api\Params\CalendarParams;
use LiturgicalCalendar\Api\LocaleDateFormatter;

/**
 * Carries the handler's shared mutable state into a TemporaleEngine so the
 * extracted computation behaves identically to the inline version.
 */
final class TemporaleContext
{
    /** @param array<int,string> $messages message sink, appended in order */
    public function __construct(
        public readonly LiturgicalEventCollection $cal,
        public readonly CalendarParams $params,
        public readonly PropriumDeTemporeMap $propriumDeTempore,
        public readonly LocaleDateFormatter $localeDateFormatter,
        private array &$messages
    ) {
    }

    public function addMessage(string $message): void
    {
        $this->messages[] = $message;
    }
}
```

Note: confirm the exact namespace of `LocaleDateFormatter` by reading its `namespace` line in `src/` (the map shows
the handler property `$localeDateFormatter` of type `LocaleDateFormatter`); adjust the `use` above to match. If
`LiturgicalEventCollection` is under `LiturgicalCalendar\Api\Models\Calendar`, keep as written (the map confirms
`src/Models/Calendar/LiturgicalEventCollection.php`).

- [ ] **Step 4: Create `RomanTemporale` and move the methods verbatim**

Create `src/Models/Calendar/Temporale/RomanTemporale.php`. Move the bodies of `calculateEasterTriduum`,
`calculateChristmasEpiphany`, `calculateAscensionPentecost`, `calculateSundaysMajorSeasons`, `calculateAshWednesday`,
`calculateWeekdaysHolyWeek`, `calculateEasterOctave`, `calculateMobileSolemnitiesOfTheLord`, and
`createPropriumDeTemporeLiturgicalEventByKey` **verbatim** from `CalendarHandler`, rewriting only their state access:

- `$this->Cal` → `$ctx->cal`
- `$this->CalendarParams` → `$ctx->params`
- `$this->PropriumDeTempore` → `$ctx->propriumDeTempore`
- `$this->localeDateFormatter` → `$ctx->localeDateFormatter`
- `$this->Messages[] = X;` → `$ctx->addMessage(X);`
- calls between the moved methods (e.g. `$this->createPropriumDeTemporeLiturgicalEventByKey(...)`) →
  `$this->createPropriumDeTemporeLiturgicalEventByKey(..., $ctx)` (thread `$ctx` as a parameter to each moved private
  method).

Skeleton (fill each method body with the rewritten original):

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Models\Calendar\Temporale;

use LiturgicalCalendar\Api\Models\Calendar\LiturgicalEvent;
// add the same `use` imports the moved code relies on (Utilities, DateTime,
// LitColor, LitGrade, \DateInterval, etc.) — copy them from CalendarHandler's
// import block for every symbol the moved bodies reference.

final class RomanTemporale implements TemporaleEngine
{
    public function buildTemporale(TemporaleContext $ctx): void
    {
        $this->calculateEasterTriduum($ctx);
        $this->calculateChristmasEpiphany($ctx);
        $this->calculateAscensionPentecost($ctx);
        $this->calculateSundaysMajorSeasons($ctx);
        $this->calculateAshWednesday($ctx);
        $this->calculateWeekdaysHolyWeek($ctx);
        $this->calculateEasterOctave($ctx);
        $this->calculateMobileSolemnitiesOfTheLord($ctx);
    }

    private function createPropriumDeTemporeLiturgicalEventByKey(?string $key, TemporaleContext $ctx): LiturgicalEvent
    {
        // moved verbatim from CalendarHandler::createPropriumDeTemporeLiturgicalEventByKey,
        // with $this->PropriumDeTempore -> $ctx->propriumDeTempore and
        // $this->Cal -> $ctx->cal
    }

    // private function calculateEasterTriduum(TemporaleContext $ctx): void { ... }
    // ...one method per moved calculation, bodies rewritten as above...
}
```

Important: the map notes `createPropriumDeTemporeLiturgicalEventByKey` throws `ServiceUnavailableException` when the
key is missing — keep that `use` and behaviour exactly. Do not change any date arithmetic, message text, gettext
calls, or ordering.

- [ ] **Step 5: Wire the handler to delegate to `RomanTemporale`**

In `src/Handlers/CalendarHandler.php`, inside `calculateUniversalCalendar()`, replace the eight inline calls (the
block after `loadPropriumDeTemporeData()`, lines ~4161–4171) with a single delegation, and delete the now-moved
private methods (their old definitions at 950, 970, 993, 1128, 1160, 1250, 1263, 1287, 1321):

```php
// after $this->loadPropriumDeTemporeData(); (line ~4153)
$temporaleMessages = [];
$temporaleContext = new \LiturgicalCalendar\Api\Models\Calendar\Temporale\TemporaleContext(
    $this->Cal,
    $this->CalendarParams,
    $this->PropriumDeTempore,
    $this->localeDateFormatter,
    $temporaleMessages
);
(new \LiturgicalCalendar\Api\Models\Calendar\Temporale\RomanTemporale())->buildTemporale($temporaleContext);
foreach ($temporaleMessages as $temporaleMessage) {
    $this->Messages[] = $temporaleMessage;
}
```

Note on message ordering: the original code appended temporale messages to `$this->Messages` inline as each event was
computed. Because no non-temporale message is emitted between `loadPropriumDeTemporeData()` and
`calculateMobileSolemnitiesOfTheLord()` in the original flow, draining `$temporaleMessages` immediately after the
delegation preserves order. The golden master (which includes `messages`) will catch any drift. Prefer wiring the same
`&$this->Messages` array by reference if `TemporaleContext` can accept it directly — i.e. pass `$this->Messages` as
the by-ref arg — which removes the drain loop entirely and is exactly order-preserving. Use that form:

```php
$temporaleContext = new TemporaleContext(
    $this->Cal, $this->CalendarParams, $this->PropriumDeTempore, $this->localeDateFormatter, $this->Messages
);
```

(Add `use LiturgicalCalendar\Api\Models\Calendar\Temporale\{RomanTemporale, TemporaleContext};` to the handler's import block.)

- [ ] **Step 6: Run the golden-master gate**

Run: `vendor/bin/phpunit phpunit_tests/Handlers/CalendarGoldenMasterTest.php -v`
Expected: PASS, 9 tests, 0 failures. If any case drifts (esp. `messages` or an event date), the culprit is a
state-access rewrite error or a message-ordering change — diff the failing fixture against the actual output, fix the
moved method, re-run. Do NOT edit the fixtures.

- [ ] **Step 7: Static analysis + lint the new/changed files**

Run: `vendor/bin/phpcs src/Models/Calendar/Temporale/ src/Handlers/CalendarHandler.php && composer analyse`
Expected: phpcs clean; PHPStan level 10 clean. Fix any new violations (e.g. missing `use`, nullable types) without changing behaviour, then re-run Step 6.

- [ ] **Step 8: Run the full handler test group to confirm no collateral breakage**

Run: `vendor/bin/phpunit phpunit_tests/Handlers/ -v`
Expected: PASS (or the same pre-existing skips as before this plan — compare tallies; nothing new should fail).

- [ ] **Step 9: Commit**

```bash
git add src/Models/Calendar/Temporale/ src/Handlers/CalendarHandler.php
git commit -m "refactor(calendar): extract Roman temporale into RomanTemporale engine

Moves the contiguous temporale block (Easter Triduum through mobile
Solemnities of the Lord) out of CalendarHandler behind a TemporaleEngine
interface, threading shared state via TemporaleContext. Golden-master
regression suite confirms byte-identical Roman output.

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 5: `RiteProfile` seam — select the temporale engine by rite

Introduce the profile that later plans hang precedence/missal-resolution/vocabularies off, and have the handler obtain
its temporale engine through it (still Roman-only, still byte-identical).

**Files:**

- Create: `src/Models/Calendar/Rite/RiteProfile.php` (interface)
- Create: `src/Models/Calendar/Rite/RomanRiteProfile.php`
- Create: `src/Models/Calendar/Rite/RiteProfileFactory.php`
- Modify: `src/Handlers/CalendarHandler.php` (obtain the engine via the factory)
- Test: `phpunit_tests/Models/Calendar/Rite/RiteProfileFactoryTest.php`
- Gate: `phpunit_tests/Handlers/CalendarGoldenMasterTest.php`

**Interfaces:**

- Consumes: `Rite` (Task 3), `TemporaleEngine`/`RomanTemporale` (Task 4).
- Produces:
  - `interface RiteProfile { public function rite(): Rite; public function temporaleEngine(): TemporaleEngine; }`
  - `final class RomanRiteProfile implements RiteProfile`
  - `final class RiteProfileFactory { public static function forRite(Rite $rite): RiteProfile; }` — returns
    `RomanRiteProfile` for `Rite::ROMAN`; throws `\InvalidArgumentException` for `Rite::AMBROSIAN` (wired in Plan 2+).

- [ ] **Step 1: Write the failing test**

Create `phpunit_tests/Models/Calendar/Rite/RiteProfileFactoryTest.php`:

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Tests\Models\Calendar\Rite;

use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Models\Calendar\Rite\RiteProfileFactory;
use LiturgicalCalendar\Api\Models\Calendar\Rite\RomanRiteProfile;
use LiturgicalCalendar\Api\Models\Calendar\Temporale\RomanTemporale;
use PHPUnit\Framework\TestCase;

final class RiteProfileFactoryTest extends TestCase
{
    public function testRomanProfileSuppliesRomanTemporale(): void
    {
        $profile = RiteProfileFactory::forRite(Rite::ROMAN);
        self::assertInstanceOf(RomanRiteProfile::class, $profile);
        self::assertSame(Rite::ROMAN, $profile->rite());
        self::assertInstanceOf(RomanTemporale::class, $profile->temporaleEngine());
    }

    public function testAmbrosianNotYetWired(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        RiteProfileFactory::forRite(Rite::AMBROSIAN);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit phpunit_tests/Models/Calendar/Rite/RiteProfileFactoryTest.php -v`
Expected: FAIL — `RiteProfileFactory` not found.

- [ ] **Step 3: Implement the interface, Roman profile, and factory**

Create `src/Models/Calendar/Rite/RiteProfile.php`:

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Models\Calendar\Rite;

use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Models\Calendar\Temporale\TemporaleEngine;

/**
 * Bundles the rite-specific strategies. This plan wires only the temporale
 * engine; later plans add precedenceResolver(), missalResolver(), and the
 * season/grade/colour vocabularies.
 */
interface RiteProfile
{
    public function rite(): Rite;

    public function temporaleEngine(): TemporaleEngine;
}
```

Create `src/Models/Calendar/Rite/RomanRiteProfile.php`:

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Models\Calendar\Rite;

use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Models\Calendar\Temporale\RomanTemporale;
use LiturgicalCalendar\Api\Models\Calendar\Temporale\TemporaleEngine;

final class RomanRiteProfile implements RiteProfile
{
    public function rite(): Rite
    {
        return Rite::ROMAN;
    }

    public function temporaleEngine(): TemporaleEngine
    {
        return new RomanTemporale();
    }
}
```

Create `src/Models/Calendar/Rite/RiteProfileFactory.php`:

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Models\Calendar\Rite;

use LiturgicalCalendar\Api\Enum\Rite;

final class RiteProfileFactory
{
    public static function forRite(Rite $rite): RiteProfile
    {
        return match ($rite) {
            Rite::ROMAN     => new RomanRiteProfile(),
            Rite::AMBROSIAN => throw new \InvalidArgumentException('Ambrosian rite not yet wired'),
        };
    }
}
```

- [ ] **Step 4: Run to verify it passes + lint**

Run: `vendor/bin/phpunit phpunit_tests/Models/Calendar/Rite/RiteProfileFactoryTest.php -v && vendor/bin/phpcs src/Models/Calendar/Rite/`
Expected: PASS, 2 tests; phpcs clean.

- [ ] **Step 5: Route the handler's temporale call through the profile**

In `src/Handlers/CalendarHandler.php`, replace the direct `new RomanTemporale()` from Task 4 Step 5 with a profile
obtained from the rite. Until Plan 2 parses the rite from the path, default to Roman:

```php
$riteProfile = \LiturgicalCalendar\Api\Models\Calendar\Rite\RiteProfileFactory::forRite(
    \LiturgicalCalendar\Api\Enum\Rite::default()
);
$temporaleContext = new TemporaleContext(
    $this->Cal, $this->CalendarParams, $this->PropriumDeTempore, $this->localeDateFormatter, $this->Messages
);
$riteProfile->temporaleEngine()->buildTemporale($temporaleContext);
```

(Add the `use` for `RiteProfileFactory` and `Rite` to the handler import block.)

- [ ] **Step 6: Run the golden-master gate + handler group**

Run: `vendor/bin/phpunit phpunit_tests/Handlers/CalendarGoldenMasterTest.php phpunit_tests/Handlers/ -v`
Expected: PASS, golden master 9/9 green; no new handler failures.

- [ ] **Step 7: Static analysis**

Run: `composer analyse`
Expected: PHPStan level 10 clean.

- [ ] **Step 8: Commit**

```bash
git add src/Models/Calendar/Rite/ src/Handlers/CalendarHandler.php phpunit_tests/Models/Calendar/Rite/
git commit -m "feat(rite): select temporale engine via RiteProfile (Roman default)

Adds the RiteProfile seam and routes the handler's temporale computation
through RiteProfileFactory::forRite(Rite::default()). Ambrosian throws
until Plan 2 wires rite parsing. Golden master remains byte-identical.

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Self-Review

**Spec coverage (this plan = spec §2 spine + §7.1 golden master, temporale slice only):**

- §2 `Rite` enum → Task 3. ✓
- §2 `RiteProfile` bundling strategies → Task 5 (temporale wired; precedence/missal/vocabularies stubbed for later plans, called out explicitly in the `RiteProfile` docblock). ✓
- §2 `TemporaleEngine` interface + `RomanTemporale` extraction → Task 4. ✓
- §7.1 golden-master lock before touching Roman code → Tasks 1–2, gated in Tasks 4–5. ✓
- **Deferred to later plans (stated):** `MissalResolver` extraction, `PrecedenceResolver` extraction, ferial-fill
  temporale methods, rite path parsing/validation (Plan 2), `AmbrosianTemporale` (Plan 3), precedence (Plan 4),
  data/schemas (Plan 5), validation (Plan 6). Not gaps — sequenced.

**Placeholder scan:** No "TBD"/"add error handling"/"similar to". The one intentional deferral (`RiteProfileFactory`
throwing for Ambrosian) is asserted by a test (Task 5 Step 1) and documented. The verbatim method-move in Task 4 Step
4 is a precise mechanical instruction (named methods + exact state-access rewrites + line numbers), not a placeholder
— the bodies already exist in the repo. ✓

**Type consistency:** `TemporaleEngine::buildTemporale(TemporaleContext): void` is defined in Task 4 and consumed
unchanged in Tasks 4–5. `TemporaleContext` constructor arg order/types are identical in the DTO (Task 4 Step 3) and
both handler wirings (Task 4 Step 5, Task 5 Step 5). `RiteProfile::temporaleEngine(): TemporaleEngine` matches its use
in the factory test. `GoldenMaster::normalize/fixturePath/MATRIX` signatures are identical across Tasks 1–2. ✓

**Known verification hooks the implementer must confirm at runtime (not placeholders — explicit checks):** exact
volatile metadata key names (Task 1 Step 2), the `CalendarHandler` construction/`requestFor` param-population pattern
(Task 1 Step 3 note), and the `LocaleDateFormatter` namespace (Task 4 Step 3 note). Each has a concrete verification
command and a fallback instruction.
