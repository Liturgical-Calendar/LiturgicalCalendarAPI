# Structured, correlated responses for the Health WebSocket protocol

Design for sections **C** and **E** of [#806](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/issues/806),
plus the terminal frame from section D. Section B ([#815](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/pull/815),
[#820](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/pull/820)) made an id the address a client sends;
this makes the response say what it is about. Client counterpart:
[UnitTestInterface#42](https://github.com/Liturgical-Calendar/UnitTestInterface/issues/42).

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
  "runId": "run-a",                           // today's runToken, published name
  "runToken": "run-a",                        // legacy, still emitted
  "requestId": "req-17",                      // echoed, only when the client sent one
  "target": { "id": "temporale:roman" },
  "step": "parses",
  "status": "pass",
  "details": { "schema": "PropriumDeTempore.json", "errors": [] } }
```

`runId` and `runToken` both ride until legacy removal. Publishing the new name now means UnitTestInterface#42 can
adopt it without a second migration later.

`step` carries the **published** vocabulary — `exists`, `parses`, `validates` — which is what closes #819. The two
vocabularies stop diverging because the structured field is the published one and the CSS class becomes a projection
of it.

### Not every field applies to every frame

Forcing one shape onto four kinds of frame is the mistake `category` made, and #806 exists to undo it.

| frame kind               | `target`                      | `step`                            | `status`        |
|--------------------------|-------------------------------|-----------------------------------|-----------------|
| source-check step result | inventory id                  | `exists` / `parses` / `validates` | `pass` / `fail` |
| test run                 | test name + calendar identity | `validates`                       | `pass` / `fail` |
| terminal                 | as above, per kind            | `complete`                        | omitted         |
| protocol rejection       | omitted                       | omitted                           | omitted         |

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

## Emitters, and the legacy projection

Three typed emitters replace frame construction spread across ~35 sites. Each owns one kind:

```php
private function sendStepResult(ConnectionInterface $to, string $classFragment, ?string $targetId,
    Step $step, Status $status, string $text, ?array $details, ?string $runToken, ?string $requestId): void

private function sendComplete(ConnectionInterface $to, string $classFragment, ?string $targetId,
    ?string $runToken, ?string $requestId): void

private function sendTestResult(ConnectionInterface $to, string $classFragment, string $test,
    CalendarIdentity $calendar, int $year, Status $status, string $text, ?array $details,
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

## Error handling

| Condition                          | Behaviour                                                         |
|------------------------------------|-------------------------------------------------------------------|
| `requestId` present but malformed  | Rejected as a malformed message, via the existing `echobot` frame |
| `requestId` absent                 | Field omitted from responses; everything else unchanged           |
| A step throws                      | Existing error frame, plus `status: "fail"`, then `complete`      |
| Target never resolves (unknown id) | `echobot` rejection, no `complete` — no work was started          |

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
