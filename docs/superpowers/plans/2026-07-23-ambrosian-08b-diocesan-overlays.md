# Ambrosian Diocesan Overlays (8b) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or
> superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add diocesan overlays for the Ambrosian rite — four diocese-level calendars (milano_it, bergam_it, novara_it,
lugano_ch) served via `/calendar/ambrosian/diocese/{id}`, announced by `/calendars`, and catalogued by
`/events/ambrosian/diocese/{id}` — sitting directly on the comune ambrosiano base with no national layer.

**Architecture:** A separate Ambrosian diocesan overlay step (`applyAmbrosianDiocesanCalendar()`) is inserted into the
existing `calculateAmbrosianCalendar()` orchestrator after the comune sanctorale add and before the readings backfill,
so diocesan events get season-stamped and participate in the add-all-then-resolve-to-fixpoint precedence.
Discovery/validation become rite-aware and data-driven off a new `rite/ambrosian/calendars/dioceses/` tree; the
diocese→nation coupling is rite-guarded off for Ambrosian. The Roman diocesan path is untouched.

**Tech Stack:** PHP 8.4, PHPUnit, PHPStan level 10, phpcs (PSR-12), JSON Schema (draft-07), Composer scripts.

## Global Constraints

- **Roman golden-master 9/9 byte-identical.** The diocesan overlay is Ambrosian-only; every shared method touched
  (loadDiocesanCalendarData, buildDiocesanCalendarData, validateDiocesanCalendarParam, DiocesanMetadata) must be
  rite-guarded so Roman behavior is unchanged.
- **Ambrosian comune output unchanged** when no diocese is requested (`/calendar/ambrosian/{year}` byte-identical to pre-8b).
- PHP >= 8.4; PHPStan level 10 clean; phpcs clean; `composer parallel-lint` clean.
- Diocesan saints are **festive** events → festive (5-field, incl `second_reading`) empty readings, NOT the comune's ferial `AmbrosianReadings::empty()`.
- Data scope: Milan-missal-attributed rows only. EXCLUDE church-specific `A Milano, nella basilica…` rows (→ 8c). No synthetic cathedral dedications.
- Override rows reuse the comune event_key; net-new rows use a diocesan key. Diocesan-wins.
- Locales: `it_IT` + `la_VA` (diocesan file format uses full ICU locale codes; the loader maps the primary language for the Ambrosian sanctorale loader which accepts `it`/`la`).
- Work in the isolated worktree with a REAL composer `vendor/` and copied `.env.local` (golden-master SKIPs without it).

Reference (all `src/Handlers/CalendarHandler.php` unless noted, against `d9116637`):
`calculateAmbrosianCalendar()` :1047-1076 (sanctorale add :1061, readings backfill :1063/:1106, season stamp :1065,
precedence :1067); `handle()` Ambrosian branch :5337, LITURGICAL two-run :5347-5374, stale comment :5338;
`loadDiocesanCalendarData()` :428-462 (nation coupling :449); `applyDiocesanCalendar()` Roman ref :4397-4494;
`addAmbrosianSanctoraleToCalendar()` :936-967 (overlay template); `CalendarParams::validateDiocesanCalendarParam()`
:450-461 (dispatch :222-224), `validateRiteCompatibility()` :662-693 (nation guard :668, whitelist :674);
`CalendarMetadataProvider::buildDiocesanCalendarData()` :175-208, `buildAmbrosianCalendarData()` :296-311;
`MetadataCalendars.php` nation-attach :332-337, keys push :320; `AmbrosianRiteProfile::SUPPORTED_DIOCESES` :31;
`DiocesanMetadata.php`; `DiocesanCalendar.json` metadata def :108-143; `AmbrosianReadings.php`;
`LiturgicalEventCollection::addLiturgicalEvent()` :237, `removeLiturgicalEvent()`.

---

## File Structure

**Created (data):**

- `jsondata/sourcedata/rite/ambrosian/calendars/dioceses/IT/milano_it/Arcidiocesi di Milano.json` + `i18n/{it_IT,la_VA}.json`
- `.../IT/bergam_it/Diocesi di Bergamo.json` + `i18n/…`
- `.../IT/novara_it/Diocesi di Novara.json` + `i18n/…`
- `.../CH/lugano_ch/Lugano.json` + `i18n/…`

**Modified (code):**

- `src/Enum/JsonDataConstants.php` + `src/Enum/JsonData.php` — `AMBROSIAN_DIOCESAN_CALENDARS_FOLDER` (+ `_FILE`) constant.
- `jsondata/schemas/DiocesanCalendar.json` — optional `metadata.rite`.
- `src/Models/RegionalData/DiocesanData/DiocesanMetadata.php` — `rite` field (default roman).
- `src/Models/Lectionary/AmbrosianReadings.php` — `emptyFestive()`.
- `src/Models/Metadata/MetadataDiocesanCalendarItem.php` — `rite` field.
- `src/Services/CalendarMetadataProvider.php` — rite-aware `buildDiocesanCalendarData()`.
- `src/Models/Metadata/MetadataCalendars.php` — skip nation-attach for Ambrosian dioceses.
- `src/Models/Calendar/Rite/AmbrosianRiteProfile.php` — data-driven `SUPPORTED_DIOCESES`.
- `src/Params/CalendarParams.php` — rite-scoped `validateDiocesanCalendarParam()`.
- `src/Handlers/CalendarHandler.php` — rite-guard `loadDiocesanCalendarData()`, new `applyAmbrosianDiocesanCalendar()`,
  wire into `calculateAmbrosianCalendar()` + `handle()` Ambrosian branch.
- `src/Handlers/EventsHandler.php` + `src/Params/EventsParams.php` + `src/Router.php` — `/events/ambrosian/diocese/{id}`.
- `phpunit_tests/…` — unit + integration tests per task.

---

## Task 1: Schema `rite` field + DiocesanMetadata DTO

**Files:**

- Modify: `jsondata/schemas/DiocesanCalendar.json` (metadata def :108-143)
- Modify: `src/Models/RegionalData/DiocesanData/DiocesanMetadata.php`
- Test: `phpunit_tests/Models/RegionalData/DiocesanMetadataTest.php` (create or extend)

**Interfaces:**

- Produces: `DiocesanMetadata->rite` (`Rite` enum, default `Rite::ROMAN`); schema accepts optional `metadata.rite` (`"roman"|"ambrosian"`).

- [ ] **Step 1: Write failing DTO test**

```php
public function testRiteDefaultsToRomanWhenAbsent(): void
{
    $meta = DiocesanMetadata::fromArray([
        'nation' => 'IT', 'diocese_id' => 'agrige_it', 'diocese_name' => 'Arcidiocesi di Agrigento',
        'locales' => ['it_IT'], 'timezone' => 'Europe/Rome',
    ]);
    self::assertSame(Rite::ROMAN, $meta->rite);
}

public function testRiteParsedWhenPresent(): void
{
    $meta = DiocesanMetadata::fromArray([
        'nation' => 'IT', 'diocese_id' => 'milano_it', 'diocese_name' => 'Arcidiocesi di Milano',
        'locales' => ['it_IT'], 'timezone' => 'Europe/Rome', 'rite' => 'ambrosian',
    ]);
    self::assertSame(Rite::AMBROSIAN, $meta->rite);
}
```

- [ ] **Step 2: Run → FAIL** (`vendor/bin/phpunit phpunit_tests/Models/RegionalData/DiocesanMetadataTest.php` → no `rite` property).

- [ ] **Step 3: Implement.** Add a `public readonly Rite $rite` field to `DiocesanMetadata` (constructor param defaulting
  to `Rite::ROMAN`); in `fromArray`/`fromObject`/`fromArrayInternal`/`fromObjectInternal` read `rite` via
  `Rite::from(...)` when present else `Rite::ROMAN`; add `rite` to the phpstan `@phpstan-type` typedefs (`:9-10`). Import
  `use LiturgicalCalendar\Api\Enum\Rite;`. In `jsondata/schemas/DiocesanCalendar.json` add to
  `DiocesanCalendarMetadata.properties` (:112-134): `"rite": { "type": "string", "enum": ["roman", "ambrosian"] }` — do
  NOT add to `required` (:136-142).

- [ ] **Step 4: Run → PASS.**

- [ ] **Step 5: Verify Roman files still valid.** Run `vendor/bin/phpunit --filter Schema phpunit_tests/` (or the
  SchemaValidationTest) → existing Roman diocese files (no `rite`) still validate.

- [ ] **Step 6: `composer analyse && composer lint` → clean. Commit.**

```bash
git add src/Models/RegionalData/DiocesanData/DiocesanMetadata.php jsondata/schemas/DiocesanCalendar.json phpunit_tests/Models/RegionalData/DiocesanMetadataTest.php
git commit -m "feat(diocesan): add optional metadata.rite (default ROMAN) to DiocesanCalendar

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 2: `AmbrosianReadings::emptyFestive()`

**Files:**

- Modify: `src/Models/Lectionary/AmbrosianReadings.php`
- Test: `phpunit_tests/Models/Lectionary/AmbrosianReadingsTest.php` (create or extend)

**Interfaces:**

- Produces: `AmbrosianReadings::emptyFestive(): ReadingsFestive` — 5 empty strings.

- [ ] **Step 1: Write failing test**

```php
public function testEmptyFestiveHasFiveEmptyFields(): void
{
    $r = AmbrosianReadings::emptyFestive();
    self::assertInstanceOf(ReadingsFestive::class, $r);
    self::assertSame('', $r->first_reading);
    self::assertSame('', $r->responsorial_psalm);
    self::assertSame('', $r->second_reading);
    self::assertSame('', $r->gospel_acclamation);
    self::assertSame('', $r->gospel);
}
```

- [ ] **Step 2: Run → FAIL.**

- [ ] **Step 3: Implement** (mirror `empty()`):

```php
public static function emptyFestive(): ReadingsFestive
{
    return ReadingsFestive::fromArray([
        'first_reading'      => '',
        'responsorial_psalm' => '',
        'second_reading'     => '',
        'gospel_acclamation' => '',
        'gospel'             => '',
    ]);
}
```

Add `use LiturgicalCalendar\Api\Models\Lectionary\ReadingsFestive;` if not present.

- [ ] **Step 4: Run → PASS. `composer analyse` clean. Commit.**

---

## Task 3: `AMBROSIAN_DIOCESAN_CALENDARS_FOLDER` constant

**Files:**

- Modify: `src/Enum/JsonDataConstants.php`, `src/Enum/JsonData.php`
- Test: `phpunit_tests/Enum/JsonDataAmbrosianDiocesanPathTest.php` (create)

**Interfaces:**

- Produces: `JsonData::AMBROSIAN_DIOCESAN_CALENDARS_FOLDER->path()` = `.../rite/ambrosian/calendars/dioceses`;
  `AMBROSIAN_DIOCESAN_CALENDAR_FILE` = `…/{nation}/{diocese}/{diocese_name}.json`.

- [ ] **Step 1: Write failing test** asserting `JsonDataConstants::AMBROSIAN_DIOCESAN_CALENDARS_FOLDER ===
  'jsondata/sourcedata/rite/ambrosian/calendars/dioceses'` and the `_FILE` template.

- [ ] **Step 2: Run → FAIL.**

- [ ] **Step 3: Implement.** In `JsonDataConstants.php` after the Ambrosian sanctorale constants: `public const
  AMBROSIAN_DIOCESAN_CALENDARS_FOLDER = self::AMBROSIAN_RITE_FOLDER . '/calendars/dioceses';` and `public const
  AMBROSIAN_DIOCESAN_CALENDAR_FILE = self::AMBROSIAN_DIOCESAN_CALENDARS_FOLDER .
  '/{nation}/{diocese}/{diocese_name}.json';` (with truthful `Evaluates to …` docblocks). Mirror the two `case` aliases in
  `JsonData.php`.

- [ ] **Step 4: Run → PASS. `composer analyse` clean. Commit.**

---

## Task 4: milano_it data file (archdiocese-wide rows)

**Files:**

- Create: `jsondata/sourcedata/rite/ambrosian/calendars/dioceses/IT/milano_it/Arcidiocesi di Milano.json`, `i18n/it_IT.json`, `i18n/la_VA.json`
- Test: schema validation (Task 12 wires it; here validate manually)

**Source:** the Milan 2024 Missal text at `scratchpad/ambrosian.txt`, `CALENDARIO AMBROSIANO` section (lines ≥ 5080).
Extract every row tagged `Nell'arcidiocesi di Milano:` and `Nell'arcidiocesi di Milano e nella diocesi di X:` (the Milan
half of shared rows). Also the `Al di fuori dell'arcidiocesi di Milano:` rows do NOT go in milano_it (Milan keeps the
comune grade). EXCLUDE `A Milano, nella basilica/chiesa di…` rows (→ 8c).

**Format:** mirror `rite/roman/calendars/dioceses/IT/agrige_it/`:

- `Arcidiocesi di Milano.json`: `{ "litcal": [ { "liturgical_event": {event_key, color, grade, common, day, month},
  "metadata": {since_year: <edition year or 1976>, form_rownum: <i>} }, … ], "metadata":
  {"nation":"IT","diocese_id":"milano_it","diocese_name":"Arcidiocesi di
  Milano","locales":["it_IT","la_VA"],"timezone":"Europe/Rome","rite":"ambrosian"} }`
- `event_key`: PascalCase from the saint name (e.g. `SanPietroDaVerona`, `BeatoCarloAcutis`). For an OVERRIDE of a comune
  event, reuse the comune's exact event_key (look it up in
  `rite/ambrosian/missals/propriumdesanctis_2024/propriumdesanctis.json`); milano_it has no overrides (those are
  non-Milan), so all milano_it rows are net-new diocesan keys.
- `grade`: map `Memoria`→3 (MEMORIAL), `Memoria facoltativa`→2, `Festa`→5 (FEAST), `Solennità`→6 (SOLEMNITY) per `LitGrade`. `common`: `["Proper"]` unless the text names a common.
- `color`: white for most saints; red for martyrs (`martire/martiri`).
- `i18n/it_IT.json`: `{ event_key: "<Italian name>", … }`. `i18n/la_VA.json`: Latin names (use the Latin form where the
  Missal gives one; else a reasonable Latin rendering — flag any uncertain ones in the task report).

- [ ] **Step 1:** Extract the milano_it rows into a working list (day, month, name, grade, color) from
  `scratchpad/ambrosian.txt`. Record the source line for each in the task report for review.
- [ ] **Step 2:** Write the three JSON files.
- [ ] **Step 3:** Validate the JSON parses and matches the shape: `php -r "json_decode(file_get_contents('…/Arcidiocesi di
  Milano.json'), false, 512, JSON_THROW_ON_ERROR); echo 'ok';"` for each file.
- [ ] **Step 4:** Cross-check: every `event_key` in `litcal` has a name in both i18n files (no orphans, no extras). Provide a one-liner grep/py check in the report.
- [ ] **Step 5: Commit** the milano_it files.

---

## Task 5: bergam_it + novara_it + lugano_ch data files (exclusive + shared + overrides)

**Files:**

- Create: the three diocese dirs (`Diocesi di Bergamo.json`, `Diocesi di Novara.json`, `Lugano.json`) + `i18n/{it_IT,la_VA}.json` each.

**Source & rules** (from `scratchpad/ambrosian.txt` ≥ 5080):

- **Exclusive rows** `Nella diocesi di X:` → into X only (e.g. lugano_ch: `S. Francesco d'Assisi` Oct 4 Memoria — this is an OVERRIDE, see below; `S. Faustina Kowalska` Oct 5).
- **Shared rows** `Nell'arcidiocesi di Milano e nella diocesi di X:` → the saint goes into X (the Milan half was handled
  in Task 4). E.g. bergam_it: `S. Giovanni XXIII` (Oct 11), `S. Gaudenzio di Brescia` (Oct 25); novara_it: `S. Arialdo`
  (Oct 27); lugano_ch: `S. Luigi Guanella` (Oct 24 Solennità), `B. Manfredo Settala` (Jan 27).
- **Override rows** (diocesan-wins, reuse the comune event_key from `rite/ambrosian/missals/propriumdesanctis_2024/propriumdesanctis.json`):
  - `Al di fuori dell'arcidiocesi di Milano:` `Ss. Protaso e Gervaso, martiri` (Jun 19, MEMORIAL, red) → into ALL THREE of
    bergam_it/novara_it/lugano_ch, reusing the comune key for SS. Protaso e Gervaso (the comune has them at Festa; these
    override to Memoria).
  - lugano_ch `S. Francesco d'Assisi` (Oct 4, MEMORIAL) → reuse the comune key for S. Francesco (comune has him at Festa "Patrono d'Italia"); override to Memoria.
- Same format/grade/color/i18n rules as Task 4.

- [ ] **Step 1:** For each of the three dioceses, extract its rows (exclusive + its shared saints + its overrides) with source lines, into the task report.
- [ ] **Step 2:** Look up the exact comune event_keys for the override saints (SS. Protaso e Gervaso; S. Francesco
  d'Assisi) in `rite/ambrosian/missals/propriumdesanctis_2024/propriumdesanctis.json` and reuse them verbatim as the
  override `event_key`s.
- [ ] **Step 3:** Write the nine JSON files (3 diocese files + 3×2 i18n).
- [ ] **Step 4:** Validate JSON parse + i18n key parity (as Task 4 Step 4) for each diocese.
- [ ] **Step 5: Commit** the three dioceses.

---

## Task 6: Rite-aware discovery

**Files:**

- Modify: `src/Models/Metadata/MetadataDiocesanCalendarItem.php` (add `rite`)
- Modify: `src/Services/CalendarMetadataProvider.php` (`buildDiocesanCalendarData`)
- Modify: `src/Models/Metadata/MetadataCalendars.php` (skip nation-attach for Ambrosian)
- Test: `phpunit_tests/Services/CalendarMetadataProviderTest.php` or a Metadata handler test

**Interfaces:**

- Consumes: Tasks 3 (constant), 4/5 (diocese files), 1 (schema rite).
- Produces: `MetadataDiocesanCalendarItem->rite` (`Rite`, default ROMAN); `diocesan_calendars_keys` now includes the 4 Ambrosian dioceses tagged `rite=ambrosian`.

- [ ] **Step 1: Write failing test** — after `CalendarMetadataProvider::create()`, `diocesan_calendars` contains an item
  with `calendar_id='milano_it'` and `rite=Rite::AMBROSIAN`, and an existing Roman diocese (`agrige_it`) with
  `rite=Rite::ROMAN`; `milano_it` is NOT attached under any national calendar's dioceses.

- [ ] **Step 2: Run → FAIL.**

- [ ] **Step 3: Implement.**
  - Add `public readonly Rite $rite` (default `Rite::ROMAN`) to `MetadataDiocesanCalendarItem` (constructor + `fromObject` + `jsonSerialize`, emitting `"rite"`).
  - Refactor `buildDiocesanCalendarData()` (:175-208) to scan BOTH roots. Extract the per-root body into a private helper
    `scanDiocesanTree(MetadataCalendars $metadata, string $folderPath, Rite $rite)` and call it for
    `JsonData::DIOCESAN_CALENDARS_FOLDER->path()` (ROMAN) and `JsonData::AMBROSIAN_DIOCESAN_CALENDARS_FOLDER->path()`
    (AMBROSIAN). Set `rite` on the item (pass through the metadata object before `MetadataDiocesanCalendarItem::fromObject`,
    or set on the item after). Guard the Ambrosian scan with `is_dir(...)` so an absent tree is a no-op (defensive).
  - In `MetadataCalendars.php` nation-attach loop (:332-337), skip diocesan items whose `rite === Rite::AMBROSIAN` (they have no national parent).

- [ ] **Step 4: Run → PASS.** Also run the existing metadata/`/calendars` tests → Roman discovery unchanged.

- [ ] **Step 5: `composer analyse && composer lint` clean. Commit.**

---

## Task 7: Rite-scoped validation + data-driven whitelist

**Files:**

- Modify: `src/Params/CalendarParams.php` (`validateDiocesanCalendarParam`, `validateRiteCompatibility`)
- Modify: `src/Models/Calendar/Rite/AmbrosianRiteProfile.php` (data-driven `SUPPORTED_DIOCESES`)
- Test: `phpunit_tests/Params/CalendarParamsTest.php` (or a handler test)

**Interfaces:**

- Consumes: Task 6 (rite-tagged discovery).
- Produces: rite-scoped diocese validity.

- [ ] **Step 1: Write failing tests** — an Ambrosian-rite request for `milano_it` passes validation; a ROMAN-rite request
  for `milano_it` throws `ValidationException` (400); an Ambrosian-rite request for a Roman diocese (`agrige_it`) throws.

- [ ] **Step 2: Run → FAIL.**

- [ ] **Step 3: Implement.**
  - In `validateDiocesanCalendarParam()` (:450-461): after the existence check, look up the requested diocese's `rite` from
    `$this->calendars->diocesan_calendars` (find the item by `calendar_id`) and throw `ValidationException` (400) if it
    differs from `$this->Rite`. Message: `"Diocesan calendar`{$value}` belongs to the {$dioceseRite} rite, not the
    requested {$requestRite} rite."`.
  - In `validateRiteCompatibility()` (:662-693): the diocese-in-`SUPPORTED_DIOCESES` check at :674 is now redundant with the
    rite-scoped existence check — remove it (keep the `NationalCalendar !== null` guard :668 and the year lower-limit :682).
  - `AmbrosianRiteProfile::SUPPORTED_DIOCESES` (:31): keep the constant but mark it deprecated in the docblock (no longer
    the enforcement path), OR replace its sole remaining reference. Since :674 is removed, the constant becomes unused —
    remove it and its docblock, and delete the now-unused reference in the error message at :678.

- [ ] **Step 4: Run → PASS.** Existing Roman diocese validation unchanged.

- [ ] **Step 5: `composer analyse && composer lint` clean. Commit.**

---

## Task 8: Rite-guard `loadDiocesanCalendarData()` (decoupling)

**Files:**

- Modify: `src/Handlers/CalendarHandler.php` (`loadDiocesanCalendarData` :428-462)
- Test: `phpunit_tests/Handlers/CalendarHandlerAmbrosianDiocesanTest.php` (create)

**Interfaces:**

- Consumes: Tasks 3, 4/5.
- Produces: `$this->DiocesanData` populated from the Ambrosian tree with `NationalCalendar` left null for Ambrosian.

- [ ] **Step 1: Write failing test** (via the handler's in-process pattern, `AbstractHandlerTestCase`): a request
  `/calendar/ambrosian/diocese/lugano_ch/2025` reaches `loadDiocesanCalendarData` and leaves
  `CalendarParams->NationalCalendar === null` and `DiocesanData !== null`, with NO exception (no `nations/CH` lookup).
  Assert `DiocesanData->metadata->diocese_id === 'lugano_ch'`.

- [ ] **Step 2: Run → FAIL** (currently sets `NationalCalendar='CH'` → later national load throws).

- [ ] **Step 3: Implement.** Add a rite branch at the top of `loadDiocesanCalendarData()`:
  - When `$this->CalendarParams->Rite === Rite::AMBROSIAN`: resolve name+nation via the same `world_dioceses.json` lookup,
    build the file path from `JsonData::AMBROSIAN_DIOCESAN_CALENDAR_FILE` (substituting
    `{nation}`/`{diocese}`/`{diocese_name}`), parse into `DiocesanData::fromObject(...)`, and DO NOT set
    `$this->CalendarParams->NationalCalendar`.
  - Roman path (existing :440-460) unchanged, including the :449 nation coupling.

- [ ] **Step 4: Run → PASS.** Roman diocesan load unchanged (add a Roman assertion that `agrige_it` still sets `NationalCalendar='IT'`).

- [ ] **Step 5: `composer analyse && composer lint` clean. Commit.**

---

## Task 9: `applyAmbrosianDiocesanCalendar()` overlay + orchestrator insert

**Files:**

- Modify: `src/Handlers/CalendarHandler.php` (new method + insert in `calculateAmbrosianCalendar()` :1061-1063)
- Test: `phpunit_tests/Handlers/CalendarHandlerAmbrosianDiocesanTest.php`

**Interfaces:**

- Consumes: Tasks 2 (emptyFestive), 8 (DiocesanData loaded).
- Produces: diocesan events in `$this->Cal` before season-stamp/precedence.

- [ ] **Step 1: Write failing test** (reflection-invoke the orchestrator on an Ambrosian diocese, as
  `CalendarHandlerAmbrosianSanctoraleLoadTest` does): after `calculateAmbrosianCalendar()` for `milano_it` 2025,
  `$this->Cal` contains a known Milan-proper event_key; for `lugano_ch`, the S. Francesco override event has grade
  MEMORIAL (not the comune Festa); every diocesan event has `readings instanceof ReadingsFestive`.

- [ ] **Step 2: Run → FAIL** (no diocesan step).

- [ ] **Step 3: Implement.** New method mirroring `addAmbrosianSanctoraleToCalendar()` (:936) but add-all (not skip-existing) and festive readings:

```php
private function applyAmbrosianDiocesanCalendar(): void
{
    if (null === $this->DiocesanData) {
        return;
    }
    $year = $this->CalendarParams->Year;
    foreach ($this->DiocesanData->litcal as $item) {
        $le = $item->liturgical_event;
        $meta = $item->metadata;
        if ($year < $meta->since_year || (isset($meta->until_year) && $meta->until_year !== null && $year > $meta->until_year)) {
            continue;
        }
        // fixed-date diocesan events (month/day); mirror applyDiocesanCalendar date logic for strtotime if present
        $date = DateTime::fromFormat($le->day . '-' . $le->month . '-' . $year);
        $key  = $le->event_key;
        // diocesan-wins: replace any existing (comune) event with the same key
        if (null !== $this->Cal->getLiturgicalEvent($key)) {
            $this->Cal->removeLiturgicalEvent($key);
        }
        $litEvent = LiturgicalEvent::fromObject($le);
        $litEvent->setDate($date);
        $litEvent->setReadings(AmbrosianReadings::emptyFestive());
        $this->Cal->addLiturgicalEvent($key, $litEvent);
    }
}
```

(Adjust property access to the real `DiocesanData`/`DiocesanLitCalItem` shape — read the DTO; `applyDiocesanCalendar()`
:4408-4467 is the reference for date resolution incl. mobile `strtotime`. Diocesan i18n names: apply the diocesan i18n
like the Roman path does — verify `DiocesanData->i18n` / `applyTranslations()` provides the name; if the Ambrosian
branch skips `applyCalendarI18nData()`, load the diocesan i18n here or set names from `DiocesanData->i18n`.)

Insert the call in `calculateAmbrosianCalendar()` between `addAmbrosianSanctoraleToCalendar()` (:1061) and `backfillAmbrosianReadingsPlaceholder()` (:1063):

```php
        $this->addAmbrosianSanctoraleToCalendar();
        $this->applyAmbrosianDiocesanCalendar();   // NEW
        $this->backfillAmbrosianReadingsPlaceholder();
```

- [ ] **Step 4: Run → PASS.**

- [ ] **Step 5:** Verify comune unchanged: reflection-invoke `calculateAmbrosianCalendar()` with NO diocese (DiocesanData
  null) → identical event count to pre-change (the method early-returns). Add that assertion.

- [ ] **Step 6: `composer analyse && composer lint` clean. Commit.**

---

## Task 10: Wire `/calendar/ambrosian/diocese/{id}` live

**Files:**

- Modify: `src/Handlers/CalendarHandler.php` (`handle()` Ambrosian branch :5337; the diocesan load must run before `calculateAmbrosianCalendar()`; update stale comment :5338)
- Test: `phpunit_tests/Handlers/CalendarHandlerAmbrosianDiocesanTest.php` (full `handle()` path)

**Interfaces:**

- Consumes: Tasks 8, 9.
- Produces: live endpoint.

- [ ] **Step 1: Write failing test** — full `handle()` for `/calendar/ambrosian/diocese/milano_it/2025?year_type=CIVIL`
  returns 200 with the Milan-proper events present and the comune events intact;
  `/calendar/ambrosian/diocese/lugano_ch/2025` returns 200 (no CH error).

- [ ] **Step 2: Run → FAIL.**

- [ ] **Step 3: Implement.** Confirm the diocesan load happens for the Ambrosian branch: `loadDiocesanCalendarData()` is
  already called at `handle()` :5292 (before the rite branch) when `DiocesanCalendar` is set — verify it runs for
  Ambrosian and that `DiocesanData` is populated before `calculateAmbrosianCalendar()` (:5345). If the Ambrosian branch
  bypasses the diocesan load, add it. Update the stale comment at :5338. Ensure the `YearType::LITURGICAL` two-run
  (:5347-5374) also overlays diocesan in the prior-year pass (the diocesan overlay is inside
  `calculateAmbrosianCalendar()`, so both runs get it automatically).

- [ ] **Step 4: Run → PASS.**

- [ ] **Step 5: GOLDEN-MASTER GATE.** `vendor/bin/phpunit phpunit_tests/Handlers/CalendarGoldenMasterTest.php` → 9/9 byte-identical (Roman untouched). Commit only if green.

- [ ] **Step 6: `composer analyse && composer lint` clean. Commit.**

---

## Task 11: `/calendars` announces Ambrosian dioceses

**Files:**

- Modify: `src/Models/Metadata/MetadataCalendars.php` / serialization (if the `rite` on diocesan items isn't already surfaced by Task 6)
- Test: `phpunit_tests/Handlers/MetadataHandlerTest.php` (or Routes/Readonly/CalendarsTest)

- [ ] **Step 1: Write failing test** — `/calendars` response `diocesan_calendars` includes the 4 Ambrosian dioceses, each
  with `rite: "ambrosian"`, and `diocesan_calendars_keys` includes them.

- [ ] **Step 2: Run → FAIL** (or already partially passing from Task 6 — adjust).

- [ ] **Step 3: Implement.** Ensure `MetadataDiocesanCalendarItem::jsonSerialize()` emits `rite`, and the four Ambrosian
  dioceses appear in the serialized `diocesan_calendars`. (Most of this lands in Task 6; this task confirms the HTTP
  surface + adds the `/calendars` assertion.)

- [ ] **Step 4: Run → PASS. `composer analyse && composer lint` clean. Commit.**

---

## Task 12: `/events/ambrosian/diocese/{id}` catalog

**Files:**

- Modify: `src/Router.php`, `src/Params/EventsParams.php`, `src/Handlers/EventsHandler.php`
- Test: `phpunit_tests/Handlers/EventsHandlerTest.php` + `phpunit_tests/Params/EventsParamsTest.php`

**Interfaces:**

- Consumes: Tasks 3, 4/5.
- Produces: diocesan event catalog for the Ambrosian rite.

- [ ] **Step 1: Write failing test** — `/events/ambrosian/diocese/milano_it` returns 200 with the Milan diocesan
  event_keys in the catalog; an Ambrosian+diocese EventsParams validates; a Roman diocese under
  `/events/ambrosian/diocese/…` is rejected (rite mismatch).

- [ ] **Step 2: Run → FAIL.**

- [ ] **Step 3: Implement.** Mirror the Plan 7 `/events/ambrosian` (comune) extension: Router accepts
  `ambrosian/diocese/{id}` for the events route; `EventsParams` gains the diocese dimension under the Ambrosian rite
  (reuse the rite-scoped validity from Task 7); the events processing loads the Ambrosian diocesan sanctorale (from
  `AMBROSIAN_DIOCESAN_CALENDAR_FILE`) and merges it with the comune catalog. Read the Plan-7 comune `/events/ambrosian`
  implementation (`processAmbrosianSanctorale` / EventsParams Rite handling) as the template.

- [ ] **Step 4: Run → PASS. `composer analyse && composer lint` clean. Commit.**

---

## Task 13: Schema-validation wiring + final gates

**Files:**

- Modify: `src/Health.php` (or the Health-wired schema map) + `phpunit_tests/Schemas/SchemaValidationTest.php`
- Test: the above + full-suite gates

**Interfaces:**

- Consumes: all prior tasks.

- [ ] **Step 1:** Wire the four Ambrosian diocesan files into schema validation. Add their paths (via
  `AMBROSIAN_DIOCESAN_CALENDARS_FOLDER` glob) to the Health/`SchemaValidationTest` corpus, validating against
  `DiocesanCalendar.json`. Write a test asserting all four validate.

- [ ] **Step 2: Run → PASS** (files from Tasks 4/5 validate incl. the new `rite` field).

- [ ] **Step 3: FULL GATES.** `composer test` (note: localhost:8000 Routes + Postgres tests are CI-only in the sandbox —
  the 18 infra errors are expected/unrelated), `composer analyse` (PHPStan 10), `composer lint`, `composer parallel-lint`,
  and **golden-master 9/9 byte-identical**. Record results.

- [ ] **Step 4: Commit.**

---

## Final Verification (manual, before opening the PR)

- [ ] Frontend docker stack (litcal-api mounting this worktree, per the 8a stack-test tip):
  `/calendar/ambrosian/diocese/milano_it/2025?year_type=CIVIL` → 200 with Milan saints;
  `/calendar/ambrosian/diocese/lugano_ch/2025` → 200, S. Francesco (Oct 4) at Memoria, no CH error; a shared saint (S.
  Giovanni XXIII, Oct 11) present in both milano_it and bergam_it; `/calendar/ambrosian/2025` (comune, no diocese)
  unchanged; `/calendars` lists the 4 Ambrosian dioceses; `/events/ambrosian/diocese/milano_it` populated.
- [ ] UnitTestInterface liturgical tests still green (it reads `rite/ambrosian/…` after UTI#38; confirm any diocesan validation it performs).
- [ ] `git log --stat` sane; markdown lint the spec + this plan (`composer lint:md`).

---

## Self-Review Notes

- **Spec coverage:** schema rite (T1) ✓; festive readings (T2, T9) ✓; discovery constant (T3) ✓; data incl.
  shared+override (T4/T5) ✓; rite-aware discovery + skip-nation-attach + data-driven whitelist (T6/T7) ✓; decoupling (T8)
  ✓; overlay method + insert point + diocesan-wins via remove+add (T9) ✓; /calendar wire + golden-master (T10) ✓;
  /calendars (T11) ✓; /events parity (T12) ✓; schema-validation wiring + gates (T13) ✓; 8c exclusions honored (T4/T5) ✓.
- **Type consistency:** `Rite` enum used across T1/T6/T7/T8; `AmbrosianReadings::emptyFestive()` (T2) consumed in T9;
  `AMBROSIAN_DIOCESAN_CALENDAR_FILE` (T3) consumed in T8/T12; `MetadataDiocesanCalendarItem->rite` (T6) consumed in
  T7/T11.
- **Golden-master gated** at T10 and T13; Roman path assertions in T8.
- **Open implementation detail flagged for the implementer** (T9 Step 3): confirm the real
  `DiocesanData`/`DiocesanLitCalItem` property shape and how diocesan i18n names are applied on the Ambrosian branch (the
  branch skips `applyCalendarI18nData()`); resolve by reading the DTO + the Roman `applyDiocesanCalendar()` reference.
