# Ambrosian edition catalogue and per-missal lectionary

Design for issue #957. Refs #953, #955.

## Summary

Two things the Ambrosian rite needs that the Roman rite does not force us to model.

First, the rite has **two** post-conciliar editions, not one. The API declares only `EDITIO_TYPICA_2024`, so
`AmbrosianMissalResolver::resolve()` returns that edition for every year and `CalendarParams::AMBROSIAN_YEAR_LOWER_LIMIT`
already admits years from 1976. Every Ambrosian calendar between 1976 and 2023 is therefore already built from the 2024
sanctorale. Coining `EDITIO_TYPICA_1976` does not create that inaccuracy — it makes it visible and reportable.

Second, the Ambrosian lectionary is **not** constant across editions: the renewed Lezionario was published in 2008, between
the two editions. The Roman assumption of one lectionary corpus per rite does not hold here. The seam for this already
exists — `MissalSource::getLectionaryFilePath()` is keyed per missal — but `AmbrosianMissalSource` hard-codes `false`.

## What this design does NOT do

- It does not add sanctorale or lectionary source data. `EDITIO_TYPICA_1976` is coined data-less, and every Ambrosian
  lectionary map entry is `false`. Landing data later is a data-and-one-map-entry change, by construction.
- It does not coin `EDITIO_TYPICA_1981`, `EDITIO_TYPICA_1990` or `EDITIO_TYPICA_2026`. The reasons are settled on the
  issue and already recorded in the `AmbrosianMissal` class docblock: 1981 and 2026 are **Latin translations** of the
  1976 and 2024 Italian editions respectively, and translations are i18n sidecars in this codebase, not delta layers;
  1990 is a revised reprint within the first edition, which is precisely why 2024 is the *second*.
- It does not touch the authorization object type. Generalising `general_roman_calendar` into `rite_calendar` is #955,
  sequenced separately behind a cdcf-infra model change. `EDITIO_TYPICA_1976` stays out of
  `AccessRequestRepository::GRC_OBJECT_IDS` until it has data, matching the Roman precedent, so the two issues do not
  interact.

## Edition history, as settled

| year | what it is                                                                                              |
|------|---------------------------------------------------------------------------------------------------------|
| 1976 | **I edizione, italiana** (Card. Giovanni Colombo), with the new Ambrosian Calendar. The authority.      |
| 1981 | the **Latin translation** of 1976 — same contents, different language. Not a separate edition.          |
| 1990 | *aggiornamento* (Card. Carlo Maria Martini): a revised reprint of the Italian, still the FIRST edition. |
| 2008 | the renewed **Lezionario** — a lectionary change *inside* the first edition's window.                   |
| 2024 | **II edizione, italiana** (Mario Delpini), in force from 17 November.                                   |
| 2026 | the **Latin translation** of 2024 (*editio altera*), superseding the 1981 Latin.                        |

The Ambrosian rite is the inverse of the Roman: here the Italian edition is the authority and the Latin is its
translation, whereas in the Roman rite the Latin *editio typica* is the authority and vernacular editions are national
adaptations carrying local memorials the Latin does not.

## Components

### 1. `AmbrosianMissal` declares `EDITIO_TYPICA_1976`

A data-less declared edition is already first-class in this codebase: `RomanMissal::$jsonFiles` maps
`EDITIO_TYPICA_1971`, `EDITIO_TYPICA_SECUNDA_1975`, `ITALY_EDITION_2020`, `NETHERLANDS_EDITION_1978`,
`CANADA_EDITION_2011` and `CANADA_EDITION_2016` to `false`.

```php
public const EDITIO_TYPICA_1976 = 'EDITIO_TYPICA_1976';

$values          += 'EDITIO_TYPICA_1976'
$names            = [ EDITIO_TYPICA_1976 => 'Messale Ambrosiano, I edizione italiana, 1976' ]
$jsonFiles        = [ EDITIO_TYPICA_1976 => false ]
$i18nPath         = [ EDITIO_TYPICA_1976 => false ]
$editioTypicaIds += EDITIO_TYPICA_1976
$yearLimits       = [
    EDITIO_TYPICA_1976 => [ 'since_year' => 1976, 'until_year' => 2024 ],
    EDITIO_TYPICA_2024 => [ 'since_year' => 2024 ],
]
```

**`until_year` is exclusive.** This is the established convention, not a choice made here: `RomanMissal::$yearLimits`
declares `ITALY_EDITION_1983` as `since 1983, until 2002` alongside `EDITIO_TYPICA_TERTIA_2002` as `since 2002`, and
`CalendarHandler` drops a missal when `Year >= until_year`. So `until_year => 2024` means the 1976 edition applies
through 2023 inclusive.

Consequences that need no new code, only confirmation in tests:

- `MissalMetadataMap::buildIndex()` skips any id whose structure file is absent, so `EDITIO_TYPICA_1976` cannot appear
  in `/missals/ambrosian` until it has data.
- `AmbrosianMissal::produceMetadata()` gives it `api_path: null` and `locales: []`.
- It stays out of `AccessRequestRepository::GRC_OBJECT_IDS`.

The class docblock's edition-history table is already correct. Two lines above it are now stale and must go: *"Only the
2024 edition is defined for now"* and *"the 1976 edition … is deferred to a later plan"*. `AmbrosianMissalResolver`'s
docblock makes the same two claims and needs the same correction.

### 2. `AmbrosianMissalResolver::resolve()` becomes year-aware

`resolve(int $year)` returns the **historically correct** edition: the one whose `[since_year, until_year)` window
contains `$year`. The windows are read from `AmbrosianMissal::getYearLimits()`, never hard-coded, so a future edition is
a data change in one place.

A year below the rite's floor never reaches this resolver — `CalendarParams::validateRiteCompatibility()` rejects it
with a 400. The resolver still returns the earliest declared edition for such a year rather than an empty list, because
returning `[]` would turn a defensive case into a `list index 0` error far from its cause.

### 3. Substitution is a separate, explicit step

`resolve()` stays pure: it answers "which edition governs this year", and nothing else. Whether we *hold data* for that
edition is a different question, and gets its own unit.

```php
final readonly class MissalEditionSelection
{
    public function __construct(
        public string $requested,   // the edition that governs the year
        public string $effective,   // the edition whose sanctorale is actually read
    ) {}

    public function isSubstituted(): bool
    {
        return $this->requested !== $this->effective;
    }
}
```

`AmbrosianMissalResolver::selectSanctoraleEdition(int $year): MissalEditionSelection` resolves historically, then walks
*forward* through the declared editions to the nearest later one that has a sanctorale file, throwing
`ServiceUnavailableException` if none does.

Forward, not backward: a later edition is a superset-ish revision of the rite's own proper and is the closest thing we
hold to the missing one, whereas walking backward would reach for an edition that is itself absent.

Keeping the two apart means each is testable without the calendar engine, and the day 1976 data lands, `resolve()` needs
no change at all — `selectSanctoraleEdition()` simply stops substituting.

### 4. The substitution is reported on `/calendar`

`CalendarHandler::addAmbrosianSanctoraleToCalendar()` calls `selectSanctoraleEdition()` and, when `isSubstituted()`,
pushes one gettext-translatable entry onto `$this->Messages[]` naming the year, the edition that governs it, and the
edition actually read. The branch stops firing on its own once 1976 data exists.

`EventsHandler` gets the same substitution but emits **no** message. That is not an oversight: `EventsHandler` has no
`Messages` sink, a structural divergence its own comment at line 845 already records for an analogous case.

### 5. `EventsHandler` must actually honour the resolved edition

`EventsHandler::processAmbrosianSanctoraleEvents()` today resolves `$edition` and then ignores it, reading the
hard-coded `JsonData::AMBROSIAN_SANCTORALE_FILE->path()` and `JsonData::AMBROSIAN_SANCTORALE_I18N_FILE->path()`;
`$edition` survives only inside an error message. With one declared edition this is invisible. With two it is silently
wrong — the resolver's answer would be computed and discarded.

Fix it by reading through the edition's own accessors, the way `CalendarHandler` does via `AmbrosianSanctoraleLoader`.
This is in scope because it is the same feature: a second edition is exactly what makes the existing code wrong.

### 6. The Ambrosian lectionary becomes per-missal

`AmbrosianMissal` gains a `$lectionaryPath` map and a `getLectionaryFilePath()` accessor mirroring
`getSanctoraleI18nFilePath()`, with both editions mapped to `false` for now.
`AmbrosianMissalSource::getLectionaryFilePath()` delegates to it instead of returning a hard-coded `false`, keeping the
existing validate-the-id-first contract. `riteLectionaryFolder()` stays `false`: there is no rite-wide Ambrosian
lectionary corpus, and it must never fall back to the Roman one.

Landing the 2008 Lezionario later is then one map entry plus data files;
`MissalsHandler::resolveSanctoraleTarget()` already flips `readings_tier` from `'rite'` to `'missal'` by itself.

**A wrinkle recorded, not built for.** The 2008 Lezionario appeared *inside* the 1976 edition's window, so strictly the
Ambrosian lectionary changed mid-edition and a per-missal map cannot express that. It does not matter while the only
lectionary data we will hold is 2008-onward, which belongs to the 2024 edition. It would matter if pre-2008 Ambrosian
readings were ever added, and that is the point at which the lectionary needs its own year dimension.

## Testing

| what                                                                                         | where                                         |
|----------------------------------------------------------------------------------------------|-----------------------------------------------|
| `resolve()` maps years across the 2024 boundary to the right edition; the floor year is 1976 | `AmbrosianMissalResolverTest`                 |
| `selectSanctoraleEdition()` substitutes for 1990 and does not for 2025                       | `AmbrosianMissalResolverTest`                 |
| `EDITIO_TYPICA_1976` has `api_path: null`, `locales: []`, and is absent from the built index | `AmbrosianMissal` / `MissalMetadataMap` tests |
| the substitution message fires for a pre-2024 year and not for a post-2024 one               | `CalendarHandler` Ambrosian sanctorale test   |
| `/events` for a pre-2024 Ambrosian year reads the substituted edition's files                | `EventsHandler` Ambrosian test                |
| `isEditioTypica()` holds for EVERY declared Ambrosian id                                     | `MissalCatalogTest`                           |

The last one is the assertion asked for on the issue: because every Ambrosian missal is rite-level and the rite has no
national tier, the `national_calendar` branch of `OpenFgaAuthorizationMiddleware::forMissals()` and of
`ChangeResource::missal()` is unreachable for this rite. Pinning it means the day someone coins a non-typical Ambrosian
id, the test says so rather than the middleware quietly filing a change request against a national calendar that does
not exist.

## Risks

- **The 1976 window is a claim about history, and it is now load-bearing.** Until now the resolver ignored year limits
  entirely, so a wrong boundary was inert. After this change it decides which edition a calendar is built from and
  whether a message is emitted. The 17 November 2024 in-force date sits inside civil year 2024 but at the start of
  *liturgical* year 2025; this design keeps `since_year => 2024`, matching what already shipped, rather than
  re-opening a civil-vs-liturgical-year question that the Roman editions do not answer either.
- **Pre-2024 Ambrosian output does not change.** The substitution reproduces today's behaviour and adds a message. That
  is deliberate: the alternative — an empty sanctorale for 1976-2023 — is honest but a visible regression, and raising
  the floor to 2024 would remove a working range of the API.
