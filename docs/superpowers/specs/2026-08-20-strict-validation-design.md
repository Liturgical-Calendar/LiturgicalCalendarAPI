# Strict inbound validation and typed protocol errors

**API#806 section G.** Sections A, B, C, E and H are merged; D's server half is complete
([#811](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/pull/811) publishes `steps`,
[#824](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/pull/824) emits the terminal frame, and
[#825](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/pull/825) made the count exact). What remains of D is
client work in UnitTestInterface#42. This is the next server-side section. F — the versioned handshake — comes last,
because negotiation is pointless until two protocols exist.

## Why this is not a tidying exercise

`Health::validateMessageProperties()` checks that required keys **exist**. It never checks what they are. The v1 dispatch
arms then unpack straight into typed parameters:

```php
$this->validateCalendar($m->calendar, $m->year, $m->category, $m->responsetype, ...);
//                       string        int       string        string
```

Ratchet's `IoServer::handleData` catches `\Exception`. `TypeError`, `ValueError` and `Error` are **not** `\Exception`.
So a malformed message does not fail — it terminates the WebSocket process, for every connected client.

Measured against `c38dbb97`, driving `Health::onMessage()` directly:

| message                                                             | outcome                                      |
|---------------------------------------------------------------------|----------------------------------------------|
| `validateCalendar` with `"year": "not-a-year"`                      | `TypeError` — process dies                   |
| `validateCalendar` with `"category": []`                            | `TypeError` — process dies                   |
| `validateCalendar` with `"responsetype": "NOT_A_FORMAT"`            | `ValueError` — process dies                  |
| `executeUnitTest` with `"test": {…}`                                | `TypeError` — process dies                   |
| `executeValidation` with `"category": {…}`                          | `Error` (the `(string)` cast) — process dies |
| `runTest`, `validateSource`, `cancelRun`, malformed every which way | rejected cleanly, process survives           |

The split is not accidental: **the three v1 actions crash and the three v2 actions do not.** The v2 handlers take the
whole message and validate defensively, because they were written after the hazard was understood. Two places in
`Health` already document it — `VALIDATABLE_RESPONSE_FORMATS` exists precisely because `ReturnTypeParam::from()` throws
a `\ValueError`, and `cancelRun()` takes `mixed` for the same reason — but each guard was applied at the one door its
author was standing in front of. Section G is where the rule stops being applied door by door.

No authentication stands in front of this. `Health` is the WebSocket endpoint the test interface connects to.

## The shape of the fix

### 1. A published schema is the authority

`jsondata/schemas/WebSocketMessage.json`: a `oneOf` over the message shapes, discriminated by `action` and — for
`validateCalendar`, which has two shapes under one action — by whether `calendar` is a string or an object. That is the
same discriminator `Health::isTypedCalendarMessage()` already uses, not a second one invented here.

This follows the precedent set by [#717](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/pull/717): schema
files are authoritative and wired into `Health`, not documentation kept beside the code and hoped to match. It also
answers #806's own thesis directly — the vocabulary currently lives in copies that must be edited in lockstep, and
nothing fails loudly when they diverge.

It declares `"$schema": "https://json-schema.org/draft-07/schema#"` and puts its shapes under `definitions`, matching
every other schema in `jsondata/schemas/` and the draft `swaggest/json-schema` v0.12.43 actually implements. §4 explains
why that version matters more than a convention here.

`ACTION_PROPERTIES` and the list-walking half of `validateMessageProperties()` are **replaced** by the schema's
`required` arrays rather than kept beside it. A second copy is the disease, not the cure.

### 2. The schema is derived from the `@phpstan-type` annotations, and a test says so

`Health`'s class docblock already carries a complete typed specification of every message shape — types, optionality and
enum membership:

```php
@phpstan-type ValidateCalendar \stdClass&object{action:'validateCalendar',calendar:string,year:int,
    category:'nationalcalendar'|'diocesancalendar'|'ritecalendar',responsetype:'JSON'|'XML'|'ICS'|'YML',rite?:string}
```

These annotations are the reason [#805](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/issues/805) exists:
they were the only written spec, and they disagreed with the code. PHPStan level 10 already enforces them **against the
implementation**. Wiring the schema to agree with the annotations therefore ties three things together — annotation,
implementation and published contract — with a failure at any corner breaking a check rather than escaping silently.

Two drift tests hold the corners, and they are stated separately because they check different things:

- **Schema against annotation:** for each `@phpstan-type` shape, a fixture message conforming to that annotation must
  validate. The annotations are enforced against the implementation by PHPStan, so a fixture that satisfies the
  annotation and fails the schema means the schema and the code disagree. Parsing the docblocks themselves is
  deliberately not attempted — the fixtures are transcribed by hand and reviewed, which is the readable half of the
  same guarantee.
- **Schema against dispatch:** every action the schema declares has a dispatch arm, and every dispatch arm has a schema
  arm. Adding an action without a schema entry fails in CI rather than falling through to `unknown_action` in
  production.

### 3. Where the gate sits

In `onMessage()`, before dispatch:

```text
parse JSON → requestId check → retired-property check → schema validation → dispatch
```

**The retired-property check stays in PHP and runs first.** JSON Schema can express the rejection
(`not: {required: [...]}`) but cannot express the sentence:

```text
category is not part of a validateSource message: target.id replaces it.
```

The value of `rejectRetiredProperties()` is the diagnosis, not the refusal — a half-migrated client is exactly the
caller who needs to be told which property replaced which. Running it ahead of the schema means a half-migrated message
is answered for what is actually wrong with it, which is the ordering `rejectRetiredProperties()` already documents.

### 4. Strictness gates on `requestId`, and the allowed names still come from the schema

Unknown-property rejection applies only to messages that opted in by carrying a `requestId` — the same v2 opt-in signal
the terminal `complete` frame is gated on.

**It cannot be expressed in the schema, and the reason is worth recording.** The natural spelling is
`allOf: [{$ref: shape}, {if: {required: [requestId]}, then: {unevaluatedProperties: false}}]`. `unevaluatedProperties`
is draft 2019-09; `swaggest/json-schema` v0.12.43 implements draft-07, where `additionalProperties: false` sees only the
properties declared in the *same* schema object — so inside a `then` it would reject everything. The remaining schema-only
option is two arms per action, lenient and strict, with every property list written twice. That is the second copy this
section exists to eliminate, traded for an aesthetic.

So the gate is applied in PHP, and the **vocabulary it applies stays in the schema**, which was the actual goal:

- each shape is a named entry under `definitions` (`executeValidation`, `validateCalendarLegacy`, `validateCalendarTyped`,
  `executeUnitTest`, `runTest`, `cancelRun`, `validateSource`), and the `oneOf` arms `$ref` those entries;
- the unknown-property check reads the allowed names from `definitions[<shape>].properties` — it does not carry a list;
- which shape a message is gets decided by the discriminator that already exists: the action name, plus
  `isTypedCalendarMessage()` for `validateCalendar`'s two forms.

A test asserts the check rejects a name absent from the schema's `properties` and accepts every name present, so the two
cannot drift apart without failing.

If [#826](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/issues/826) ever moves the project to a
draft-2019-09 or 2020-12 validator, this gate can move into the schema as originally drawn. It is recorded here so that
the workaround is understood as forced rather than preferred — and so nobody re-proposes the elegant spelling without
finding out why it was not used.

**This is load-bearing, not caution.** The shipped client sends properties the server does not declare and does not
read:

- `assets/js/index.js:531` — `sendMessage()` sets `data.runToken` on **every** message, and `runToken` is declared only
  for `cancelRun`.
- `assets/js/index.js:647` and `assets/js/resources.js:1221` — `{ action: 'executeValidation', ...check }` spreads a
  config object carrying `rite`, which `executeValidation` never reads: `readRiteHint()` is called only from the
  `validateCalendar` and `executeUnitTest` arms.

A uniform `additionalProperties: false` would refuse every source-data check the current UI sends. Type checking, by
contrast, applies to **all** messages, v1 included: refusing a message that would otherwise kill the server is strictly
better for a v1 client too.

Envelope properties — `action`, `runToken`, `requestId` — are declared on every shape, since they are cross-cutting
rather than per-action.

### 5. Typed protocol errors

```jsonc
{ "type": "protocolError", "errorCode": "unknown_target_id",
  "text": "…", "runToken": "…", "requestId": "…" }
```

**Ungated, and this is a finding rather than an assumption.** #806 section 8 says protocol errors are invisible because
the client dispatches only on `success`/`error`. That is now **stale**: UnitTestInterface#46 added an `else` branch that
treats *any* unrecognised type — `echobot` included — as a visible failure via `countUnattributableFailure()`
(`assets/js/index.js:932-937`). A `protocolError` frame therefore behaves in the shipped client exactly as `echobot`
does today. Nothing regresses, so nothing needs a gate.

**`text`, not `message`.** #806's sketch shows a `message` field. Every other frame in this protocol carries `text`, and
introducing a second name for the same thing is the duplication #806 exists to remove. `errorCode` is the genuinely new
part: machine-readable, and the reason the frame is worth typing at all.

### 6. Error codes exist where a client would act differently

```php
enum ProtocolErrorCode: string {
    case INVALID_JSON       = 'invalid_json';        // the body did not parse
    case NOT_AN_OBJECT      = 'not_an_object';       // parsed to a scalar or an array
    case MISSING_ACTION     = 'missing_action';
    case UNKNOWN_ACTION     = 'unknown_action';
    case INVALID_REQUEST_ID = 'invalid_request_id';  // the existing correlation-id check
    case RETIRED_PROPERTY   = 'retired_property';    // you are half-migrated
    case UNKNOWN_TARGET_ID  = 'unknown_target_id';   // refetch /validations
    case INVALID_MESSAGE    = 'invalid_message';     // schema violation
    case INTERNAL_ERROR     = 'internal_error';      // see §7
}
```

`INVALID_MESSAGE` is deliberately **not** split into `wrong_type` / `unknown_enum_value` / `unknown_property`, though
the #806 example gestures at that granularity. A client cannot act differently on those three: each means "fix the
message", and `text` carries the validator's own account of which one it was. `RETIRED_PROPERTY` and
`UNKNOWN_TARGET_ID` do imply different client behaviour, so they are separate. The rule: a code where behaviour
diverges, text where it does not.

### 7. A `\Throwable` backstop around dispatch

Schema validation is the primary gate. It is not the only one, because a crash that kills the process for every
connected client must not depend on a schema file being correct. The dispatch `switch` is wrapped in
`catch (\Throwable)`: log loudly, answer `INTERNAL_ERROR`, keep serving.

The tradeoff is real and worth naming rather than hiding: a catch-all can mask bugs. For a long-running daemon,
converting "the process dies" into "one request fails, loudly logged" is the right trade — and the log line is what
makes the masked bug findable.

**It does not cover [#823](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/issues/823).** Those throws
happen inside promise callbacks, after `onMessage()` has returned, so no `try` around dispatch can see them. #823 stays
open and is not in scope here.

### 8. Import the schema once

`validateDataAgainstSchema()` calls `Schema::import()` per invocation. That is fine for a handful of source files and
wrong for every inbound message. The message schema is imported once into a static and reused. If the schema file is
unreadable, that fails at startup rather than once per message: a WebSocket server that cannot validate is
misconfigured, and should say so before a client connects rather than answering every message with an internal error.

## Testing

**The highest-value test is compatibility, not the crash vectors.** Every message shape the shipped client actually
sends must pass validation. `UnitTestInterface/assets/js/wsProtocol.js` holds that table literally, so the fixtures are
transcribed from the real thing rather than imagined — including the `rite` spread and the injected `runToken` that a
uniform strict rule would have broken on day one.

Then:

- **Each confirmed crash vector** from the table above asserts a `protocolError` frame *and* that nothing was thrown.
  Deleting the guard must fail these — the mutation check every guard on this rollout has had to pass.
- **v1 leniency, both directions:** an `executeValidation` carrying a stray property is accepted; the same message with
  a `requestId` is refused. One test, two assertions, and it pins the gate rather than the general idea of a gate.
- **The drift test** of §2: schema arms ⟷ dispatch arms.
- **Error codes:** each rejection path asserts its own code, so a refactor cannot quietly collapse two codes into one.

Tests drive `onMessage()` with a real message, never the private validator directly. #825 is the precedent and the
reason: the emitters there were correct and tested, and the bug was that no request reached them — a test calling the
right function directly passed against the bug.

## Scope

**In:** the message schema, type and enum validation, unknown-property rejection for v2, typed `protocolError` frames
with codes, the dispatch backstop, and the tests above.

**Out:** #823's promise-callback hole; the `hello` handshake and capability negotiation (section F); any change to
response frames beyond the new error type; UnitTestInterface's adoption of `errorCode`, which is #42's business.

**Compatibility:** no v1 message that works today stops working. The only v1 messages this refuses are ones that
currently kill the server.
