# Structured Responses Implementation Plan

**Historical record — not the protocol reference.** This plan describes the design *as scoped*, before it was built.
Several details changed during implementation: the class table shipped as `FrameFamily::CLASS_FOR_STEP` rather than
`Health::FRAME_CLASS_FOR_STEP`, `sendStepResult()` takes a `?\stdClass $target` rather than a `?string $targetId`, and
the terminal frame ended up gated on `requestId`. The task bodies below are left as written on purpose — the gap
between what was planned and what shipped is the useful part of keeping them. For the contract as shipped, read
`docs/superpowers/specs/2026-08-20-structured-responses-design.md`, which is authoritative.

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or
> superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make every Health WebSocket response say what it is about — target, step, status — in the vocabulary the
API already publishes, alongside the legacy fields.

**Architecture:** Frame construction is spread across 27 sites in three clusters. Each cluster collapses into a typed
emitter that takes the structured data and derives `type` and `classes` from it as a *legacy projection*. The
projection is computed once, so the two vocabularies cannot drift, and legacy removal later is deleting a function
rather than editing 27 sites again.

**Tech Stack:** PHP 8.4, Ratchet/ReactPHP, PHPUnit 12, PHPStan level 10.

**Sections C and E of #806, plus D's terminal frame.** Closes #819 (the published step vocabulary reaches the wire)
and moots #821 (a client stops on `complete` instead of counting). The `protocol` field and the `hello` handshake
stay with section F.

## Global Constraints

- Work in the worktree `/home/johnrdorazio/development/LiturgicalCalendar/LiturgicalCalendarAPI-frames` on branch
  `feat/806-structured-responses` (PR base: `development`). **Never commit in the main checkout**
  `/home/johnrdorazio/development/LiturgicalCalendar/LiturgicalCalendarAPI` — shared with other agents.
- Never use `git commit --no-verify`. Commits are GPG-signed; if signing fails, stop and ask.
- PSR-12 per `phpcs.xml`; short array syntax; 4-space indent; single quotes unless interpolating.
- PHPStan level 10 over `src` only.
- **Additive only.** Every legacy frame stays byte-identical: same `type`, same `text`, same `classes`, in the same
  order. Every pre-existing test must pass **unchanged**.
- **No new response `type` values.** Since UnitTestInterface PR #46 an unrecognised `type` is painted as a visible
  failed check. `protocolError` is section G.
- **Do not touch the `glob()` / path-containment gap** — closed by the legacy-removal follow-up, standing decision.
- **Do not rename `.test-valid`.** It addresses the validity box; the rename belongs to legacy removal.
- Tests that build a `Health` must use `HealthQueueIsolationTrait` — `React\EventLoop\Loop` runs from a shutdown
  function, so a queued `cachedGet()` URL is fetched for real against the shared checkout at process end.
- Spec: `docs/superpowers/specs/2026-08-20-structured-responses-design.md`.

---

## File Structure

| File                                          | Responsibility                                                   |
|-----------------------------------------------|------------------------------------------------------------------|
| `src/Enum/Step.php`                           | New: `EXISTS`, `PARSES`, `VALIDATES`, `COMPLETE`                 |
| `src/Enum/Status.php`                         | New: `PASS`, `FAIL`                                              |
| `src/Health.php`                              | The three emitters, the legacy projection, `requestId` threading |
| `phpunit_tests/HealthFrameProjectionTest.php` | New: the projection asserted against literals                    |
| `phpunit_tests/HealthCorrelationTest.php`     | New: `requestId` echo, and interleaved in-flight attribution     |

### The three clusters

Counted against `746a3bfd`. All line numbers shift as you work — re-derive them, do not trust these.

| cluster             | sites | fragment shape                                      | owner                                                                                              |
|---------------------|-------|-----------------------------------------------------|----------------------------------------------------------------------------------------------------|
| source check        | 11    | `.{slug}.{file-exists / json-valid / schema-valid}` | `runValidationSteps`, `processValidationData`, `handleValidationDataError`, `sendFolderStepResult` |
| calendar validation | 16    | `.calendar-{id}.{step}.year-{year}`                 | `validateCalendar`'s two promise closures                                                          |
| test run            | 2     | `.{test}.year-{year}.test-valid`                    | `executeUnitTest`                                                                                  |

`sendFolderStepResult()` already derives `type` from an error list — it is the partial precedent the new emitter
generalises, and it is replaced by it.

---

## Task 1: The vocabulary, the projection, and the source-check cluster

**Files:**

- Create: `src/Enum/Step.php`, `src/Enum/Status.php`, `phpunit_tests/HealthFrameProjectionTest.php`
- Modify: `src/Health.php`

**Interfaces:**

- Produces: `Step` and `Status` enums; `Health::sendStepResult(ConnectionInterface $to, string $classFragment,
  ?string $targetId, Step $step, Status $status, string $text, ?array $details, ?string $runToken): void`;
  `Health::FRAME_CLASS_FOR_STEP`, a `array<string,string>` const mapping `Step->value` to the legacy CSS fragment.
- Consumes: `cssClassFragmentForId()` from #820.

- [ ] **Step 1: Confirm the worktree and record the baseline**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LiturgicalCalendarAPI-frames
git rev-parse --show-toplevel   # must end in -frames
git branch --show-current       # expect: feat/806-structured-responses
vendor/bin/phpunit phpunit_tests/Health*.php 2>&1 | tail -5
```

Write the counts into your report. Every later step compares against them.

- [ ] **Step 2: Add the enums**

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Enum;

/**
 * A step in a check, in the vocabulary `GET /validations` publishes.
 *
 * `CheckableInventory::STEPS` advertises `exists`, `parses`, `validates`; until now those words never
 * reached the wire, because the frames were classed `file-exists` / `json-valid` / `schema-valid` and
 * nothing related the two vocabularies (#819). This enum is the published vocabulary, and
 * {@see \LiturgicalCalendar\Api\Health::FRAME_CLASS_FOR_STEP} projects it onto the legacy class names.
 *
 * `COMPLETE` is not a check; it is the terminal frame that lets a client stop without counting (#821).
 */
enum Step: string
{
    case EXISTS    = 'exists';
    case PARSES    = 'parses';
    case VALIDATES = 'validates';
    case COMPLETE  = 'complete';
}
```

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Enum;

/**
 * The outcome of a step.
 *
 * Explicit on the wire so a client need not infer it from a CSS class. Note the legacy `.test-valid`
 * class is an *address* for the validity box, not a claim about the outcome — the box is coloured by
 * this status. That is why the class is the same for a pass and a fail, and why it is correct.
 */
enum Status: string
{
    case PASS = 'pass';
    case FAIL = 'fail';
}
```

- [ ] **Step 3: Write the failing projection test**

Create `phpunit_tests/HealthFrameProjectionTest.php`. Assert the projection against **literals**, never against
`FRAME_CLASS_FOR_STEP` itself — both sides reading one source is how section B produced four tests that could not
fail.

```php
    public static function projectionProvider(): array
    {
        return [
            'exists passes'    => ['temporale-roman', Step::EXISTS,    Status::PASS, 'success', '.temporale-roman.file-exists'],
            'parses fails'     => ['temporale-roman', Step::PARSES,    Status::FAIL, 'error',   '.temporale-roman.json-valid'],
            'validates passes' => ['nation-roman-IT', Step::VALIDATES, Status::PASS, 'success', '.nation-roman-IT.schema-valid'],
        ];
    }

    #[DataProvider('projectionProvider')]
    public function testTheLegacyFieldsAreProjectedFromTheStructuredOnes(
        string $fragment,
        Step $step,
        Status $status,
        string $expectedType,
        string $expectedClasses
    ): void {
        $conn   = $this->stubConnection();
        $health = $this->newHealth();
        $this->invoke($health, 'sendStepResult', [$conn, $fragment, 'temporale:roman', $step, $status, 'text', null, null]);

        self::assertCount(1, $conn->sent);
        $frame = json_decode($conn->sent[0]);
        self::assertSame($expectedType, $frame->type, 'type is projected from status');
        self::assertSame($expectedClasses, $frame->classes, 'classes is projected from fragment and step');
        self::assertSame($step->value, $frame->step, 'the published step name reaches the wire');
        self::assertSame($status->value, $frame->status);
    }
```

Follow the stub-`ConnectionInterface`-plus-reflection pattern in `HealthValidateSourceTest`; read it and match it
rather than inventing a harness. Use `HealthQueueIsolationTrait`.

- [ ] **Step 4: Run it to verify it fails**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LiturgicalCalendarAPI-frames
vendor/bin/phpunit phpunit_tests/HealthFrameProjectionTest.php
```

Expected: FAIL — `sendStepResult` does not exist.

- [ ] **Step 5: Add the projection and the emitter**

Move `FRAME_CLASS_FOR_STEP` **out of the test suite** and into `Health` as a const. Section B put it in the tests as
a stopgap and a reviewer called it *"relocated hardcoding, not eliminated hardcoding"*; this is the home it always
described.

```php
    /**
     * The legacy CSS class fragment for each published step.
     *
     * The wire carries two vocabularies during migration: `step` is what `GET /validations` publishes, and
     * `classes` is what the current clients match on. This is the projection between them, and it exists in
     * exactly one place so they cannot drift — the label-as-selector defect fixed in #820 happened because
     * every emitter built its own selector. Deleting this const and the `$classFragment` parameter is most of
     * what legacy removal will be.
     */
    private const FRAME_CLASS_FOR_STEP = [
        'exists'    => 'file-exists',
        'parses'    => 'json-valid',
        'validates' => 'schema-valid'
    ];
```

Then `sendStepResult()`, placed next to `sendMessage()`. It carries the structured fields and derives the legacy
ones. `COMPLETE` has no legacy class and must not reach this method — `sendComplete()` in Task 4 owns it.

- [ ] **Step 6: Convert the source-check cluster**

Eleven sites. `handleValidationDataError()` becomes three calls:

```php
        $this->sendStepResult($to, $validate, null, Step::EXISTS, Status::FAIL,
            "Data file $dataPath is not readable: " . $e->getMessage(), null, $runToken);
        $this->sendStepResult($to, $validate, null, Step::PARSES, Status::FAIL,
            "Could not decode the Data file $dataPath as JSON because it is not readable", null, $runToken);
        $this->sendStepResult($to, $validate, null, Step::VALIDATES, Status::FAIL,
            "Unable to verify schema for dataPath {$dataPath} and category {$category} since Data file $dataPath does not exist or is not readable",
            null, $runToken);
```

The `text` strings are reproduced **exactly**. Replace `sendFolderStepResult()` with `sendStepResult()`, mapping its
error list to a `Status` and building the same text.

Leave `.diocese-metadata` alone: it is not a step, and forcing it into `Step` would be the collapse this design
avoids. It keeps its inline construction.

- [ ] **Step 7: Verify byte-identity**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LiturgicalCalendarAPI-frames
vendor/bin/phpunit phpunit_tests/Health*.php
composer lint
composer analyse
```

Expected: the Step 1 counts, **plus** your new tests. Any pre-existing test that changed expectation means the
projection is wrong — fix the projection, never the test.

- [ ] **Step 8: Commit**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LiturgicalCalendarAPI-frames
git add src/Enum/Step.php src/Enum/Status.php src/Health.php phpunit_tests/HealthFrameProjectionTest.php
git commit -m "feat(health): project the legacy frame fields from structured ones

A frame said what it was about only through a CSS selector the server built,
so attribution was string matching and the server knew Bootstrap existed.
sendStepResult() takes the target, step and status, and derives type and
classes from them.

step carries the vocabulary GET /validations publishes, which is what closes
 #819: the two vocabularies stop diverging because one is now a projection of
the other rather than an unrelated third word.

FRAME_CLASS_FOR_STEP moves out of the test suite, where section B left it as a
stopgap, into the projection it always described. Computing it in one place is
also why the label-as-selector defect of #820 cannot recur.

Refs #806, #819

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## Task 2: The calendar-validation cluster

The largest cluster: sixteen sites in `validateCalendar()`'s two promise closures.

**Files:** Modify `src/Health.php`

**Interfaces:** Consumes `sendStepResult()` from Task 1.

- [ ] **Step 1: Convert the sites**

The fragment is `"calendar-$calendar"` and the year rides in the class, so these sites need the year in the
fragment. Extend the fragment argument rather than adding a parameter — the fragment is already "whatever addresses
this frame":

```php
        $this->sendStepResult($to, "calendar-$calendar", null, Step::EXISTS, Status::PASS,
            "The $category of $calendar for the year $year exists", null, $runToken);
```

This does **not** reproduce `.calendar-$calendar.file-exists.year-$year` — the year segment is missing. Resolve it by
having `sendStepResult()` accept an optional trailing class segment, or by passing the fragment pre-composed. Decide
which, implement it, and record the reasoning in your report; do not guess. The constraint is that the emitted
`classes` string is character-identical to what the site emitted before.

- [ ] **Step 2: Verify byte-identity, then commit**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LiturgicalCalendarAPI-frames
vendor/bin/phpunit phpunit_tests/Health*.php
composer lint && composer analyse
git add src/Health.php
git commit -m "feat(health): structure the calendar-validation frames

Sixteen of the twenty-seven emission sites, and the only cluster whose class
carries a year segment. Same projection, same emitter; the frames are
byte-identical.

Refs #806

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## Task 3: The test-run cluster

**Files:** Modify `src/Health.php`

**Interfaces:** Produces `sendTestResult()`.

- [ ] **Step 1: Add the emitter and convert both sites**

A test run is one named outcome, not a pipeline, so it carries `step: "validates"` and `status` says the rest. Both
sites keep emitting `.{test}.year-{year}.test-valid` unchanged — that class **addresses the validity box**, it does
not claim an outcome, and renaming it is a breaking change reserved for legacy removal.

```php
    /**
     * @param array{category:string,calendar:string,rite:Rite} $calendar
     */
    private function sendTestResult(ConnectionInterface $to, string $test, array $calendar, int $year,
        Status $status, string $text, ?array $details, ?string $runToken): void
```

- [ ] **Step 2: Verify and commit**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LiturgicalCalendarAPI-frames
vendor/bin/phpunit phpunit_tests/Health*.php
composer lint && composer analyse
git add src/Health.php
git commit -m "feat(health): structure the test-run frames

A test run is a single named outcome rather than a three-step pipeline, so it
carries step: validates and lets status say the rest.

 .test-valid is unchanged. It addresses the validity box, which the client
colours by the result -- #806 reads it as an oddity because the class is the
same for a pass and a fail, but a class that encoded the outcome would be
worse: the client would need a different selector depending on a result it has
not parsed yet.

Refs #806

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## Task 4: Correlation

**Files:** Modify `src/Health.php`; create `phpunit_tests/HealthCorrelationTest.php`

**Interfaces:** All four emitters gain a trailing `?string $requestId = null`.

- [ ] **Step 1: Write the failing tests, including the interleaved case**

Cover: `requestId` echoed on every frame when the client sends one; the field **absent** when it does not; a
malformed `requestId` rejected as a malformed message; and — the one that matters —

**two requests in flight on one connection, each frame carrying its own `requestId`.**

A test issuing one request at a time cannot detect the misattribution this whole section exists to prevent. Build it
by queueing two `validateSource` messages with different `requestId`s before the loop drains.

- [ ] **Step 2: Implement**

`requestId` is validated as `^[A-Za-z0-9_\-]{1,64}$`, the same shape as `runToken`.

**Do not store it per connection.** `runToken` lives in `$this->runTokens[$resourceId]` and is injected in
`sendMessage()`; mirroring that is the obvious implementation and is wrong. `Health` is async — frames come from
promise closures, and `$this->queue` / `inFlight` exist because several requests can be in flight at once. A
per-connection "current requestId" would stamp late frames with whichever request arrived most recently: **exactly
the misattribution correlation exists to prevent.**

Capture it in the closure and pass it explicitly. Per-connection is right for `runToken`, which scopes a run, and
wrong for `requestId`, which scopes a request.

Also emit `runId` alongside `runToken`, same value. Publishing the new name now means UnitTestInterface#42 adopts it
without a second migration.

- [ ] **Step 3: Verify, prove the interleaving test discriminates, commit**

Mutate: store `requestId` per connection instead of threading it. Confirm the interleaved test fails and the
sequential ones pass — that contrast is the evidence the test is worth having. Restore, record the output.

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LiturgicalCalendarAPI-frames
vendor/bin/phpunit phpunit_tests/Health*.php
composer lint && composer analyse
git add src/Health.php phpunit_tests/HealthCorrelationTest.php
git commit -m "feat(health): correlate every frame with the request that caused it

Attribution was CSS string matching within a run. requestId is client-supplied
and echoed, so a client maps a frame to its own state without parsing a
selector.

It is threaded through the closures rather than stored per connection, which
would have been the obvious implementation and the wrong one: Health is async,
frames are emitted from promise closures, and a per-connection value would
stamp late frames with whichever request arrived most recently -- the exact
misattribution this exists to prevent. runToken stays per-connection because it
scopes a run, not a request.

runId is published alongside runToken so the client migrates once.

Refs #806

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## Task 5: The terminal frame

**Files:** Modify `src/Health.php`; extend `phpunit_tests/HealthFrameProjectionTest.php`

**Interfaces:** Produces `sendComplete()`. Consumes `requestId` from Task 4.

### The frame is gated on `requestId`

**Emit `complete` only when the request carried a `requestId`.** This is the one place the additive envelope is not
enough, and the reason is worth stating because the frame looks harmless.

A new frame changes the *stream*, not just a frame's contents. A v1 client survives the unknown shape —
`testResults.js` never throws and warns on a missing `classes` — but `resources.js` computes
`expectedResponses = checks * 3` and compares with `>=`. So it reaches its threshold on the three real frames,
advances to the next phase, and then the late `complete` frames increment whichever counter is now active, finishing
the *following* phase early too. A cascading miscount with nothing failing visibly, which is worse than a crash.

`requestId` is already the v2 opt-in signal, and a client adopting `complete` is a client adopting correlation — they
migrate together. So the gate costs nothing and keeps the byte-identity guarantee intact.

The **fields** stay additive and always-on, exactly as designed. Only the new frame gates.

- [ ] **Step 1: Write the failing tests**

Assert `complete` on **every** path that starts work, and assert it **absent** after a rejection:

```text
happy path        exists(pass) → parses(pass) → validates(pass) → complete
JSON decode fails exists(pass) → parses(fail) → complete
file missing      exists(fail) → complete
test run          validates(pass|fail) → complete
unknown target    echobot rejection only — no complete, nothing was started
no requestId      no complete at all — a v1 client must not receive it (see above)
```

The failure arms are the point. A client stops on `complete`, so an arm that terminates without one wedges it
forever — which is the bug this frame exists to prevent, in the same shape as the folder-branch wedge fixed by
`ea29b678`.

- [ ] **Step 2: Implement**

`sendComplete()` emits `step: "complete"`, **no** `status`, and no legacy `classes` — there is no legacy class for a
step that never existed in the legacy protocol, and inventing one would put a selector on the wire that no client
matches. `type` is `'success'`: the frame reports that the run finished, not that it passed.

- [ ] **Step 3: Verify, prove it can fail, commit**

Delete one `sendComplete()` call, confirm the corresponding arm's test fails naming that arm, restore. Record the
output.

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LiturgicalCalendarAPI-frames
vendor/bin/phpunit phpunit_tests/Health*.php
composer lint && composer analyse
git add src/Health.php phpunit_tests/HealthFrameProjectionTest.php
git commit -m "feat(health): emit a terminal frame on every path that starts work

Both clients hardcode three responses per check in four places, and section A's
published steps could not replace the constant because the count is not
reliable: a file whose JSON fails to decode emits two frames, not three (#821).

A client now stops on complete instead of counting, which makes #821 moot
rather than fixed -- the short path still emits fewer step frames, but nothing
waits for the difference.

Emitted on the failure arms too. An arm that terminates without one wedges a
client forever, which is the same shape as the folder-branch wedge fixed in
ea29b678.

Refs #806, #821

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## Task 6: Document the response contract

**Files:** Modify `docs/superpowers/specs/2026-08-20-structured-responses-design.md`; whichever file documents the
protocol reference (section B established the design spec as that reference — confirm, do not assume).

- [ ] **Step 1: Record the contract**

The envelope and which fields apply to which frame kind; that `step` carries the published vocabulary; that a client
stops on `complete` rather than counting; that `requestId` is echoed only when sent; that `runId` and `runToken` are
the same value under two names during migration.

- [ ] **Step 2: Close out #819 and #821 in the docs**

`/validations`' `steps` description and the `openapi.json` `/validations` description currently carry caveats saying
the step names correspond to nothing on the wire (#819) and that the length is an upper bound (#821). **Both change
meaning here.** Update them: the names now reach the wire as `step`, and the length no longer matters for phase
completion because `complete` is explicit. Say what a client should do now, not what it used to have to tolerate.

- [ ] **Step 3: Lint and commit**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LiturgicalCalendarAPI-frames
composer lint:md && composer lint:openapi
git add -A
git commit -m "docs(protocol): record the structured response contract

Refs #806, #819, #821

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## Task 7: Publish

- [ ] **Step 1: Merge development and verify the merge result**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LiturgicalCalendarAPI-frames
git fetch origin development
git merge origin/development --no-edit
vendor/bin/phpunit phpunit_tests/Health*.php phpunit_tests/Models/ValidationsPath/
composer lint && composer analyse
```

CI tests the branch, not the merge result. If `Health.php` conflicts, resolve by hand and re-run — an auto-merge that
succeeds textually is not evidence that two edits to one function compose.

- [ ] **Step 2: Push and open the PR**

Base `development`. The body must state: the envelope and that it is additive; that every legacy frame is
byte-identical and every pre-existing test passes unchanged; that `#819` closes and `#821` becomes moot, with the
reason for each; that `requestId` is threaded rather than stored per connection, and why; that `.test-valid` is
deliberately unchanged; and that the `protocol` field and the handshake remain with section F.
