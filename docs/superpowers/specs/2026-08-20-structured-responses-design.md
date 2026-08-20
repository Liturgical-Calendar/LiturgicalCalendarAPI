# Structured, correlated responses for the Health WebSocket protocol

Design for sections **C** and **E** of [#806](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/issues/806),
plus the terminal frame from section D. Section B ([#815](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/pull/815),
[#820](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/pull/820)) made an id the address a client sends;
this makes the response say what it is about. Client counterpart:
[UnitTestInterface#42](https://github.com/Liturgical-Calendar/UnitTestInterface/issues/42).

**This document is the protocol reference for the response side of the Health WebSocket wire format** — the
envelope, which fields apply to which frame kind, correlation, and the terminal frame — the same way
[`2026-08-20-typed-target-design.md`](2026-08-20-typed-target-design.md) is the reference for the request side, both
until section F's `hello` handshake ships and supersedes them. That is still the case as this is written: `Health`
has not gained a `hello` action, and `jsondata/schemas/openapi.json` still does not describe this protocol — its
`/validations` entry documents only the HTTP discovery route (`GET /validations`), the WebSocket endpoint has no path
entry of its own, and a search of `jsondata/schemas/` and `docs/` for the wire action names (`validateSource`,
`validateCalendar`, `executeUnitTest`, `runTest`) turns up nothing outside these two design docs and `Health`'s own
docblock. Confirmed again here rather than assumed, because it is easy for a design doc's claim about its own
authority to go stale silently.

## Problem

A response today carries three fields the client can use: `type` (`success` | `error` | `echobot`), `text`, and
`classes` — a CSS selector the server builds, like `.national-calendar-it.json-valid`.

**The server knows Bootstrap exists.** `classes` is a presentation detail computed in the server and matched in the
browser with `document.querySelectorAll()`. It is also the *only* way a frame says what it is about: attribution is
CSS string matching. Change the client's markup and the server is wrong.

**Nothing says which request a frame answers.** `runToken` scopes a whole run; within a run, a frame is attributed by
parsing its selector. Several requests can be in flight on one connection — `Health` is async, `cachedGet()` returns
promises, and `$this->queue` / `inFlight` exist for exactly that reason.

**Nothing says a target is finished.** Both clients hardcode "3 responses per check" in four places. Section A
published `steps` per item so the count could come from the server, but the count is not reliable either:
[#821](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/issues/821) records that a file whose JSON fails
to decode emits two frames, not three.

**The published step vocabulary never reaches the wire.** `/validations` advertises `exists` / `parses` / `validates`;
the frames are classed `file-exists` / `json-valid` / `schema-valid`; nothing relates them
([#819](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/issues/819)).

## The principle

**A frame states what it is about, in the vocabulary the API already publishes.** Presentation is the client's
business.

## Scope

| In scope                                                        | Out of scope                                                         |
|-----------------------------------------------------------------|----------------------------------------------------------------------|
| Structured fields on every response, alongside the legacy three | Removing `type` / `text` / `classes` (gated on UnitTestInterface#42) |
| `requestId` correlation, client-supplied and echoed             | The `protocol` field and the `hello` handshake (#806 section F)      |
| `runId` as the published name for today's `runToken`            | Typed `protocolError` responses (#806 section G)                     |
| A terminal `complete` frame per target                          | Renaming the `.test-valid` CSS class (see below)                     |
| Retiring `FRAME_CLASS_FOR_STEP` from the test suite into `src`  | Strict request validation beyond what section B already rejects      |

## The envelope

Every response keeps `type`, `text` and `classes`, and **gains** structured fields. Existing clients ignore unknown
JSON keys; a v2 client ignores the legacy three. No negotiation, no gating, no second response builder — the same
additive approach that carried sections A, B and H.

```jsonc
{ "type": "success",                          // legacy projection
  "text": "...",                              // legacy, unchanged
  "classes": ".temporale-roman.json-valid",   // legacy projection
  "target": { "id": "temporale:roman" },
  "step": "parses",
  "status": "pass",
  "details": { "schema": "PropriumDeTempore.json", "errors": [] },
  "runToken": "run-a",                        // legacy, still emitted, at its historical position
  "runId": "run-a",                           // published name for the same value
  "requestId": "req-17" }                     // echoed, only when the client sent one
```

`runToken` is assigned before `runId` — not the reverse — because `runToken` already occupied that slot in every v1
frame and `sendMessage()` writes it first, then adds `runId` right after as the same value under its published name.
Keeping the legacy key at its historical position is what makes the byte-diff against v1 a pure *append*: every v1
key stays exactly where it was, and everything new lands after it. Both fields ride until legacy removal; publishing
`runId` now means UnitTestInterface#42 can adopt the new spelling in the same migration as everything else, rather
than doing it twice.

`step` carries the **published** vocabulary — `exists`, `parses`, `validates` — which is what closes #819. The two
vocabularies stop diverging because the structured field is the published one and the CSS class becomes a projection
of it.

### Not every field applies to every frame

Forcing one shape onto five kinds of frame is the mistake `category` made, and #806 exists to undo it.

| frame kind                | `target`                             | `step`                            | `status`        |
|---------------------------|--------------------------------------|-----------------------------------|-----------------|
| source-check step result  | inventory id                         | `exists` / `parses` / `validates` | `pass` / `fail` |
| calendar-validation step  | calendar identity + year             | `exists` / `parses` / `validates` | `pass` / `fail` |
| test run                  | test name + calendar identity + year | `validates`                       | `pass` / `fail` |
| terminal                  | as above, per kind, or `null`        | `complete`                        | omitted         |
| protocol rejection        | omitted                              | omitted                           | omitted         |

Checked against `src/Health.php` at `f9c8e1ec` (`sendStepResult()`, `sendCalendarStepResult()`, `sendTestResult()`,
`sendComplete()` and their call sites): the table still holds. The one addition worth naming is `terminal`'s
`target`: it is usually the same target as the step frames that preceded it, but it is `null` on the two paths that
start work without ever resolving one — a v1 `executeValidation` message's `sourceFolder`/`sourceFile` check that
never names an id, and the pre-existing diocese-metadata-lookup error (an early failure that emits no step frame at
all, only its own error frame, before still calling `sendComplete()` with `target: null`). Neither invents an id it
does not have; both are what "as above, per kind" means when the kind never had one.

There are **three** step-emitting kinds, not two. Counted against `src/Health.php` at `746a3bfd`, the 27 sites that
assign `classes` fall into four clusters: eleven for source checks (`.{slug}.{file-exists|json-valid|schema-valid}`,
plus one `.diocese-metadata`), **sixteen for calendar validation** (`.calendar-{id}.{step}.year-{year}` — the largest
cluster by some way), and two for test runs. Rejections assign none.

A calendar validation is a three-step pipeline exactly as a source check is; only the target and the fragment differ.
So one `sendStepResult()` serves both, taking the fragment and target descriptor from its caller.

A source check is a three-step pipeline; a test run is a single named outcome. Both therefore end with `complete`,
so **every operation has the same shape — N step frames, then a terminal** — and a client's phase logic does not
need to know which kind it asked for.

### Why a test run carries `step: "validates"`

`.test-valid` is not an assertion about the result. It **addresses the validity box** for a test; the box turns green
or red according to `status`. A class that encoded the outcome would be worse, because the client would need a
different selector depending on a result it has not parsed yet.

Issue #806's own wording — that `test-valid` is "the class for both a passing and a failing assertion" — reads this as a
defect. It is not; it is correct addressing, and this design keeps it.

Seen that way a test run *does* have a step-like identity: one named slot where a source check has three. So it
carries `step: "validates"`, and `status` distinguishes the outcome. That is what makes the shape uniform without
inventing a fake step.

### The `.test-valid` rename is deferred, deliberately

Existing clients match on `.test-valid`; renaming it is a breaking change, and this work is additive. The confusion
lives entirely in the legacy vocabulary, and a v2 client reads `step` and `status` and never sees the class.

The right moment is **legacy removal**, when clients change anyway and it costs nothing. `.test-validates` is the
name to use then, because it matches the `Step::VALIDATES` the structured frame already carries — the two
vocabularies converge rather than inventing a third word. Not `.test-validity`: nothing else in either vocabulary is
a noun.

## Known warts, on record rather than fixed

Two more places where the response tells two different truths depending on which vantage you ask from. Both
surfaced during implementation review; neither is a defect in the emitter that produces it, and neither can be
resolved without touching the legacy `text` contract this design leaves standing.

### `target.calendar` disagrees with itself for a `'VA'` request

A legacy `executeUnitTest` message with `calendar: 'VA'` — the historical marker for the rite-level (Vatican /
General Roman) calendar — produces two different values for `target.calendar` depending on which emitter reports it:
`'VA'` from `Health`'s own failure arms, because that is literally what the request asked for, and `'roman'` from
`LitTestRunner`, because the calendar object it gets back names no nation and falls back to its rite. Each is
correct from its own vantage — what was asked for, versus what came back — and reconciling them would mean picking
one emitter to lie about what it knows. `requestId` is the correlation key a client should use to tie a request's
frames together; `target.calendar` describes the target, it is not an id to match frames against.

### `details` is HTML-escaped on the XML path, not on the ICS path

An XML-format check's `details` entries arrive HTML-escaped — `&lt;this is not &lt;xml`, `&#039;root&#039;` —
because `retrieveXmlErrors()` escapes them for a `text` field a browser injects as markup. An ICS-format check's
`details` entries do not, because `formatIcsValidationErrors()` never escaped them. Both are faithful to their own
`text` — that is the constraint, not an oversight — and `text` is legacy and frozen for this migration, so
un-escaping one side would mean the two could no longer share a `text` value. This can only be reconciled at legacy
removal, when `text` stops being a contract and `details` can be escaped, or not, on its own terms.

## Emitters, and the legacy projection

Three typed emitters replace frame construction spread across 27 sites. Each owns one kind:

```php
private function sendStepResult(ConnectionInterface $to, string $classFragment, ?string $targetId,
    Step $step, Status $status, string $text, ?array $details, ?string $runToken, ?string $requestId): void

private function sendComplete(ConnectionInterface $to, string $classFragment, ?string $targetId,
    ?string $runToken, ?string $requestId): void

/** @param array{category:string,calendar:string,rite:Rite} $calendar */
private function sendTestResult(ConnectionInterface $to, string $classFragment, string $test,
    array $calendar, int $year, Status $status, string $text, ?array $details,
    ?string $runToken, ?string $requestId): void
```

`rejectMessage()` is unchanged: a protocol rejection has no target, no step and no status.

Two enums, matching the codebase's existing style: `Step` (`EXISTS`, `PARSES`, `VALIDATES`, `COMPLETE`) and `Status`
(`PASS`, `FAIL`).

The legacy fields become a projection computed **once**, inside the emitters:

```php
$frame->type    = $status === Status::PASS ? 'success' : 'error';
$frame->classes = '.' . $classFragment . '.' . self::FRAME_CLASS_FOR_STEP[$step->value];
```

Three consequences make this worth the refactor:

- `FRAME_CLASS_FOR_STEP` currently lives in the **test suite**, where section B put it as a stopgap and a reviewer
  correctly called it *"relocated hardcoding, not eliminated hardcoding"*. Here it becomes production code with one
  home: the legacy projection. It stops being a stopgap and becomes the thing it always described.
- `$classFragment` already exists and already handles both vocabularies — a v1 caller passes the slug,
  `validateSource` passes `cssClassFragmentForId($item->id)`. Centralising means the label-as-CSS defect fixed in
  #820 cannot recur, because nothing else computes a selector.
- Legacy removal becomes deleting the projection and the `$classFragment` parameter, rather than revisiting every
  emission site a second time.

## Correlation

`requestId` is **client-supplied and echoed**, validated as `^[A-Za-z0-9_\-]{1,64}$` — the same shape as `runToken`,
so junk cannot reach the wire. Absent means the field is omitted, which is every v1 request.

**It must not be stored per connection.** `runToken` is held in `$this->runTokens[$resourceId]` and injected in
`sendMessage()`, and mirroring that for `requestId` is the obvious implementation and is wrong. `Health` is async:
frames are emitted from promise closures, and several requests can be in flight on one connection. A per-connection
"current requestId" would stamp late-arriving frames with whichever request arrived most recently — **the exact
misattribution correlation exists to prevent**.

`requestId` is captured in the closure and passed explicitly to the emitter. Per-connection storage is right for
`runToken`, which scopes a run, and wrong for `requestId`, which scopes a request.

## The terminal frame

Emitted on **every** path that starts work, including early failure:

```text
happy path        exists(pass) → parses(pass) → validates(pass) → complete
JSON decode fails exists(pass) → parses(fail) → complete
file missing      exists(fail) → complete
test run          validates(pass|fail) → complete
unknown target    echobot rejection only — no complete, nothing was started
```

A client stops on `complete` and never counts frames. That makes **#821 moot rather than fixed**: the JSON-decode
path still emits fewer step frames than `count(steps)`, but nothing waits for the difference. `steps` remains an
upper bound and stops mattering for phase completion.

### A known limit: a throw inside a fulfil handler skips `complete` (#823)

The guarantee above is "every path that starts work terminates." It has one hole, and it is honest to state it next
to the guarantee rather than let a reader discover it independently.

`sendComplete()` is always the last statement of a promise's `onFulfilled` handler — at four sites: the URL-check
fulfil, the filesystem-check fulfil, `validateCalendar()`'s fulfil, and `executeUnitTest()`'s fulfil. If anything
*above* that last statement throws, `sendComplete()` never runs, and the request goes silent after whatever step
frames it had already managed to emit. This is not a case the code declines to handle — it is a case the code cannot
reach: React's promise implementation does not invoke the sibling `onRejected` when `onFulfilled` throws; the
rejection propagates to the *next* promise in the chain, and there is no next promise here. A v2 client that stops on
`complete`, exactly as this design tells it to, wedges on this path — the precise failure the terminal frame exists
to prevent.

Exposure is narrow rather than nil, and pre-existing rather than introduced by this work: `validateDataAgainstSchema()`
already catches `\Throwable` internally and is the principal throw source inside these handlers, and the `then(fulfil,
reject)` pair with no tail handler is the shape these four call sites had before this design. A client that used to
count `checks * 3` wedged on the same paths for the same reason; making `complete` explicit did not create the gap,
it just gave the gap a name. **Deliberately not fixed here** — wrapping four error paths is not a change to make
late in a branch whose own review is about the terminal frame's happy paths, not its exception plumbing. Filed as
[#823](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/issues/823).

## Error handling

| Condition                          | Behaviour                                                          |
|------------------------------------|--------------------------------------------------------------------|
| `requestId` present but malformed  | Rejected as a malformed message, via the existing `echobot` frame  |
| `requestId` absent                 | Field omitted from responses; everything else unchanged            |
| A step throws                      | Existing error frame, plus `status: "fail"`, then `complete`       |
| Target never resolves (unknown id) | `echobot` rejection, no `complete` — no work was started           |

"A step throws" assumes the throw is caught where the frame is composed. It is not, on the four fulfil-handler paths
described above (#823): there, a throw skips both the error frame and `complete`, rather than producing them.

No new response `type` is introduced. Since UnitTestInterface PR #46 an unrecognised `type` is painted as a visible
failed check, so a `protocolError` type would make every rejection look like a failing test. That belongs to section
G and is gated on this section shipping first.

## Testing

**Equivalence is the safety net, as it was for A and B.** Every legacy frame must be byte-identical for a v1
request — `type`, `text` and `classes` — asserted per frame kind. This matters more here than in section B, because
those fields are now produced by a projection rather than written inline: a projection that is subtly wrong looks
exactly like a projection that is right until something reads it.

**Correlation under interleaving.** A test that issues one request at a time cannot detect the misattribution this
design exists to prevent. There must be a test with two requests in flight on one connection, asserting each frame
carries its own `requestId`.

**Termination on every arm.** `complete` asserted on the happy path, on each failure arm, and on a test run — and
asserted *absent* after a rejection, since nothing was started.

**The projection asserted against literals**, not recomputed from the same enum the production code reads. Both
sides reading one source is how section B produced four tests that could not fail; the same trap is available here.

**Legacy suites pass unchanged**, which is what makes "additive" a claim rather than an intention.
