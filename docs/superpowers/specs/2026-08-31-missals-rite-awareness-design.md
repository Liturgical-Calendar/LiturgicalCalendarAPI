# Design: rite-awareness for `/missals`

Issue: [#953](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/issues/953)
Status: approved design, not yet implemented
Date: 2026-08-31

## 1. Background

`GET /missals` announces five missals, all Roman. The Ambrosian sanctorale exists on disk at
`jsondata/sourcedata/rite/ambrosian/missals/propriumdesanctis_2024/` — 254 events plus `it` and `la`
i18n sidecars — and no route reaches it.

The asymmetry is the problem. The source tree has been rite-partitioned, and the lectionary routes
added in #948 are rite-scoped and answer honestly for a rite they have no data for:

```text
/lectionary/roman/sanctorale        ✓ rite in the path
/lectionary/ambrosian/sanctorale    ✓ {"lectionary_available": false, "message": "..."}
/missals                            ✗ no rite anywhere
```

A client just taught about rites by the lectionary routes finds the missals catalogue speaks only
Roman. The sanctorale viewer in LiturgicalCalendarFrontend#503 composes a sanctorale from the missal
layers applying to a chosen rite; for the Ambrosian rite it can obtain the readings' absence but not
the events, so the rite renders as an empty sanctorale rather than as the calendar it is.

**What the issue understates.** The routing half is nearly free — `Router::extractRiteSegment()` and
`Router::canonicalRiteUrl()` are already generic, gated on a route allow-list. The actual work is
that the `/missals` *endpoint* has no concept of a non-Roman missal: `RomanMissal` contains no
reference to `Rite` at all, and its `produceMetadata()` reflects over its own constants against a
hardcoded `JsonData::MISSALS_FOLDER`.

**But the identity is not missing — only unexposed.** `src/Enum/AmbrosianMissal.php` already exists,
declares `EDITIO_2024` ("Messale Ambrosiano, Editio 2024", `since_year: 2024`) with its sanctorale
file, i18n path and year limits, and states that it mirrors the shape of `RomanMissal`. It is
already wired into the calendar engine through `AmbrosianMissalResolver`, `AmbrosianSanctoraleLoader`,
`CalendarHandler` and `EventsHandler`, and has its own test class; a `MissalResolver` interface
already exists on the calendar side. So this change *exposes* identity that the engine has been
using, rather than inventing it. That materially reduces the scope the issue implies.

**What the issue overstates.** Its closing note asks that the enumerator tolerate the #940 naming
wart. That is stale: `AMBROSIAN_MISSAL_FILE` is already `{missal_folder}/{missal_folder}.json` and
the file on disk is `propriumdesanctis_2024.json`. #940 is fully fixed and is not in this scope.

## 2. Goals

- `/missals/{rite}` and `/missals/{rite}/…` reach the Ambrosian sanctorale, for all four existing
  shapes including the write surface.
- Bare `/missals` keeps working and means `roman`, advertising `/missals/roman` as canonical.
- Missal identity stops being Roman-only, without `RomanMissal` growing rite conditionals.
- The editio-typica *tier* (which decides bare-language vs. full-locale sidecars) stops being
  inferred from an id prefix.

## 3. Non-goals

- **Renaming the `general_roman_calendar` FGA object type to `rite_calendar`.** Decoupled to its own
  issue; see §7.
- Exposing `propriumdetempore` through `/missals`. The catalogue lists sanctorale missals only, for
  the Roman rite too; that is unchanged.
- Adding an Ambrosian *lectionary*. `/lectionary/ambrosian/sanctorale` already reports its absence
  correctly, and this change does not invent readings.
- Any change to `/calendar/ambrosian`, which already works.

## 4. Design decisions (confirmed)

### 4.1 A rite-keyed registry over per-rite sources

A `MissalSource` interface with a `MissalCatalog::for(Rite): MissalSource` resolver. `RomanMissal`
keeps its constants and public static API — the calendar engine references those constants as
compile-time symbols throughout — and becomes the Roman implementation. A new `AmbrosianMissal`
declares the Ambrosian edition.

Rejected: adding Ambrosian constants to `RomanMissal`, which would leave a class named `RomanMissal`
lying about its contents; and a fully data-driven scan of the missals folder, which would require a
metadata block in every missal source file and would dissolve constants the engine depends on.

### 4.2 `EDITIO_2024`, region `AMBROSIAN`

The public identifier is permanent and the frontend will key on it.

| field            | value                               |
| ---------------- | ----------------------------------- |
| `missal_id`      | `EDITIO_2024`                       |
| `region`         | `AMBROSIAN`                         |
| `locales`        | `["it", "la"]`                      |
| `year_published` | 2024                                |
| `api_path`       | `/missals/ambrosian/EDITIO_2024`    |

`EDITIO_2024` is the constant `AmbrosianMissal` already declares, so nothing in the engine has to be
renamed. It is also the better public id on its own merits: the rite is carried by the path segment
and by the rite-qualified FGA object id, so `AMBROSIAN_2024` would repeat it, and the `EDITIO_`
spelling marks the edition as a typical edition rather than a regional delta — the same authority
distinction §4.3 names.

Region `IT` was rejected: it would file the missal beside `IT_1983` and `IT_2020` as though it were
another Italian Roman-rite national missal, and `region` is what the engine uses to decide which
national calendars a missal layer applies to. Region `VA` was rejected as misattributing Milan's
missal to the Vatican.

### 4.3 The editio-typica tier becomes explicit — and is renamed for what it is

An *editio typica* is first printed in Latin and becomes the normative base from which every
regional missal's sanctorale is computed as a delta. That is a statement about **authority**, not
about language: the language translations shipped alongside the Latin are a practicality, not the
defining property.

The predicate already exists and is named for the practicality rather than the authority:

```php
public static function isLatinMissal(string $missal_id): bool
{
    return in_array($missal_id, self::$values) && str_starts_with($missal_id, 'EDITIO_TYPICA_');
}
```

Three of its four production call sites are asking an authority question, not a language one:

| Call site                            | Question actually asked                                  |
| ------------------------------------ | -------------------------------------------------------- |
| `MissalsHandler:269`                 | which locale names the i18n sidecar — naming             |
| `MissalsHandler:450`                 | the event's `calendar` value (`GENERAL ROMAN` vs region) |
| `ChangeResource:100`                 | `general_roman_calendar` vs `national_calendar`          |
| `OpenFgaAuthorizationMiddleware:385` | which FGA object governs the write                       |

So `isLatinMissal()` is **renamed to `isEditioTypica()`** (and `getLatinMissalIds()` to
`getEditioTypicaIds()`), and its body stops being a prefix test: the tier becomes a property each
`MissalSource` declares. `EDITIO_2024` is a typical edition and must answer `true` while matching no
`EDITIO_TYPICA_` prefix.

An earlier draft of this spec called the predicate `isLanguageGeneric()`. That was wrong twice over:
it named a downstream symptom instead of the fact, and it was circular — it would have decided the
sidecar naming convention from a property ("its files are bare-language") that is itself the thing
being decided. `CLAUDE.md` already frames it the right way round: "i18n and lectionary file names
follow the missal's **tier**."

Scope: 18 occurrences, all internal PHP, no HTTP surface.

### 4.4 Routing

Add `missals` to the allow-lists in `extractRiteSegment()` and `canonicalRiteUrl()`. Because
`extractRiteSegment()` strips the segment in place, the existing 0/1/2-part shape parsing in the
`missals` case runs unchanged, and `configureAuthorizationPipeline()` still finds the missal id at
path part 0.

Rite and missal id cannot be confused: rite values are lowercase (`roman`, `ambrosian`), missal ids
uppercase (`EDITIO_2024`). This is documented at `extractRiteSegment()` the way the `/tests`
docblock documents its own disambiguation.

Canonicalisation is the existing `Link: rel="canonical"` header, **not** a redirect — a 301 would
downgrade POST to GET and drop the body, and browsers treat a redirect to a preflighted
cross-origin request as a network error. `canonicalRiteUrl()` already restricts itself to GET/POST,
so the write shape is unaffected and no preflight guard is needed.

### 4.5 Writes: rite-qualified object ids under the existing type

`OpenFgaAuthorizationMiddleware::forMissals()` currently comments that "missals live only under the
Roman source tree", which this change falsifies. It gains a rite parameter and qualifies the object
id with the existing `RiteScopedObjectId::qualify()`:

```text
general_roman_calendar:roman/EDITIO_TYPICA_1970
general_roman_calendar:ambrosian/EDITIO_2024
national_calendar:roman/US            (unchanged)
```

No authorization-model change, so this does not queue behind the in-flight RBAC rollout. It does
need a tuple migration for existing unqualified `general_roman_calendar` ids, modelled on
`scripts/migrate-rite-data-tuples.php`.

**These object ids are explicitly interim.** `general_roman_calendar` is not merely a name that has
outgrown its contents; the *tier* it denotes is a rite-level tier that every rite has. For the Roman
rite it is the General Roman Calendar; for the Ambrosian rite it is that rite's own general calendar
(the Ambrosian *comune*); a future rite would bring its own. The generalisation — a `rite_calendar`
tier of which `general_roman_calendar` is one instance rather than the archetype — is
[#955](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/issues/955), and it is why this
spec spends nothing on defending the current name. Qualifying the id with the rite is the smallest
step that is true today and is not thrown away by that work: `ambrosian/EDITIO_2024` still names the
right thing once the type is renamed.

## 5. Detailed changes

### 5.1 Identity

- `src/Enum/MissalSource.php` (new) — interface: `getMissalIds()`, `getSanctoraleFileName()`,
  `getSanctoraleI18nFilePath()`, `getLectionaryFilePath()`, `isValid()`, `getName()`,
  `isEditioTypica()`, `regionFor()`, `produceMetadata()`.
- `src/Enum/MissalCatalog.php` (new) — `for(Rite): MissalSource`.
- `src/Enum/AmbrosianMissal.php` (**exists**) — implements `MissalSource`; gains `isEditioTypica()`,
  `regionFor()` and `produceMetadata()`. `EDITIO_2024` and every existing signature stay as they are.
- `src/Enum/RomanMissal.php` — implements `MissalSource`; `produceMetadata()` takes its folder from
  `JsonData::missalsFolderFor($rite)`; region and tier become declared rather than derived;
  `isLatinMissal()` → `isEditioTypica()` and `getLatinMissalIds()` → `getEditioTypicaIds()`, with
  the four call sites updated. Existing constants preserved.

### 5.2 Routing and handler

- `src/Router.php` — `missals` added to both allow-lists; `new MissalsHandler($requestPathParts, $rite)`;
  `forMissals($fgaClient, $requestPathParts[0], $rite)`.
- `src/Handlers/MissalsHandler.php` — takes a `Rite`, resolves through `MissalCatalog::for()`
  instead of calling `RomanMissal::` statically. The Ambrosian per-missal read must tolerate an
  absent lectionary, which `getLectionaryFilePath()` already expresses as `false`/null for a missal
  without one.
- `src/Http/Middleware/OpenFgaAuthorizationMiddleware.php` — `forMissals()` gains the rite and
  qualifies both branches' object ids.

### 5.3 Contract and data

- `jsondata/schemas/openapi.json` — four new rite-scoped path items; the bare spellings retained and
  documented as meaning `roman`, with the canonical form named. `MissalMetadata.region` gains
  `AMBROSIAN`.
- `scripts/migrate-missal-fga-tuples.php` (new) — qualify existing `general_roman_calendar` ids.

## 6. Validation rules

- An unknown rite segment is not a rite: it falls through `Rite::tryFrom()` and is parsed as a
  missal id, yielding the existing 404 for an unknown missal. No new error path.
- `/missals/ambrosian/{id}` for a Roman id 404s, and vice versa: `isValid()` is asked of the rite's
  own source, so ids do not leak across rites.
- A write to an Ambrosian missal requires `calendar_editor` plus the FGA relation on
  `general_roman_calendar:ambrosian/EDITIO_2024`.

## 7. Rollout / sequencing

1. This change: identity, routing, handler, FGA id qualification, tuple migration, OpenAPI.
2. Frontend #503 switches the sanctorale viewer to `/missals/{rite}` and drops its planned
   "no sanctorale exposed for this rite yet" placeholder for the Ambrosian rite.
3. Separately and later, as #955: generalise the rite-level tier — `general_roman_calendar` becomes
   one instance of a `rite_calendar` tier rather than its archetype, reusable by the Ambrosian rite
   and by any rite added afterwards. That work rewrites the object ids this change introduces, which
   is anticipated and costs nothing here, since the rite qualifier survives the rename.

## 8. Risks

- **The `_test` naming collision, if the rename is ever folded in here.** `general_roman_calendar_test`
  and `rite_calendar_test` already coexist as different types — the former accepts only the literal
  id `general_roman_calendar`, the latter accepts rite ids. Renaming the data type without resolving
  that pair produces either a mismatched pair or a head-on collision. This is why §3 excludes it.
- **`produceMetadata()` is reflection over class constants.** Splitting it per rite must not change
  the Roman output by even a key, since `/missals` is consumed by the frontend and the WebSocket
  validation interface. Guarded by a key-for-key comparison against the pre-change response.
- **The `isLatinMissal()` rename touches the calendar engine's data loading.** It is the riskiest
  edit in this change, because `MissalsHandler:450`, `ChangeResource` and the FGA middleware each
  read it for a different decision. Behaviour must be identical for every Roman id; the only new
  answer is `true` for `EDITIO_2024`.
- **Tuple migration touches a live store.** The dev store is mid-rollout on the additive model; the
  migration must be additive and idempotent, tolerating already-qualified ids.

## 9. Open questions

None. All four decision points (identity home, public id and region, scope, FGA object) are
confirmed; the type rename is decoupled to its own issue.
