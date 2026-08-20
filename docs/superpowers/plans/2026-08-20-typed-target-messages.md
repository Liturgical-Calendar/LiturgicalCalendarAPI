# Typed Target Messages Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or
> superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the inventory id the address a client sends, by adding typed `target` and typed calendar identity to the
Health WebSocket protocol alongside the legacy shapes.

**Architecture:** `executeValidation()` currently derives a filesystem path and a schema from client input across a
resolution phase and an execution phase. The execution phase is extracted into a seam that takes an already-resolved
path, kind and schema. v2 messages skip resolution entirely: `CheckableInventory::byId()` hands back a `CheckableItem`
whose `path`, `kind` and `schema` are exactly that seam's inputs. Legacy messages keep their resolution phase and enter
the same seam. Nothing legacy is deleted.

**Tech Stack:** PHP 8.4, Ratchet/ReactPHP, PHPUnit 12, PHPStan level 10.

**This is plan 2 of 2 for the section B spec.** Plan 1 (#815, merged `bb6b5f55`) grew `/validations` to 77 enumerated
items. This plan makes those ids addressable on the wire. Removing the legacy branch is a **separate, later** change
gated on UnitTestInterface#42 — and it is that follow-up, not this plan, that closes the `glob()` path-containment
exposure.

## Global Constraints

- Work in the worktree `/home/johnrdorazio/development/LiturgicalCalendar/LiturgicalCalendarAPI-msg` on branch
  `feat/806-typed-target-messages` (PR base: `development`). **Never commit in the main checkout**
  `/home/johnrdorazio/development/LiturgicalCalendar/LiturgicalCalendarAPI` — shared with other agents.
- Never use `git commit --no-verify`. Commits are GPG-signed; if signing fails, stop and ask.
- PSR-12 per `phpcs.xml`; short array syntax; 4-space indent; single quotes unless interpolating.
- PHPStan level 10 over `src` only.
- **Additive only.** `executeValidation`, `executeUnitTest` and the string form of `validateCalendar.calendar` keep
  working byte-for-byte. Every existing test must pass **unchanged** — that is what makes "additive" a claim rather
  than an intention. Do not delete a legacy arm, and do not "tidy" one.
- **Ids are opaque to clients.** The server may parse an id; the client only echoes what `/validations` published.
- **No new response `type` values.** Since UnitTestInterface PR #46 an unrecognised response `type` is painted as a
  visible failed check, so a new type would make every rejection look like a failing test. Rejections reuse the
  existing `echobot` error frame. `protocolError` is section G and is gated on section C.
- **Do not touch the `glob()` / path-containment gap** in `executeValidation()`. It is removed by the legacy-removal
  follow-up, per the maintainer's standing decision.
- Message shapes are declared as `@phpstan-type` aliases in `Health`'s class docblock. New shapes get aliases there,
  in the same style.
- Spec: `docs/superpowers/specs/2026-08-20-typed-target-design.md`.

---

## File Structure

| File                                                | Responsibility                                                   |
|-----------------------------------------------------|------------------------------------------------------------------|
| `src/Health.php`                                    | Action vocabulary, dispatch, the execution seam, v2 handlers     |
| `src/Models/ValidationsPath/CheckableInventory.php` | Gains a per-run reset so v2 lookups cannot serve a stale index   |
| `phpunit_tests/HealthValidateSourceTest.php`        | New: `validateSource` resolution, rejection, equivalence         |
| `phpunit_tests/HealthTypedCalendarTest.php`         | New: typed calendar identity on `validateCalendar` and `runTest` |
| `phpunit_tests/HealthSchemaCategoryTest.php`        | Extended: the id form of every slug it already covers            |
| `jsondata/schemas/openapi.json`                     | The WebSocket message shapes, where documented                   |

### The seam

`executeValidation()` today runs two phases. Lines ~610-732 resolve `$pathForSchema` and `$dataPath` from
`category`/`validate`/`sourceFile`/`sourceFolder`. Lines ~738-939 execute: a folder branch that globs and validates each
file, and a file branch that re-derives diocesan/national paths from the slug, then reads over HTTP or from disk and
validates.

Task 1 extracts the execution phase behind:

```php
private function runValidationSteps(
    string $dataPath,
    string $kind,
    ?string $schema,
    string $label,
    ConnectionInterface $to,
    ?string $runToken
): void
```

`$kind` is `'file'` or `'folder'` — the same vocabulary `CheckableItem::$kind` already uses, which is why the seam
lands there and not somewhere else. `$dataPath` is **final**: the legacy slug re-derivation moves up into the
resolution phase, so the seam never re-derives.

### Why a per-run inventory reset is in scope

`CheckableInventory::metadata()` memoizes with `self::$metadata ??= CalendarMetadataProvider::create()`. Under PHP-FPM
that lasts one request. `Health` is a long-running ReactPHP process, so there it lasts until restart.

Today that is masked: the legacy `sourceDataCheck` arms resolve `national-calendar-XX` and `diocesan-calendar-xxxxxx_xx`
generically, so a calendar created via `/data` mid-process is still checkable. **`validateSource` resolves solely
through `byId()`**, so for a v2 client that masking is gone and a newly-created calendar would be invisible until the
WebSocket server restarts.

An invalidation hook on the write path cannot work: `/data` writes happen in the HTTP process, and `Health` is a
different process that would never see the call. The fix that fits the actual topology is to **reset the memo once per
run**, bounding staleness to a single run at the cost of one rebuild per run rather than per lookup.

---

## Task 1: The execution-phase seam

Pure refactor. No behaviour change, no new action. Its deliverable is that the existing suites still pass while
`executeValidation()` has an entry point a v2 handler can call.

**Files:**

- Modify: `src/Health.php`

**Interfaces:**

- Produces: `runValidationSteps(string $dataPath, string $kind, ?string $schema, string $label, ConnectionInterface $to, ?string $runToken): void`.
  `$kind` is `'file'|'folder'`. `$label` is what the legacy code passes as `$validate` — it is the human label and the
  CSS-class fragment, and stays a plain string.
- Consumes: nothing new.

- [ ] **Step 1: Confirm the worktree**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LiturgicalCalendarAPI-msg
git rev-parse --show-toplevel   # must end in -msg
git branch --show-current       # expect: feat/806-typed-target-messages
ls vendor/bin/phpunit
```

- [ ] **Step 2: Record the legacy baseline before touching anything**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LiturgicalCalendarAPI-msg
vendor/bin/phpunit phpunit_tests/Health*.php 2>&1 | tail -5
```

Write the exact counts into your report. This is the number the refactor must not change. A refactor whose "before"
figure was never recorded cannot be shown to have preserved behaviour.

- [ ] **Step 3: Move the legacy slug re-derivation up into the resolution phase**

In `executeValidation()`, the file branch of the execution phase (~line 868) re-derives diocesan and national paths:

```php
if (preg_match('/^diocesan-calendar-([a-z]{6}_[a-z]{2})$/', $pathForSchema, $matches)) {
```

Move that block so it runs at the end of the resolution phase, assigning `$dataPath` there. The execution phase must
receive a path that is already final. Behaviour is unchanged because nothing between the two points reads `$dataPath`.

Verify that claim rather than assuming it: read the lines between and confirm none of them reads `$dataPath`. Say so
explicitly in your report.

- [ ] **Step 4: Extract the execution phase**

Move lines from the `if (property_exists($validation, 'sourceFolder') && is_string($validation->sourceFolder))` test
through the end of the execution phase into the new method, replacing the `sourceFolder` test with `$kind === 'folder'`
and the local `$validate` / `$dataPath` / `$schema` reads with the parameters. Call it from `executeValidation()`:

```php
$this->runValidationSteps(
    $dataPath,
    property_exists($validation, 'sourceFolder') ? 'folder' : 'file',
    $schema,
    $validate,
    $to,
    $runToken
);
```

Keep the method private and place it directly after `executeValidation()`.

- [ ] **Step 5: Prove behaviour is unchanged**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LiturgicalCalendarAPI-msg
vendor/bin/phpunit phpunit_tests/Health*.php 2>&1 | tail -5
composer lint
composer analyse
```

Expected: **identical** counts to Step 2. If any figure moved, the refactor changed behaviour — stop and report rather
than adjusting a test to match.

- [ ] **Step 6: Commit**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LiturgicalCalendarAPI-msg
git add src/Health.php
git commit -m "refactor(health): give executeValidation an execution-phase seam

The function resolves a path and a schema from client input, then executes
the checks. A typed target makes the first half unnecessary: the inventory
already knows the path, the kind and the schema. Splitting the two lets a v2
handler enter at the second half without duplicating it.

The legacy slug re-derivation moves up into the resolution phase so the seam
receives a final path and never re-derives one. Nothing between the two points
read \$dataPath, so this is behaviour-preserving.

No behaviour change: the legacy suites pass with identical counts.

Refs #806

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## Task 2: `validateSource`, and a per-run inventory reset

**Files:**

- Modify: `src/Health.php`, `src/Models/ValidationsPath/CheckableInventory.php`
- Create: `phpunit_tests/HealthValidateSourceTest.php`
- Modify: `phpunit_tests/HealthSchemaCategoryTest.php`

**Interfaces:**

- Consumes: `runValidationSteps()` from Task 1; `CheckableInventory::byId(string $id): ?CheckableItem`;
  `CheckableItem` public readonly `id`, `kind` (`'file'|'folder'`), `rite`, `region`, `label`, `schema` (`LitSchema`),
  `steps`, `path`.
- Produces: `CheckableInventory::reset(): void`.

- [ ] **Step 1: Write the failing tests**

Create `phpunit_tests/HealthValidateSourceTest.php`. Follow the stub-`ConnectionInterface`-plus-reflection pattern used
by `HealthCancelRunTest` and `HealthSchemaCategoryTest`; read one of them first and match it rather than inventing a
harness.

Cover, at minimum:

- A `validateSource` message carrying `{"target":{"id":"temporale:roman"}}` resolves to the same schema the legacy
  slug `proprium-de-tempore` resolves to.
- The same for a per-calendar id: `nation:roman:IT` versus `national-calendar-IT`.
- An unknown `target.id` is rejected via the existing `echobot` frame and no validation runs.
- `target` present but a string, not an object, is rejected as malformed.
- `target` absent is rejected by `validateMessageProperties()`.

- [ ] **Step 2: Run them to verify they fail**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LiturgicalCalendarAPI-msg
vendor/bin/phpunit phpunit_tests/HealthValidateSourceTest.php
```

Expected: FAIL — the action does not exist yet.

- [ ] **Step 3: Add the reset hook**

In `CheckableInventory`:

```php
    /**
     * Drop the memoized index.
     *
     * The memo is per-process, which is a request under PHP-FPM but the whole server lifetime in
     * Health's long-running ReactPHP process. A v2 `validateSource` resolves solely through
     * byId(), so without this a calendar created via /data would stay invisible to the WebSocket
     * until restart — the legacy slug arms used to mask that by resolving generically.
     *
     * An invalidation hook on the write path cannot work: /data writes happen in the HTTP process,
     * which Health never observes. Health therefore resets once per run, bounding staleness to a
     * single run at the cost of one rebuild per run rather than one per lookup.
     */
    public static function reset(): void
    {
        self::$items    = null;
        self::$metadata = null;
    }
```

Match the existing property names — read them rather than trusting these.

- [ ] **Step 4: Add the action**

Add to `ACTION_PROPERTIES`:

```php
        'validateSource'    => ['target'],
```

Add a `@phpstan-type` alias in the class docblock, in the existing style:

```php
 * @phpstan-type ValidateSource \stdClass&object{action:'validateSource',target:\stdClass&object{id:string},runToken?:string}
```

Add the dispatch case, and call `CheckableInventory::reset()` where a run begins — the same place `runToken` is first
stored for a connection, so a reset happens once per run rather than once per message. Read that block before editing;
`cancelRun` is deliberately exempt from it and must stay exempt.

```php
                case 'validateSource':
                    /** @var ValidateSource $messageReceived */
                    $this->validateSource($messageReceived, $from);
                    break;
```

- [ ] **Step 5: Implement the handler**

```php
    private function validateSource(\stdClass $message, ConnectionInterface $to): void
    {
        if (false === ( $message->target instanceof \stdClass ) || false === property_exists($message->target, 'id')) {
            $this->rejectMessage($to, 'validateSource requires a target object with an id.');
            return;
        }

        $id = $message->target->id;
        if (false === is_string($id)) {
            $this->rejectMessage($to, 'validateSource target id must be a string.');
            return;
        }

        $item = CheckableInventory::byId($id);
        if (null === $item) {
            $this->rejectMessage($to, "Unknown validation target: {$id}");
            return;
        }

        $this->runValidationSteps(
            $item->path,
            $item->kind,
            $item->schema->path(),
            $item->label,
            $to,
            $this->resolveRunToken($to)
        );
    }
```

`$this->resolveRunToken(ConnectionInterface $to): ?string` already exists and is what `executeValidation()` itself
calls — use it, do not read `runToken` off the message.

`rejectMessage()` does **not** exist. The only `echobot` frame is built inline in `onMessage()`'s `default` arm
(~line 420). Three call sites in this plan need it, so add one private helper next to `sendMessage()` and build the
frame exactly as that arm does:

```php
    /**
     * Reject a malformed or unresolvable v2 message.
     *
     * Reuses the existing `echobot` error shape deliberately. Since UnitTestInterface PR #46 an
     * unrecognised response `type` is painted as a visible failed check, so a dedicated
     * `protocolError` type would make every rejection look like a failing test. That type belongs
     * to #806 section G and is gated on section C.
     */
    private function rejectMessage(ConnectionInterface $to, string $text): void
    {
        $message       = new \stdClass();
        $message->type = 'echobot';
        $message->text = $text;
        $this->sendMessage($to, $message);
    }
```

Read the `default` arm first and match whatever it actually sets — if it carries fields beyond `type` and `text`,
carry them too.

- [ ] **Step 6: Extend the equivalence oracle, and add the round-trip**

Two distinct guarantees, both named by the spec. Write both.

**Equivalence.** In `HealthSchemaCategoryTest`, extend the existing provider so every legacy slug it covers is also
asserted in its id form, resolving to the same schema. This is what proves the new address is the *same* address.

**Round-trip.** In `HealthValidateSourceTest`, assert that **every** id `/validations` advertises resolves through
`CheckableInventory::byId()` to a non-null item whose `schema` is non-empty — driven from
`CheckableInventory::all()`, not a hand-written list, so a newly enumerated kind is covered the day it appears. This
is what proves the published list and the addressable set are the same set: an id a client can read but not send
would be worse than not publishing it.

- [ ] **Step 7: Verify**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LiturgicalCalendarAPI-msg
vendor/bin/phpunit phpunit_tests/Health*.php phpunit_tests/Models/ValidationsPath/
composer lint
composer analyse
```

- [ ] **Step 8: Commit**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LiturgicalCalendarAPI-msg
git add src/Health.php src/Models/ValidationsPath/CheckableInventory.php \
        phpunit_tests/HealthValidateSourceTest.php phpunit_tests/HealthSchemaCategoryTest.php
git commit -m "feat(health): address source validation by inventory id

validateSource takes a target object holding an id the server published, and
resolves it with one lookup instead of eight anchored preg_match arms. The
legacy executeValidation shape is untouched and its arms stay reachable.

The inventory memo is reset once per run. It is per-process, which is a request
under PHP-FPM but the server lifetime in Health, and a v2 message resolves only
through byId() — so without the reset a calendar created via /data would be
invisible until restart. The legacy arms used to mask that by resolving
generically. A write-path hook cannot work: /data writes happen in a different
process.

Refs #806

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## Task 3: Typed calendar identity on `validateCalendar`

**Files:**

- Modify: `src/Health.php`
- Create: `phpunit_tests/HealthTypedCalendarTest.php`

**Interfaces:**

- Consumes: `resolveRite(string $calendar, string $category, ?string $riteHint = null): Rite`;
  `validateCalendar(string $calendar, int $year, string $category, string $responseType, ConnectionInterface $to, ?string $riteHint = null): void`.
- Produces: v2 recognition on `validateCalendar` — `calendar` being an **object** rather than a string.

`calendar.kind` maps to the internal category: `general` and `rite` → `ritecalendar`, `national` → `nationalcalendar`,
`diocesan` → `diocesancalendar`. The internal vocabulary is not being renamed in this plan; only the wire changes.

- [ ] **Step 1: Write the failing tests**

Create `phpunit_tests/HealthTypedCalendarTest.php` covering:

- `{"calendar":{"kind":"diocesan","id":"lugano_ch","rite":"ambrosian"},"year":2026,"responseFormat":"JSON"}` reaches
  `validateCalendar()` with category `diocesancalendar`, calendar `lugano_ch`, rite Ambrosian.
- A string `calendar` still takes the legacy path with `responsetype`, unchanged.
- An unknown `kind` is rejected.
- A `rite` that disagrees with the calendar's actual rite is **rejected**, not silently resolved. Use a real diocese:
  `lugano_ch` is Ambrosian, so `{"kind":"diocesan","id":"lugano_ch","rite":"roman"}` must be rejected.
- `responseFormat` is honoured on the object form; `responsetype` remains honoured on the string form.

- [ ] **Step 2: Run them to verify they fail**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LiturgicalCalendarAPI-msg
vendor/bin/phpunit phpunit_tests/HealthTypedCalendarTest.php
```

- [ ] **Step 3: Implement**

`validateMessageProperties()` requires `['category','calendar','year','responsetype']` for `validateCalendar`. The v2
form has neither `category` nor `responsetype`, so that check must branch on whether `calendar` is an object **before**
the property list is applied. Make that branch explicit and commented — it is the discriminator the spec names, and a
reader must not have to infer it.

Map `kind` to the internal category, take the rite from `calendar.rite`, and verify it against the calendar's actual
rite before proceeding. The disagreement check is the point: `resolveRite()` currently treats the hint as authoritative
when it parses, which is right for a hint and wrong for an assertion.

- [ ] **Step 4: Verify and commit**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LiturgicalCalendarAPI-msg
vendor/bin/phpunit phpunit_tests/Health*.php
composer lint
composer analyse
git add src/Health.php phpunit_tests/HealthTypedCalendarTest.php
git commit -m "feat(health): accept a tagged calendar identity on validateCalendar

calendar becomes an object carrying kind, id and rite, which is also the v2
discriminator: the action name is unchanged because the action is unchanged.
category disappears from the wire, and responsetype becomes responseFormat on
the reshaped form only.

A rite that disagrees with the calendar's actual rite is rejected rather than
silently preferred. A hint may be guessed at; an assertion that is wrong is a
client bug and saying so is more useful than papering over it.

Refs #806

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## Task 4: `runTest`

**Files:**

- Modify: `src/Health.php`
- Modify: `phpunit_tests/HealthTypedCalendarTest.php`

**Interfaces:**

- Consumes: `executeUnitTest(string $test, string $calendar, int $year, string $category, ConnectionInterface $to, ?string $riteHint = null): void`,
  and the `kind`→category mapping and rite-disagreement check from Task 3. Factor those out of Task 3 rather than
  copying them — a second verbatim copy of the mapping is a defect, not a convenience.
- Produces: the `runTest` action.

- [ ] **Step 1: Write the failing tests**

Extend `HealthTypedCalendarTest` with `runTest` cases mirroring Task 3's: correct dispatch, unknown `kind` rejected,
rite disagreement rejected, and `executeUnitTest` still working unchanged.

Note the distinction the spec draws and assert it: `test:ambrosian:StIgnatiusOfLoyolaTest` is a source check reached by
`validateSource`, whereas `runTest` **runs** that test against a computed calendar. Both must work, addressed
differently.

- [ ] **Step 2: Run them, implement, verify**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LiturgicalCalendarAPI-msg
vendor/bin/phpunit phpunit_tests/HealthTypedCalendarTest.php
```

Add `'runTest' => ['test', 'calendar', 'year']` to `ACTION_PROPERTIES`, a `@phpstan-type RunTest` alias, and the
dispatch case delegating to `executeUnitTest()` with the mapped category and verified rite.

- [ ] **Step 3: Commit**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LiturgicalCalendarAPI-msg
git add src/Health.php phpunit_tests/HealthTypedCalendarTest.php
git commit -m "feat(health): add runTest with a tagged calendar identity

executeUnitTest keeps working. The new name is the v2 discriminator, as it is
for validateSource: a v1 client cannot accidentally emit it.

Refs #806

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## Task 5: Document the protocol

**Files:**

- Modify: `jsondata/schemas/openapi.json`
- Modify: `docs/superpowers/specs/2026-08-20-typed-target-design.md`

- [ ] **Step 1: Find where the WebSocket protocol is documented**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LiturgicalCalendarAPI-msg
grep -rn 'executeValidation\|validateCalendar' jsondata/schemas/ docs/ --include=*.json --include=*.md | grep -v superpowers | head
```

If the WebSocket messages are not in `openapi.json` at all — they may not be, being a different transport — do **not**
invent a place for them. Say so in your report and document the three shapes in the spec instead, marking it as the
protocol reference until section F's `hello` frame supersedes it.

- [ ] **Step 2: Record the v1/v2 mapping**

Whichever file, the reference must state: the three v2 shapes; how each is discriminated from its v1 form; that
`calendar.kind` is one of `general`, `national`, `diocesan`, `rite`; that ids are opaque and come from `/validations`;
and that v1 remains supported until UnitTestInterface#42 ships.

- [ ] **Step 3: Record the `steps` caveat from issue #819**

`GET /validations` publishes a `steps` array per item, and `Health` emits one frame per step — but the two use
different words. `CheckableInventory::STEPS` is `['exists', 'parses', 'validates']`; the emitted frame classes are
`file-exists`, `json-valid`, `schema-valid`. Nothing in the API relates them, so a client that takes `steps` literally
waits for a `.<label>.exists` frame that never arrives. Present since #811; filed as
[#819](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/issues/819).

**Do not change either vocabulary here.** The maintainer's decision is that section C dissolves this — once responses
are structured and DOM-agnostic, the frame carries the step identity and the CSS class becomes a client-side rendering
choice, leaving nothing to reconcile. Renaming the published values down to the frame classes now would bake
presentation detail into the discovery endpoint, which is the coupling `/validations` exists to remove, and C would
undo it immediately. Renaming the frames is not additive and would break the live UnitTestInterface runners, which
match on those classes.

What to write, wherever the `/validations` contract is documented: that **`steps` is authoritative for its length,
not for its values** — a client sizes a phase by `count(steps)`, which is correct today and is what replaces
UnitTestInterface#42's four hardcoded `* 3` constants — and that its values do not yet correspond to emitted frame
classes, with a pointer to #819.

This exists to prevent one specific failure: UnitTestInterface#42 shipping before section C, needing step names, and
hardcoding a client-side mapping. That would reintroduce exactly the duplication #806 exists to end, and it would
then be load-bearing.

- [ ] **Step 4: Amend the spec's status**

Add a short section recording what plan 2 shipped and what it deliberately did not: the legacy branch survives, and the
`glob()` containment exposure survives with it until the legacy-removal follow-up.

- [ ] **Step 5: Lint and commit**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LiturgicalCalendarAPI-msg
composer lint:md
composer lint:openapi
git add -A && git commit -m "docs(protocol): record the v2 message shapes and their discriminators

Refs #806

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## Task 6: Publish

**Files:** none — repository metadata only.

- [ ] **Step 1: Merge development and verify the merge result**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LiturgicalCalendarAPI-msg
git fetch origin development
git merge origin/development --no-edit
vendor/bin/phpunit phpunit_tests/Health*.php phpunit_tests/Models/ValidationsPath/
composer lint && composer analyse
```

CI tests the branch, not the merge result. If `Health.php` conflicts, resolve it by hand and re-run — an auto-merge that
succeeds textually is not evidence that two edits to the same function compose.

- [ ] **Step 2: Push and open the PR**

Base `development`. The body must state: the three new shapes and how each is discriminated; that **nothing legacy was
removed** and every existing test passes unchanged; the per-run inventory reset and why a write-path hook cannot work;
that the rite-disagreement case is rejected rather than resolved; and that the `glob()` exposure is **still open**,
closed only by the legacy-removal follow-up gated on UnitTestInterface#42.
