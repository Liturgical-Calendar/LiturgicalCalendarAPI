# A typed target for the Health WebSocket protocol

Design for section B of [#806](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/issues/806). Section A
([#811](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/pull/811)) published the inventory; this makes it
the address. Client counterpart: [UnitTestInterface#42](https://github.com/Liturgical-Calendar/UnitTestInterface/issues/42).

## Problem

Three properties on the wire are each doing more than one job, and they overlap.

**`category` is one name carrying two disjoint enums.** On `executeValidation` it selects a schema-resolution strategy
(`universalcalendar`, `sourceDataCheck`, `resourceDataCheck`). On `validateCalendar` and `executeUnitTest` it names a
calendar type (`nationalcalendar`, `diocesancalendar`, `ritecalendar`). Two of those values appear in both enums meaning
different things.

**`sourceFile` is polymorphic with no discriminator, and worse than #806 recorded.** It is a repo-relative path, or an
absolute API URL, or — for every per-calendar check — a *bare identifier*: `"IT"`, `"Europe"`, `"roma_lazio_it"`,
`"EDITIO_TYPICA_1970"`. In that last case the server ignores it as a path entirely and reconstructs the real path from
the `validate` slug. Its sibling `sourceFolder` is mutually exclusive with it but not modelled as such.

**`validate` is already an id in all but name.** `national-calendar-IT`, `diocesan-calendar-roma_lazio_it`,
`wider-region-Europe`, `proprium-de-sanctis-IT-1983`, `tests-StIgnatiusOfLoyola` — the server parses these with eight
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

`/validations` goes from 18 items to roughly 130: the existing static source data, plus every per-calendar source
artifact — 10 national calendars, 32 diocesan, 7 wider regions, 11 test definitions, each with its `i18n` folder where
one exists.

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

| Id                              | What it addresses                        |
|---------------------------------|------------------------------------------|
| `temporale:roman`               | the Roman Proprium de Tempore file       |
| `temporale:roman:i18n`          | its translations folder                  |
| `sanctorale:roman:US_2011`      | the USA edition's sanctorale             |
| `decrees:roman`                 | memorials from decrees                   |
| `nation:roman:IT`               | the Italian national calendar definition |
| `diocese:ambrosian:lugano_ch`   | a diocesan calendar definition           |
| `widerregion:roman:Europe`      | a wider-region definition                |
| `test:roman:StIgnatiusOfLoyola` | a test *definition* file                 |

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
  "test": "StIgnatiusOfLoyola",
  "calendar": { "kind": "national", "id": "IT", "rite": "roman" },
  "year": 2026 }
```

`calendar.kind` is one of `general`, `national`, `diocesan`, `rite`. The word `category` disappears from the protocol.

### Source check versus test run

The current protocol blurs a distinction worth keeping explicit. `tests-StIgnatiusOfLoyola` today is a **source check**:
does the test *definition* validate against `LitCalTest.json`. `executeUnitTest` **runs** that test against a computed
calendar. Both survive, addressed differently — `test:roman:StIgnatiusOfLoyola` is an inventory item reached by shape 1;
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

## What this does not fix, yet

`Health::executeValidation()` passes a client-supplied `sourceFolder` to `glob()` with no containment check, and an
absolute path bypasses the `Router::$apiFilePath` prefix entirely — an arbitrary-directory read of `*.json` on the
WebSocket host. It is pre-existing, and the maintainer's decision is to let section B remove client-supplied paths
rather than patch it.

**That fix lands at the *end* of B, not the start.** The additive phase leaves the legacy branch — and the exposure —
in place. Anyone reading this design as "B closes the path issue" should read it as "the legacy-removal follow-up closes
it", and that follow-up is gated on client adoption.

## Error handling

| Condition                                        | Behaviour                                                                      |
|--------------------------------------------------|--------------------------------------------------------------------------------|
| `target.id` is not in the inventory              | Rejected immediately, via the existing error frame                             |
| `target` present but not an object               | Rejected as a malformed message                                                |
| `calendar` is an object with an unknown `kind`   | Rejected immediately                                                           |
| `calendar` is a string                           | Legacy path, unchanged behaviour                                               |
| `rite` disagrees with the calendar's actual rite | Rejected, rather than silently preferring one — a disagreement is a client bug |

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
