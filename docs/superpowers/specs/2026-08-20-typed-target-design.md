# A typed target for the Health WebSocket protocol

Design for section B of [#806](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/issues/806). Section A
([#811](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/pull/811)) published the inventory; this makes it
the address. Client counterpart: [UnitTestInterface#42](https://github.com/Liturgical-Calendar/UnitTestInterface/issues/42).

**This document is the protocol reference** for the three v2 message shapes (`validateSource`, the typed-`calendar`
form of `validateCalendar`, and `runTest`) until section F's `hello` handshake frame ships and supersedes it. The
Health WebSocket protocol is not documented in `jsondata/schemas/openapi.json`: that file describes the HTTP API, the
WebSocket endpoint is a separate transport with no path entry there, and a search of `jsondata/schemas/` and `docs/`
for these action names turns up nothing outside this design and the `Health` class's own docblock. Read the two
together — this file for the wire shapes and the reasoning behind each rule, the `Health` class docblock
(`src/Health.php`) for the exact PHPStan `@phpstan-type` definitions and per-action implementation detail.

## Problem

Three properties on the wire are each doing more than one job, and they overlap.

**`category` is one name carrying two disjoint enums.** On `executeValidation` it selects a schema-resolution strategy
(`universalcalendar`, `sourceDataCheck`, `resourceDataCheck`). On `validateCalendar` and `executeUnitTest` it names a
calendar type (`nationalcalendar`, `diocesancalendar`, `ritecalendar`). Two of those values appear in both enums meaning
different things.

**`sourceFile` is polymorphic with no discriminator, and worse than #806 recorded.** It is a repo-relative path, or an
absolute API URL, or — for every per-calendar check — a *bare identifier*: `"IT"`, `"Europe"`, `"romamo_it"`,
`"EDITIO_TYPICA_1970"`. In that last case the server ignores it as a path entirely and reconstructs the real path from
the `validate` slug. Its sibling `sourceFolder` is mutually exclusive with it but not modelled as such.

**`validate` is already an id in all but name.** `national-calendar-IT`, `diocesan-calendar-romamo_it`,
`wider-region-Europe`, `proprium-de-sanctis-IT-1983`, `tests-StIgnatiusOfLoyolaTest` — the server parses these with eight
anchored `preg_match` arms to recover what the client meant. It is simultaneously a schema selector, the human label
rendered on the card, and a CSS class fragment.

So the protocol already has an addressing scheme. It is undeclared, hyphenated because of CSS, and recovered by regex.

## The principle

**Everything checkable has an id, and the id is the address.** A client picks an id out of a list the server published
and sends it back. The server resolves it with one lookup.

That is only true if the inventory covers everything checkable, which today it does not.

## Scope

| In scope                                                                 | Out of scope                                                                    |
|--------------------------------------------------------------------------|---------------------------------------------------------------------------------|
| Growing `/validations` to enumerate per-calendar source data             | Removing the legacy message shapes (a follow-up, gated on UnitTestInterface#42) |
| A typed `target` on source validation, replacing `category`+`sourceFile` | Structured, DOM-agnostic responses (#806 section C)                             |
| A tagged calendar identity on `validateCalendar` and test runs           | `requestId` correlation (#806 section E)                                        |
| Retiring the eight `preg_match` slug arms for inventory-owned targets    | The terminal `complete` frame (#806 section D)                                  |
| `responsetype` → `responseFormat` on the reshaped messages               | A `protocolError` response type (#806 section G)                                |
| —                                                                        | The `hello` handshake and versioning (#806 section F)                           |

### Why the naming clean-up rides along

`responsetype` is renamed here rather than in a separate pass because these messages are being reshaped anyway. An odd
name left alone through a redesign survives it.

## The inventory grows

`/validations` goes from 18 items to 77: the existing static source data, plus every per-calendar source artifact.
Each calendar contributes two items, its definition file and its `i18n` folder — 5 checkable national calendars give 10,
16 dioceses give 32, 3 wider regions give 6. The 11 test definitions contribute one apiece, having no translations
folder. (The Vatican is announced as a national calendar but is served by the General Roman Calendar and has no source
data of its own, so it contributes nothing.) Those figures are what the bundled source data happens to yield today; the
point is that they are enumerated rather than listed, so they move with the data.

The enumeration comes from `CalendarMetadataProvider::create()`, which the codebase already calls "the single source of
truth" for the calendar index and which `MetadataHandler` uses to serve `/calendars`. Deriving from the same builder
means the two lists cannot disagree: a calendar that exists is a calendar that is checkable.

### An amendment to section A's stated principle

Section A said the endpoint "does not touch the filesystem". That is no longer literally true — enumerating registered
calendars reads source data, because that is what `CalendarMetadataProvider` does.

The principle that actually mattered is narrower and still holds: **the endpoint never stats a target to decide whether
to list it.** An item appears because the calendar is registered, not because a file was found present. Presence remains
the `exists` step's job at check time. A list that quietly omitted what it could not stat would reintroduce exactly the
blindness of #800.

`CalendarMetadataProvider` deliberately re-reads on every call, because the `/data` write endpoints can mutate calendar
definitions at runtime; `/validations` inherits that and stays correct after a write rather than serving a stale index.

## Id vocabulary

`kind:rite[:qualifier][:i18n]`, fully qualified.

| Id                                      | What it addresses                        |
|-----------------------------------------|------------------------------------------|
| `temporale:roman`                       | the Roman Proprium de Tempore file       |
| `temporale:roman:i18n`                  | its translations folder                  |
| `sanctorale:roman:US_2011`              | the USA edition's sanctorale             |
| `decrees:roman`                         | memorials from decrees                   |
| `nation:roman:IT`                       | the Italian national calendar definition |
| `diocese:ambrosian:lugano_ch`           | a diocesan calendar definition           |
| `widerregion:roman:Europe`              | a wider-region definition                |
| `test:ambrosian:StIgnatiusOfLoyolaTest` | a test *definition* file                 |

All 18 ids already published in #811 satisfy this scheme unchanged, so nothing shipped needs renaming. The rite segment
is always present even where it is not currently a discriminator — nations and tests are Roman-only today — because a
uniform first-two-segments shape lets the server switch on `kind` without special cases, and an Ambrosian national
calendar later would not force a vocabulary change.

Ids stay **opaque to clients**: they are echoed back, never parsed. The structure exists for the server and for humans
reading logs.

## Three message shapes

There are three domains here, and collapsing them into one `target` would be the same mistake `category` made.

```jsonc
// 1. Validate a source artifact — fully addressed by an inventory id.
{ "action": "validateSource",
  "target": { "id": "diocese:ambrosian:lugano_ch" } }

// 2. Compute a calendar — an identity plus a year, not an inventory item.
{ "action": "validateCalendar",
  "calendar": { "kind": "diocesan", "id": "lugano_ch", "rite": "ambrosian" },
  "year": 2026,
  "responseFormat": "JSON" }

// 3. Run a test — a test id plus the calendar to run it against.
{ "action": "runTest",
  "test": "StIgnatiusOfLoyolaTest",
  "calendar": { "kind": "rite", "id": "ambrosian", "rite": "ambrosian" },
  "year": 2026 }
```

`calendar.kind` is one of `general`, `national`, `diocesan`, `rite`. The word `category` disappears from the protocol.

**`general` is an alias, not a fifth kind.** It names the General Roman Calendar and is exactly equivalent to
`{"kind": "rite", "id": "roman"}` — its only difference is that `id` may be omitted, because `general` is the one kind
with nothing to choose between: there is exactly one General Roman Calendar, so no id is needed to pick it out. Read it
as a convenience spelling for the client that means "the default", not as a distinct calendar type with its own
resolution path; both forms resolve through the same rite-level handling and must never be treated as two different
things. (An `id` sent alongside `kind: general` is accepted only if it is `roman` — anything else is rejected, since it
would be asserting a calendar `general` cannot name.)

### Source check versus test run

The current protocol blurs a distinction worth keeping explicit. `tests-StIgnatiusOfLoyolaTest` today is a **source check**:
does the test *definition* validate against `LitCalTest.json`. `executeUnitTest` **runs** that test against a computed
calendar. Both survive, addressed differently — `test:ambrosian:StIgnatiusOfLoyolaTest` is an inventory item reached by shape 1;
running it is shape 3.

### Why `rite` is carried rather than inferred

`Health::resolveRite()` can infer a rite from a calendar id, and does so today for clients that predate rite awareness.
The typed calendar identity carries it explicitly because inference is how the rite arrived in the first place — as an
optional field with a server-side guess — and #806's own complaint is that there was no way to state it. A client that
selected an Ambrosian diocese knows the rite; making it say so removes a guess from the server.

## What `Health` sheds

- `retrieveSchemaForCategory()`'s `sourceDataCheck` branch — eight anchored `preg_match` arms — becomes a single
  `CheckableInventory::byId()` lookup for every target the inventory owns, which after this design is all of them.
- `executeValidation()` stops deriving a filesystem path from client input for v2 messages; it reads the resolved
  inventory item's own `path` and `kind`.
- The `universalcalendar` / `sourceDataCheck` / `resourceDataCheck` strategy enum stops being consulted on v2 messages.

Nothing is deleted while a legacy client can still send the old shapes. The arms remain reachable from the legacy branch
until it is removed.

## Migration

Additive, exactly as `cancelRun` was. How a message is recognised as v2 differs by action, and it is worth stating
precisely rather than as one rule:

| Action                                     | v2 recognised by                                                |
|--------------------------------------------|-----------------------------------------------------------------|
| `validateSource` (was `executeValidation`) | the action name itself — it is new, so every such message is v2 |
| `runTest` (was `executeUnitTest`)          | the action name itself                                          |
| `validateCalendar` (name unchanged)        | `calendar` being an **object** rather than a string             |
| `cancelRun`                                | unaffected by this design                                       |

Two of the three get a new name, which #806's capability sketch already uses, and a new name is a cleaner discriminator
than a shape test: a v1 client cannot accidentally emit `validateSource`. `validateCalendar` keeps its name because the
action itself is unchanged — only the calendar identity becomes typed — so there the shape of `calendar` is the signal.

No client breaks on the day this lands, and UnitTestInterface can migrate one page at a time.

Removal of the legacy branch is a separate, later change, gated on UnitTestInterface#42 shipping.

## Retired properties

Being additive is not the same as being permissive. Each reshaped message replaces specific legacy properties with a
typed equivalent, and a v2 message that *also* carries the property it replaced is rejected rather than silently
ignored:

| v2 action          | v1 predecessor      | rejects if present                                   |
|--------------------|---------------------|------------------------------------------------------|
| `validateSource`   | `executeValidation` | `category`, `validate`, `sourceFile`, `sourceFolder` |
| `validateCalendar` | `validateCalendar`  | `category`, `responsetype`                           |
| `runTest`          | `executeUnitTest`   | `category`                                           |

`runTest` retires only `category`, because `executeUnitTest` never had a `responsetype` to retire in the first place.
`runToken` is retired by nothing on any of the three — it predates this design, is shared across all three actions, and
stays current.

This is not a breaking change: a v1 client sends a string `calendar` or an old action name and never reaches these
checks, so nothing that worked yesterday stops working. What it catches is the client in between — one that has
switched to the new action name or the object `calendar`, but is still sending fields the old shape used. Without this
rule that client's mistake is invisible: a retired property is simply never read, the response looks correct, and the
bug surfaces only later, on the day the legacy branch is finally removed and there is no fallback left to mask it. A
loud rejection *now*, while both branches still exist side by side to compare against, is strictly more useful than a
quiet one that waits for removal to become a symptom.

The rule is applied uniformly across all three actions on purpose, even though `runTest` retires only one property.
Rejecting a stale field on two actions and tolerating it on the third would leave a client unable to predict which
behaviour it will get without checking the action name — worse than either answer applied consistently.

## What this does not fix, yet

`Health::executeValidation()` passes a client-supplied `sourceFolder` to `glob()` with no containment check, and an
absolute path bypasses the `Router::$apiFilePath` prefix entirely — an arbitrary-directory read of `*.json` on the
WebSocket host. It is pre-existing, and the maintainer's decision is to let section B remove client-supplied paths
rather than patch it.

**That fix lands at the *end* of B, not the start.** The additive phase leaves the legacy branch — and the exposure —
in place. Anyone reading this design as "B closes the path issue" should read it as "the legacy-removal follow-up closes
it", and that follow-up is gated on client adoption.

## Error handling

| Condition                                                     | Behaviour                                                                      |
|---------------------------------------------------------------|--------------------------------------------------------------------------------|
| `target.id` is not in the inventory                           | Rejected immediately, via the existing error frame                             |
| `target` present but not an object                            | Rejected as a malformed message                                                |
| `calendar` is an object with an unknown `kind`                | Rejected immediately                                                           |
| `calendar` is a string                                        | Legacy path, unchanged behaviour                                               |
| `rite` disagrees with the calendar's actual rite              | Rejected, rather than silently preferring one — a disagreement is a client bug |
| A v2 message also carries a legacy property its shape retired | Rejected before anything else runs — see Retired properties, above             |

Rejections reuse the **existing** `echobot` error shape. A dedicated `protocolError` type belongs to section G and
cannot land before section C, because since UnitTestInterface PR #46 an unrecognised response `type` is painted as a
visible failed check. Introducing one now would make every rejection look like a failing test to the user.

## Testing

**Equivalence is the safety net, as it was for section A.** Every legacy slug the current branch resolves must resolve
to the same schema through its new id. The existing `HealthSchemaCategoryTest` provider is extended with the id form of
each slug it already covers, and the old slug→schema table is pasted into the inventory tests as an oracle.

**Round-trip:** every id `/validations` advertises resolves through `byId()`, and every resolved item yields the same
schema its legacy slug did.

**Drift, extended to the dynamic half:** every calendar `CalendarMetadataProvider::create()` reports must have a
corresponding inventory entry. This is the section A drift test's guarantee applied to the part that is now enumerated
rather than hand-listed — a diocese added to source data without appearing in `/validations` fails the build.

**Legacy untouched:** the existing suites covering the legacy shapes must pass unchanged, which is what makes "additive"
a claim rather than an intention.

## A caveat on `steps`, not fixed here

`GET /validations` publishes a `steps` array per item (`['exists', 'parses', 'validates']`, from
`CheckableInventory::STEPS`), and `Health` emits one WebSocket frame per step during a check — but the frame classes
are `file-exists`, `json-valid`, `schema-valid`, and nothing in the API relates the two vocabularies. A client that
takes a step name literally and waits for a `.<label>.exists` frame waits forever. This is present since #811,
unrelated to the reshaping this document describes, and filed separately as
[#819](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/issues/819); the substantive documentation of the
caveat lives on the `steps` property itself, in `jsondata/schemas/LitCalValidationsPath.json` and the `/validations`
description in `openapi.json`, because that is where a client discovers the array in the first place. **`steps` is
authoritative for its length, not for its values** — sizing a check's progress as `count(steps)` is correct today and
is exactly what replaces UnitTestInterface#42's four hardcoded `* 3` constants.

Deliberately not fixed by renaming either vocabulary to match the other: the maintainer's decision is that #806
section C dissolves this on its own. Once responses are structured and DOM-agnostic, the frame itself carries the step
identity and the CSS class becomes a client-side rendering choice, leaving nothing left to reconcile. Renaming
`steps`' published values down to the frame classes now would bake a presentation detail into the discovery endpoint
— the exact coupling `/validations` exists to remove — and section C would immediately undo it. Renaming the frame
classes instead is not an additive change and would break the live UnitTestInterface runners, which match on those
classes today. The risk this guards against is specific: UnitTestInterface#42 shipping before section C, needing step
names for something more than a count, and hardcoding a client-side mapping between the two vocabularies — which would
reintroduce exactly the duplication #806 exists to end, and once written it would be load-bearing.

## Status

Section B, as designed above, is implemented. `validateSource` resolves a `target.id` through
`CheckableInventory::byId()` instead of the eight anchored `preg_match` slug arms; the typed (object-`calendar`) form
of `validateCalendar` exists alongside the legacy string form, with `rite` enforced as an assertion rather than taken
as a hint; `runTest` reshapes `executeUnitTest` behind a new action name; and all three reject a legacy property their
own shape retired, per [Retired properties](#retired-properties) above.

What shipped deliberately stopped short of what "additive" promised, in two ways that were always meant to survive
this pass and are not oversights:

- **The legacy branch survives.** `executeValidation`, the string form of `validateCalendar`, and `executeUnitTest`
  remain fully reachable, byte-for-byte, exactly as [Migration](#migration) above committed to. Removing them is a
  separate, later change gated on [UnitTestInterface#42](https://github.com/Liturgical-Calendar/UnitTestInterface/issues/42).
- **The `glob()` containment exposure survives with it.** `Health::executeValidation()` still passes a client-supplied
  `sourceFolder` to `glob()` with no containment check, exactly as [What this does not fix, yet](#what-this-does-not-fix-yet)
  above recorded before implementation began. `validateSource`'s `target.id` path never reaches `glob()` with
  client-supplied input — it resolves paths from `CheckableInventory`'s own records — so the new code carries none of
  the exposure. But the exposure was never section B's to fix: it lives entirely in the legacy branch, and closing it
  is bundled into the later legacy-removal change rather than patched opportunistically here, per the maintainer's
  original decision.

Also implemented, settled after this document was first written and recorded here rather than left to drift from the
code: the uniform retired-property rejection ([Retired properties](#retired-properties)), and `general` as a
literal alias for `{"kind": "rite", "id": "roman"}` rather than a fifth calendar kind (documented under
[Three message shapes](#three-message-shapes)).
