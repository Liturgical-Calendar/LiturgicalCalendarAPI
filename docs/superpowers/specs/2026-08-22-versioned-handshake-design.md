# A versioned handshake, and the client that consumes it

**API#806 section F — the last one.** A, B, C, E, G and H are merged; D's server half is complete. F was deliberately
held back: a handshake negotiates *between* protocol versions, and that is pointless until two exist and a client can
choose. Everything before it was additive or gated on `requestId`, which is what made the sections landable one at a
time while the shipped test interface kept working.

This document covers both halves, because F is only half a handshake without a client that reads it:

- **the API** sends `hello` on connect and accepts an inbound `protocol` — closes #806;
- **UnitTestInterface** reworks `resources.php` onto the structured contract — the pilot half of UnitTestInterface#42.

`index.php`, `types.js` regeneration, the `CLAUDE.md` protocol table and legacy removal are explicitly **not** in scope.

## Why an unsolicited frame is safe

The obvious objection to a server-initiated `hello` is that UnitTestInterface#46 made an unrecognised response `type`
paint a visible failed check — so a new frame type would appear to break the shipped UI. It does not, and the reason is
worth writing down rather than re-deriving.

Both runners open their handler with the same two guards:

```js
if ( currentState === TestState.Stopped || currentRunToken === null ) { return; }
// …parse…
if ( null === responseData || 'object' !== typeof responseData || responseData.runToken !== currentRunToken ) { return; }
```

`hello` carries no `runToken`. Before a run there is no `currentRunToken`, so the first guard returns; during a run the
second guard returns, because `undefined !== currentRunToken`. Neither path reaches the `type` dispatch, so neither
reaches `countUnattributableFailure()`. The frame is invisible to a v1 client in both states.

This is the same property every earlier section relied on, arrived at by a different route: C and E could add fields
because a v1 client ignores unknown fields; D's terminal frame needed `requestId` gating because a v1 client *counts*
frames. `hello` needs neither, because the run-token guard drops it before anything counts it.

## The frame

Sent once, from `Health::onOpen()`, through `sendMessage()` so it inherits `stripProjectRoot()` like every other frame:

```jsonc
{
  "type": "hello",
  "protocol": 1,
  "capabilities": {
    "rites":           ["roman", "ambrosian"],
    "actions":         ["executeValidation", "validateCalendar", "executeUnitTest",
                        "runTest", "cancelRun", "validateSource"],
    "responseFormats": ["JSON", "XML", "ICS", "YML"],
    "steps":           ["exists", "parses", "validates", "complete"],
    "statuses":        ["pass", "fail"]
  }
}
```

**Nothing in `capabilities` is written down twice.** Each list is derived from the thing that already defines it:

| key               | derived from                                                   |
|-------------------|----------------------------------------------------------------|
| `rites`           | `Rite::cases()`                                                |
| `steps`           | `Step::cases()`                                                |
| `statuses`        | `Status::cases()`                                              |
| `responseFormats` | `Health::VALIDATABLE_RESPONSE_FORMATS`                         |
| `actions`         | the `action.const` of every `WebSocketMessage.json` definition |

That last one matters most. `actions` is the list a client uses to decide what it may send, and the authority on what
the server accepts is the schema — so reading it from the schema means a definition added there cannot fail to be
advertised. A hand-written list is precisely the "several places that must be edited in lockstep" #806 exists to
remove; adding one inside the fix for it would be self-defeating.

`protocol: 1` follows #806's own numbering: `1` is the new self-describing contract, an **absent** `protocol` is legacy.
The value is the highest version the server supports, not a list — a client that needs to know the whole supported set
learns it by being refused.

## Inbound `protocol`

`WebSocketMessageValidator` gains `SUPPORTED_PROTOCOL_VERSIONS = [1]`, and `Health::onMessage()` checks it **before**
anything else interprets the message — before `UNKNOWN_ACTION`, before schema validation, before the action dispatch.
The ordering is a claim about meaning, not a micro-optimisation: the protocol version says how the rest of the message
is to be read, so "I do not speak your protocol" is a truer answer than "your action is unknown" for
`{"action": "bogus", "protocol": 7}` — under protocol 7 that action might be perfectly well known.

A violation answers a **tenth** `ProtocolErrorCode` case:

```jsonc
{ "type": "protocolError", "errorCode": "unsupported_protocol",
  "text": "This server speaks protocol 1. …", "requestId": "…" }
```

Both a version the server does not implement (`7`) and a wrongly-typed one (`"1"`, `1.0`) land here. The wrong-type
case is deliberately not left to the schema: `1.0` is a float that PHP's coercive typing would refuse deep inside a
handler, which is the seventh crash shape section G found the hard way, and answering it at the door is the lesson that
section already paid for.

The schema declares `protocol` on all seven definitions as `{"type": "integer", "enum": [1]}`. Two reasons, neither of
which is redundancy with the check above:

1. **Without it, a v2 client is refused for being a v2 client.** The `requestId`-gated root strict check refuses any
   property the shape does not declare, so the moment a client sends both `requestId` and `protocol` — which is
   exactly what a v2 client sends — its message becomes `INVALID_MESSAGE`.
2. The published schema is the contract clients read. A field the server requires but the schema omits is the
   documentation drift #805 was filed for.

**A drift test pins the two together**, asserting that the schema's `protocol` enum equals
`SUPPORTED_PROTOCOL_VERSIONS` in every definition. This repo's recurring defect is a check that reports something it
does not actually verify; two independent statements of the supported version set, with nothing comparing them, is
how that starts.

## What F deliberately does not do

- **Shape selection does not move.** A message is still resolved by `action`, and for `validateCalendar` by whether
  `calendar` is a string or an object. That mechanism works, is tested, and is what let the v1 and v2 shapes coexist;
  routing by `protocol` instead would make version a second discriminator with nothing to gain.
- **No per-connection protocol mode.** Declaring `protocol: 1` on one message does not make the connection refuse
  legacy shapes on the next. A half-migrated client — which is precisely what the pilot produces, sending
  `validateSource` for source data and `executeValidation` for routes on the same socket — must keep working.
- **No capability *requests*.** The client does not send a `hello`. Negotiation here is one-directional advertisement
  plus refusal of what the server cannot read, which is all either side needs while exactly one protocol exists.

## `WebSocketFrame.json`

`WebSocketMessage.json` publishes what a client may **send**. Nothing publishes what it will **receive** — and the
client's typedefs are almost entirely about received frames, which is why UnitTestInterface#42 item 5 ("regenerate
`types.js` from the published message schema") has no schema to regenerate from today.

F is the section that makes the contract self-describing, so this is its natural home. A new schema publishes the three
outbound frame shapes: `hello`, the step-result frame (`type` `success`/`error` with `runId`, `requestId`, `target`,
`step`, `status`, `details`, plus the legacy `text`/`classes` projection), and `protocolError`.

The legacy `classes` field is documented as **deprecated in the schema text**, not removed — removal is gated on
UnitTestInterface#42 shipping, and this pilot is only half of that.

This is an addition beyond #806's literal section-F text, and is called out as such.

## Client half: the `resources.php` pilot

Two commits, because they carry different risk and are separately revertible.

### P1 — structured frames, no wire-shape change

Since #824 the server already stamps `runId`, `requestId`, `target`, `step` and `status` on the frames answering
**legacy** `executeValidation` messages, and gates the terminal `complete` frame on `requestId`. So the client gets the
entire structured contract the moment it starts minting request ids — no action rename required, and the route checks
benefit identically to the source checks.

- mint a `requestId` per outbound message;
- build a `requestId → {node, steps}` registry when the cards are rendered, and paint from `requestId` + `step` +
  `status` rather than from `document.querySelectorAll(slugifySelector(responseData.classes))`;
- finish each phase on its terminal `complete` frames, deleting `resourceDataChecks.length * 3` and
  `sourceDataChecks.length * 3` — and with them the `>=`-tolerates-an-overshoot workaround, which exists only because
  counting frames cannot tell a duplicate from a legitimate one.

**The landmine.** Adding `requestId` *arms* the server's root unknown-property gate for these messages — that gate is
`requestId`-gated precisely because the shipped client sends properties the shapes do not declare. `resources.js`
spreads `...check` (`validate`, `category`, `sourceFile`/`sourceFolder`, and `rite` on the dynamically-pushed entries)
plus `responsetype`, and `executeValidation` declares all of those. So it should pass — but "should" is not
"verified", and the failure mode is every check in the run being refused. A test sends the real shapes, with a
`requestId`, before anything relies on it.

### P2 — opaque ids for source data

`sourceDataChecks` stops coming from `wsProtocol.js`'s `UNIVERSAL_CHECKS` and starts coming from a `GET /validations`
fetch, filtered by the selected rite through the existing `inRiteScope()`. Those checks send:

```jsonc
{ "action": "validateSource", "protocol": 1, "target": { "id": "…" }, "runToken": "…", "requestId": "…" }
```

Cards take their label and their step count from the inventory item, so `steps.length` drives the scaffolding — exact
since #825, which is what makes rendering one card per step honest rather than an estimate. `UNIVERSAL_CHECKS`, the
last hardcoded copy of the API's on-disk layout in this repo, is deleted.

**Route checks stay on `executeValidation`.** `/validations` advertises source data only — 77 file and folder items —
and the route family has no v2 shape to move to. That is a smaller problem than it sounds: a URL is not a filesystem
path, and the per-nation and per-diocese route checks are already derived from `/calendars` metadata rather than
hardcoded. A v2 shape for route checks gets its own issue; it would also close UnitTestInterface#50 (`/temporale` and
`/validations` are not health-checked) by making the route list server-advertised instead of hand-listed.

### Where the code goes

All new protocol behaviour lands in `assets/js/wsProtocol.js` — `PROTOCOL_VERSION`, `newRequestId()`, `readHello()`,
`createRequestRegistry()`, `fetchValidations()` — which is what that module was seeded for, and the alternative is a
fourth copy of the protocol to keep in lockstep.

`applyResultToDom()` lives in `assets/js/testResults.js` and is **shared with `index.js`**, which is not being migrated
in this pass. It therefore gains a registry-based sibling rather than being rewritten in place; `index.js` keeps
painting by `classes` until its own migration.

## Testing

**API.** `hello` is emitted on connect and shaped correctly; each capability list is genuinely derived, not
transcribed — the actions test asserts against the schema document rather than a literal; `protocol: 1` is accepted on
every shape; `protocol: 7`, `"1"` and `1.0` are refused with `unsupported_protocol`; an absent `protocol` leaves every
legacy path byte-identical; the protocol check precedes `UNKNOWN_ACTION`; and the schema-to-constant drift test.

**Client.** The e2e specs that assert card markup and dropdown contents are re-run — `e2e/rite-selection.spec.ts`
pins the hand-built dropdown and may need revisiting once cards are inventory-rendered.

**Known bound on the API test suite in a worktree:** `Routes/*` tests speak to `localhost:8000`, which is the *shared*
checkout, so a green run there says nothing about this branch. The meaningful signals are the `Health*` and
`WebSocket/*` suites, which drive `onMessage()` in-process, plus `composer analyse` and `composer lint`.

## Order

The API PR lands first. The client's `protocol: 1` messages need a server that accepts the field — sent to a server
without it, and carrying a `requestId`, they are refused by the root strict gate as an undeclared property.
