# Missals Rite-Awareness Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task.
> Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `/missals` rite-aware so the Ambrosian sanctorale on disk becomes reachable, with `/missals/{rite}` canonical and bare `/missals` continuing to mean `roman`.

**Architecture:** A `MissalSource` interface with a `MissalCatalog::for(Rite)` resolver; `RomanMissal` and the already-existing `AmbrosianMissal` become its two
implementations. `MissalMetadataMap` stops deriving identity from folder names and asks the rite's source instead. The router's existing rite-segment machinery gains
`missals`, and FGA object ids become rite-qualified under today's type name.

**Tech Stack:** PHP 8.4, PHPUnit 12, PHPStan level 10, PSR-12 via phpcs, OpenAPI 3.1 (Redocly), OpenFGA.

**Spec:** `docs/superpowers/specs/2026-08-31-missals-rite-awareness-design.md`

## Global Constraints

- PHP >= 8.4; PSR-12 with short array syntax, 4-space indent, single quotes preferred.
- PHPStan level 10 must pass: `composer analyse` (scans `src` only). Never add `@phpstan-ignore`, baseline entries, `assert()`, or casts to silence it.
- `composer lint` (phpcs), `composer lint:md`, `composer lint:openapi`, `composer lint:jsondata`, `composer lint:missals` must all pass before the final commit.
- **Never mutate `jsondata/` in a test.** Use `phpunit_tests/Support/ShadowProjectRootTrait.php` if a test needs a writable tree.
- `openapi.json` canonical encoding is `ensure_ascii=FALSE` (non-ASCII stays literal). Verify with `composer lint:jsondata`, not just `lint:openapi`.
- New tests use the `#[Group('slow')]` ATTRIBUTE only if measurably slow; default is no group.
- Public identifier decisions are fixed: Ambrosian missal id is `EDITIO_2024`, region `AMBROSIAN`.
- FGA object type stays `general_roman_calendar` in this change; only ids gain a rite prefix. The rename is #955 and is out of scope.
- Run tests with `composer test:quick`, never a bare `--exclude-group` (CLI overrides the XML fence).

---

## File Structure

| File                                                              | Responsibility                                                                    |
| ----------------------------------------------------------------- | --------------------------------------------------------------------------------- |
| `src/Enum/MissalSource.php` (new)                                 | Interface every rite's missal registry satisfies                                  |
| `src/Enum/MissalCatalog.php` (new)                                | `for(Rite): MissalSource` resolver                                                |
| `src/Enum/RomanMissal.php` (modify)                               | Implements `MissalSource`; `isLatinMissal()` → `isEditioTypica()`                 |
| `src/Enum/AmbrosianMissal.php` (modify)                           | Implements `MissalSource`; gains region/tier/lectionary/produceMetadata           |
| `src/Models/MissalsPath/MissalMetadataMap.php` (modify)           | Rite-scoped index; identity from the source, not folder names; per-rite cache key |
| `src/Handlers/MissalsHandler.php` (modify)                        | Takes a `Rite`; resolves through `MissalCatalog`                                  |
| `src/Router.php` (modify)                                         | `missals` joins the rite-segment and canonical-URL allow-lists                    |
| `src/Http/Middleware/OpenFgaAuthorizationMiddleware.php` (modify) | `forMissals()` gains a rite; ids rite-qualified                                   |
| `src/Services/ChangeResource.php` (modify)                        | `isEditioTypica()` rename call site                                               |
| `jsondata/schemas/openapi.json` (modify)                          | Four rite-scoped path items; `region` gains `AMBROSIAN`                           |
| `scripts/migrate-missal-fga-tuples.php` (new)                     | Idempotent tuple migration for unqualified ids                                    |

---

### Task 1: Rename `isLatinMissal()` to `isEditioTypica()`

Pure rename with no behaviour change, landed first so later tasks build on the final name. See spec §4.3.

**Files:**

- Modify: `src/Enum/RomanMissal.php:188` (`isLatinMissal`), `:287` (`getLatinMissalIds`)
- Modify: `src/Handlers/MissalsHandler.php:269`, `:450`
- Modify: `src/Services/ChangeResource.php:100`
- Modify: `src/Http/Middleware/OpenFgaAuthorizationMiddleware.php:385`
- Modify: `src/Handlers/EventsHandler.php:426`
- Test: `phpunit_tests/Enum/RomanMissalTest.php:41-44`, `:136`

**Interfaces:**

- Consumes: nothing.
- Produces: `RomanMissal::isEditioTypica(string $missal_id): bool` and `RomanMissal::getEditioTypicaIds(): string[]`, replacing `isLatinMissal()` and `getLatinMissalIds()`. No
  other signature changes.

- [ ] **Step 1: Update the existing test to the new name**

In `phpunit_tests/Enum/RomanMissalTest.php`, replace the four `isLatinMissal` assertions and the `getLatinMissalIds` call:

```php
    public function testIsEditioTypica(): void
    {
        self::assertTrue(RomanMissal::isEditioTypica(RomanMissal::EDITIO_TYPICA_1970));
        self::assertTrue(RomanMissal::isEditioTypica(RomanMissal::EDITIO_TYPICA_TERTIA_2002));
        self::assertFalse(RomanMissal::isEditioTypica(RomanMissal::USA_EDITION_2011));
        self::assertFalse(RomanMissal::isEditioTypica('not-a-missal'));
    }
```

Rename the method that was `testIsLatinMissal`, and at `:136` change `$latin = RomanMissal::getLatinMissalIds();` to `$editioTypica = RomanMissal::getEditioTypicaIds();`,
updating the assertions that use `$latin` to use `$editioTypica`.

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit phpunit_tests/Enum/RomanMissalTest.php`
Expected: FAIL — `Error: Call to undefined method LiturgicalCalendar\Api\Enum\RomanMissal::isEditioTypica()`

- [ ] **Step 3: Rename in `RomanMissal`**

```php
    public static function isEditioTypica(string $missal_id): bool
    {
        return in_array($missal_id, self::$values) && str_starts_with($missal_id, 'EDITIO_TYPICA_');
    }
```

```php
    public static function getEditioTypicaIds(): array
    {
        return array_values(array_filter(self::$values, static fn (string $missal_id): bool => self::isEditioTypica($missal_id)));
    }
```

Update the docblocks: the concept is the *typical edition* — the normative base from which regional missals are computed as deltas — not "a Latin missal".

- [ ] **Step 4: Update the five production call sites**

Replace `RomanMissal::isLatinMissal(` with `RomanMissal::isEditioTypica(` at `MissalsHandler:269`, `MissalsHandler:450`, `ChangeResource:100`,
`OpenFgaAuthorizationMiddleware:385`; replace `RomanMissal::getLatinMissalIds()` with `RomanMissal::getEditioTypicaIds()` at `EventsHandler:426` and in the `{@see}` docblock
at `EventsHandler:461`.

- [ ] **Step 5: Verify nothing references the old names**

Run: `grep -rn "isLatinMissal\|getLatinMissalIds" --include=*.php src/ phpunit_tests/`
Expected: no output.

- [ ] **Step 6: Run the full quick suite**

Run: `composer test:quick`
Expected: PASS. This is a pure rename, so any failure is a missed call site.

- [ ] **Step 7: Static analysis and lint**

Run: `composer analyse && composer lint`
Expected: both clean.

- [ ] **Step 8: Commit**

```bash
git add src phpunit_tests
git commit -m "refactor: rename isLatinMissal to isEditioTypica

An editio typica is the normative base from which regional sanctorales are
computed as deltas — a statement about authority, not language. Three of the
four call sites already asked an authority question.

Pure rename; no behaviour change.

Refs #953"
```

---

### Task 2: The `MissalSource` interface and `MissalCatalog`

See spec §4.1. Behaviour-neutral: both classes already have these methods; this names the contract.

**Files:**

- Create: `src/Enum/MissalSource.php`
- Create: `src/Enum/MissalCatalog.php`
- Modify: `src/Enum/RomanMissal.php` (add `implements MissalSource`, add `isEditioTypica` already done, add `regionFor`)
- Modify: `src/Enum/AmbrosianMissal.php` (add `implements MissalSource`, add missing methods)
- Test: `phpunit_tests/Enum/MissalCatalogTest.php` (new)

**Interfaces:**

- Consumes: `RomanMissal::isEditioTypica()` from Task 1.
- Produces:
  - `MissalCatalog::for(Rite $rite): MissalSource`
  - `MissalSource` static methods: `getMissalIds(): string[]`, `isValid(string): bool`, `getName(string): string`, `getSanctoraleFileName(string): string|false`,
    `getSanctoraleI18nFilePath(string): string|false`, `getLectionaryFilePath(string): string|false`, `getYearLimits(string): array{since_year:int,until_year?:int}`,
    `isEditioTypica(string): bool`, `regionFor(string): string`, `rite(): Rite`
  - `AmbrosianMissal::regionFor()` returns `'AMBROSIAN'`; `AmbrosianMissal::isEditioTypica()` returns `true` for every declared id; `AmbrosianMissal::getLectionaryFilePath()`
    returns `false` for every id.

PHP does not allow `static` methods in an interface to be called polymorphically through an instance in the way this plan needs, so `MissalSource` is declared as an interface
of **instance** methods and each rite gets a thin instance wrapper delegating to its existing statics. This keeps every existing static call site working untouched.

- [ ] **Step 1: Write the failing test**

Create `phpunit_tests/Enum/MissalCatalogTest.php`:

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Enum;

use LiturgicalCalendar\Api\Enum\MissalCatalog;
use LiturgicalCalendar\Api\Enum\Rite;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MissalCatalog::class)]
final class MissalCatalogTest extends TestCase
{
    public function testTheRomanSourceKnowsTheRomanMissals(): void
    {
        $source = MissalCatalog::for(Rite::ROMAN);

        self::assertSame(Rite::ROMAN, $source->rite());
        self::assertContains('EDITIO_TYPICA_1970', $source->getMissalIds());
        self::assertTrue($source->isEditioTypica('EDITIO_TYPICA_1970'));
        self::assertFalse($source->isEditioTypica('US_2011'));
        self::assertSame('VA', $source->regionFor('EDITIO_TYPICA_1970'));
        self::assertSame('US', $source->regionFor('US_2011'));
    }

    public function testTheAmbrosianSourceKnowsTheAmbrosianMissal(): void
    {
        $source = MissalCatalog::for(Rite::AMBROSIAN);

        self::assertSame(Rite::AMBROSIAN, $source->rite());
        self::assertSame(['EDITIO_2024'], $source->getMissalIds());
        self::assertSame('AMBROSIAN', $source->regionFor('EDITIO_2024'));
    }

    /**
     * EDITIO_2024 is a typical edition — the normative base for the Ambrosian rite — while
     * matching no `EDITIO_TYPICA_` prefix. That is the whole reason the tier stopped being a
     * prefix test (#953, spec §4.3).
     */
    public function testTheAmbrosianEditionIsATypicalEditionDespiteItsIdPrefix(): void
    {
        self::assertTrue(MissalCatalog::for(Rite::AMBROSIAN)->isEditioTypica('EDITIO_2024'));
        self::assertStringStartsNotWith('EDITIO_TYPICA_', 'EDITIO_2024');
    }

    public function testTheRitesDoNotShareIds(): void
    {
        $roman     = MissalCatalog::for(Rite::ROMAN)->getMissalIds();
        $ambrosian = MissalCatalog::for(Rite::AMBROSIAN)->getMissalIds();

        self::assertSame([], array_intersect($roman, $ambrosian), 'a missal id must name one missal in one rite');
    }

    public function testTheAmbrosianMissalHasNoLectionary(): void
    {
        self::assertFalse(MissalCatalog::for(Rite::AMBROSIAN)->getLectionaryFilePath('EDITIO_2024'));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit phpunit_tests/Enum/MissalCatalogTest.php`
Expected: FAIL — `Error: Class "LiturgicalCalendar\Api\Enum\MissalCatalog" not found`

- [ ] **Step 3: Create the interface**

Create `src/Enum/MissalSource.php`:

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Enum;

/**
 * One rite's registry of missal editions.
 *
 * `RomanMissal` and `AmbrosianMissal` each hold their rite's ids, names, file paths and year
 * limits as static maps, and every existing call site reaches them statically. This interface
 * is the polymorphic face of those registries, so that code which must work for *whichever*
 * rite a request names — the metadata index, the handler — can resolve one through
 * {@see MissalCatalog::for()} instead of hardcoding `RomanMissal::`.
 *
 * Instance methods rather than statics: PHP cannot dispatch a static call polymorphically
 * through an interface, and the point here is precisely to choose the implementation at runtime.
 * Each implementation is a thin wrapper delegating to its own statics, which stay public so the
 * existing static call sites are untouched.
 */
interface MissalSource
{
    /** The rite whose missals this source registers. */
    public function rite(): Rite;

    /** @return string[] every missal id this rite declares */
    public function getMissalIds(): array;

    public function isValid(string $missalId): bool;

    public function getName(string $missalId): string;

    public function getSanctoraleFileName(string $missalId): string|false;

    public function getSanctoraleI18nFilePath(string $missalId): string|false;

    /** False when this missal ships no lectionary of its own; the caller falls back to the rite's. */
    public function getLectionaryFilePath(string $missalId): string|false;

    /** @return array{since_year:int,until_year?:int} */
    public function getYearLimits(string $missalId): array;

    /**
     * Whether this edition is a typical edition: the normative base from which regional missals
     * of the same rite are computed as deltas.
     *
     * Deliberately NOT `str_starts_with($id, 'EDITIO_TYPICA_')`. That was a naming convention
     * doing type duty, and it answers wrongly for the Ambrosian `EDITIO_2024`, which is a typical
     * edition carrying no such prefix (#953).
     */
    public function isEditioTypica(string $missalId): bool;

    /**
     * The region a missal's events are filed under: `VA` for a Roman typical edition, the nation
     * code for a Roman national edition, `AMBROSIAN` for the Ambrosian rite.
     */
    public function regionFor(string $missalId): string;
}
```

- [ ] **Step 4: Create the two wrappers and the catalog**

Create `src/Enum/MissalCatalog.php`:

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Enum;

/**
 * Resolves the {@see MissalSource} for a rite.
 *
 * Every rite partitions its missals the same way in the source tree, so both branches always
 * exist — the same guarantee {@see JsonData::missalsFolderFor()} relies on.
 */
final class MissalCatalog
{
    public static function for(Rite $rite): MissalSource
    {
        return match ($rite) {
            Rite::ROMAN     => new RomanMissalSource(),
            Rite::AMBROSIAN => new AmbrosianMissalSource(),
        };
    }
}
```

Create `src/Enum/RomanMissalSource.php`:

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Enum;

/**
 * {@see MissalSource} over {@see RomanMissal}. Delegation only: the statics remain the single
 * definition of Roman missal identity, and every existing static call site keeps working.
 */
final class RomanMissalSource implements MissalSource
{
    public function rite(): Rite
    {
        return Rite::ROMAN;
    }

    /** @return string[] */
    public function getMissalIds(): array
    {
        return RomanMissal::getMissalIds();
    }

    public function isValid(string $missalId): bool
    {
        return RomanMissal::isValid($missalId);
    }

    public function getName(string $missalId): string
    {
        return RomanMissal::getName($missalId);
    }

    public function getSanctoraleFileName(string $missalId): string|false
    {
        return RomanMissal::getSanctoraleFileName($missalId);
    }

    public function getSanctoraleI18nFilePath(string $missalId): string|false
    {
        return RomanMissal::getSanctoraleI18nFilePath($missalId);
    }

    public function getLectionaryFilePath(string $missalId): string|false
    {
        return RomanMissal::getLectionaryFilePath($missalId);
    }

    /** @return array{since_year:int,until_year?:int} */
    public function getYearLimits(string $missalId): array
    {
        return RomanMissal::getYearLimits($missalId);
    }

    public function isEditioTypica(string $missalId): bool
    {
        return RomanMissal::isEditioTypica($missalId);
    }

    public function regionFor(string $missalId): string
    {
        return RomanMissal::regionFor($missalId);
    }
}
```

Create `src/Enum/AmbrosianMissalSource.php`:

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Enum;

/**
 * {@see MissalSource} over {@see AmbrosianMissal}.
 *
 * Every Ambrosian edition currently declared is a typical edition of the Ambrosian rite, and none
 * ships a lectionary — `/lectionary/ambrosian/sanctorale` reports that absence honestly, and this
 * change does not invent readings.
 */
final class AmbrosianMissalSource implements MissalSource
{
    public function rite(): Rite
    {
        return Rite::AMBROSIAN;
    }

    /** @return string[] */
    public function getMissalIds(): array
    {
        return AmbrosianMissal::getMissalIds();
    }

    public function isValid(string $missalId): bool
    {
        return AmbrosianMissal::isValid($missalId);
    }

    public function getName(string $missalId): string
    {
        return AmbrosianMissal::getName($missalId);
    }

    public function getSanctoraleFileName(string $missalId): string|false
    {
        return AmbrosianMissal::getSanctoraleFileName($missalId);
    }

    public function getSanctoraleI18nFilePath(string $missalId): string|false
    {
        return AmbrosianMissal::getSanctoraleI18nFilePath($missalId);
    }

    public function getLectionaryFilePath(string $missalId): string|false
    {
        return false;
    }

    /** @return array{since_year:int,until_year?:int} */
    public function getYearLimits(string $missalId): array
    {
        return AmbrosianMissal::getYearLimits($missalId);
    }

    public function isEditioTypica(string $missalId): bool
    {
        return AmbrosianMissal::isValid($missalId);
    }

    public function regionFor(string $missalId): string
    {
        return AmbrosianMissal::REGION;
    }
}
```

- [ ] **Step 5: Add `regionFor()` to `RomanMissal` and `REGION` to `AmbrosianMissal`**

In `src/Enum/RomanMissal.php`, add — this is the derivation lifted verbatim out of `produceMetadata()` and `MissalsHandler::resolveSanctoraleTarget()`, so both can call it
instead of repeating it:

```php
    /**
     * The region a missal's events are filed under.
     *
     * `VA` for a typical edition (it is not nation-specific); otherwise the nation code that
     * prefixes the id. Previously duplicated inline in produceMetadata() and in
     * MissalsHandler::resolveSanctoraleTarget().
     */
    public static function regionFor(string $missal_id): string
    {
        if (false === self::isValid($missal_id)) {
            throw new ValidationException('Invalid missal_id: ' . $missal_id);
        }

        return self::isEditioTypica($missal_id) ? 'VA' : explode('_', $missal_id)[0];
    }
```

In `src/Enum/AmbrosianMissal.php`, add the constant:

```php
    /**
     * The region every Ambrosian edition is filed under.
     *
     * Not `IT`: filing it under Italy would place it beside IT_1983 and IT_2020 as though it were
     * another Italian Roman-rite national missal, and `region` is what decides which national
     * calendars a missal layer applies to. Not `VA` either, which would misattribute Milan's
     * missal to the Vatican.
     */
    public const REGION = 'AMBROSIAN';
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `vendor/bin/phpunit phpunit_tests/Enum/MissalCatalogTest.php`
Expected: PASS (5 tests)

- [ ] **Step 7: Static analysis**

Run: `composer analyse && composer lint`
Expected: both clean.

- [ ] **Step 8: Commit**

```bash
git add src/Enum phpunit_tests/Enum/MissalCatalogTest.php
git commit -m "feat: add MissalSource and MissalCatalog

Names the contract RomanMissal and AmbrosianMissal already satisfy, so code
that must work for whichever rite a request names can resolve one at runtime
instead of hardcoding RomanMissal::.

Instance methods, because PHP cannot dispatch a static call polymorphically;
each wrapper delegates to its rite's existing statics, which are untouched.

Refs #953"
```

---

### Task 3: Rite-scope the metadata index

The load-bearing task. See spec §5.2 — the served `/missals` list comes from folder-name parsing, not from `produceMetadata()`, and pointed at the Ambrosian tree unchanged it
yields `EDITIO_TYPICA_2024` / `VA` and then a 503.

**Files:**

- Modify: `src/Models/MissalsPath/MissalMetadataMap.php:19` (cache key), `:182-270` (`buildIndex`)
- Test: `phpunit_tests/Models/MissalsPath/MissalMetadataMapRiteTest.php` (new)

**Interfaces:**

- Consumes: `MissalCatalog::for()`, `MissalSource` from Task 2.
- Produces: `MissalMetadataMap::__construct(Rite $rite = Rite::ROMAN)`; `MissalMetadataMap::buildIndex()` unchanged in signature. `MissalMetadata::$api_path` becomes
  `/missals/{rite}/{missal_id}`.

- [ ] **Step 1: Write the failing test**

Create `phpunit_tests/Models/MissalsPath/MissalMetadataMapRiteTest.php`:

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Models\MissalsPath;

use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Models\MissalsPath\MissalMetadataMap;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MissalMetadataMap::class)]
final class MissalMetadataMapRiteTest extends TestCase
{
    public function testTheRomanIndexIsUnchanged(): void
    {
        $map = new MissalMetadataMap(Rite::ROMAN);
        $map->buildIndex();

        $ids = $map->getMissalIDs();
        self::assertContains('EDITIO_TYPICA_1970', $ids);
        self::assertContains('US_2011', $ids);
        self::assertNotContains('EDITIO_2024', $ids, 'the Ambrosian edition must not leak into the Roman index');

        $metadata = $map->getMissalMetadata('EDITIO_TYPICA_1970');
        self::assertNotNull($metadata);
        self::assertSame('VA', $metadata->region);
        self::assertStringEndsWith('/missals/roman/EDITIO_TYPICA_1970', (string) $metadata->api_path);
    }

    /**
     * The Ambrosian folder is `propriumdesanctis_2024`, which the old folder-name regex read as
     * `EDITIO_TYPICA_2024` with region `VA` — the wrong id, the wrong region, and a collision with
     * the Roman namespace, after which RomanMissal::getName() threw and the endpoint 503'd (#953).
     */
    public function testTheAmbrosianIndexUsesTheDeclaredIdNotTheFolderName(): void
    {
        $map = new MissalMetadataMap(Rite::AMBROSIAN);
        $map->buildIndex();

        self::assertSame(['EDITIO_2024'], $map->getMissalIDs());

        $metadata = $map->getMissalMetadata('EDITIO_2024');
        self::assertNotNull($metadata);
        self::assertSame('AMBROSIAN', $metadata->region);
        self::assertSame(2024, $metadata->year_published);
        self::assertSame(['it', 'la'], $metadata->locales);
        self::assertStringEndsWith('/missals/ambrosian/EDITIO_2024', (string) $metadata->api_path);
    }

    /**
     * The index is memoised in APCu under one key. Keyed per rite it must not be, or the first
     * rite to build serves the other for the whole 600-second TTL — a collision that survives the
     * request that caused it and so is unusually hard to attribute.
     */
    public function testTheTwoRiteIndexesDoNotShareACacheEntry(): void
    {
        $roman = new MissalMetadataMap(Rite::ROMAN);
        $roman->buildIndex();
        $ambrosian = new MissalMetadataMap(Rite::AMBROSIAN);
        $ambrosian->buildIndex();

        self::assertNotContains('EDITIO_2024', $roman->getMissalIDs());
        self::assertSame(['EDITIO_2024'], $ambrosian->getMissalIDs());

        // Rebuild Roman AFTER Ambrosian: a shared key would now serve the Ambrosian entry.
        $romanAgain = new MissalMetadataMap(Rite::ROMAN);
        $romanAgain->buildIndex();
        self::assertContains('EDITIO_TYPICA_1970', $romanAgain->getMissalIDs());
        self::assertNotContains('EDITIO_2024', $romanAgain->getMissalIDs());
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit phpunit_tests/Models/MissalsPath/MissalMetadataMapRiteTest.php`
Expected: FAIL — `ArgumentCountError` or "too many arguments" on `new MissalMetadataMap(Rite::ROMAN)`, since the constructor takes none today.

- [ ] **Step 3: Make the map rite-aware**

In `src/Models/MissalsPath/MissalMetadataMap.php`, change the cache key to a method and add the rite to the constructor:

```php
    private const string CACHE_KEY_PREFIX = 'litcal_missals_index';

    private readonly Rite $rite;

    public function __construct(Rite $rite = Rite::ROMAN)
    {
        $this->rite = $rite;
        // ... existing constructor body unchanged ...
    }

    /**
     * The APCu key for this rite's index.
     *
     * Per rite, deliberately. A single key let whichever rite built the index first serve the
     * other for the whole 600-second TTL (#953).
     */
    private function cacheKey(): string
    {
        return self::CACHE_KEY_PREFIX . '_' . $this->rite->value;
    }
```

Replace both `self::CACHE_KEY` uses with `$this->cacheKey()`.

- [ ] **Step 4: Take identity from the source instead of the folder name**

In `buildIndex()`, replace the folder glob root and the two `preg_match` branches:

```php
        $source       = MissalCatalog::for($this->rite);
        $missalsPath  = JsonData::missalsFolderFor($this->rite)->path();

        if (false === is_readable($missalsPath)) {
            throw new ServiceUnavailableException('Unable to read the ' . $missalsPath . ' directory');
        }

        $missalFolderPaths = glob($missalsPath . '/propriumdesanctis*', GLOB_ONLYDIR);
        if (false === $missalFolderPaths) {
            throw new ServiceUnavailableException('Unable to read the ' . $missalsPath . ' directory contents');
        }

        if (count($missalFolderPaths) === 0) {
            throw new NotFoundException('No Missals found');
        }

        // The folder scan answers only "which editions are present on disk". Identity — id, region,
        // name, year limits — comes from the rite's MissalSource. Deriving the id from the folder
        // NAME is what made `propriumdesanctis_2024` read as EDITIO_TYPICA_2024 region VA (#953).
        foreach ($source->getMissalIds() as $missalId) {
            $structureFile = $source->getSanctoraleFileName($missalId);
            if (false === $structureFile || false === file_exists($structureFile)) {
                continue;
            }

            $missal = [
                'missal_id' => $missalId,
                'region'    => $source->regionFor($missalId),
            ];

            $i18nPath = $source->getSanctoraleI18nFilePath($missalId);
            if (is_string($i18nPath) && is_readable($i18nPath)) {
                $locales = [];
                foreach (new \DirectoryIterator('glob://' . rtrim($i18nPath, '/\\') . '/*.json') as $f) {
                    $locales[] = $f->getBasename('.json');
                }
                sort($locales);
                $missal['locales'] = $locales;
            } else {
                $missal['locales'] = null;
            }

            $missal['name']           = $source->getName($missalId);
            $missal['year_limits']    = $source->getYearLimits($missalId);
            $missal['year_published'] = $missal['year_limits']['since_year'];
            $missal['api_path']       = Router::$apiPath . '/missals/' . $this->rite->value . '/' . $missalId;
            $this->addMissal(MissalMetadata::fromArray($missal));
        }
```

Add `use LiturgicalCalendar\Api\Enum\MissalCatalog;` and `use LiturgicalCalendar\Api\Enum\Rite;` to the imports.

- [ ] **Step 5: Make `allMissals` rite-aware too**

Replace `$allMissals = RomanMissal::produceMetadata();` with a call that respects the rite. In `RomanMissal::produceMetadata()`, change the hardcoded folder and region derivation:

```php
            $region = self::regionFor($missal_id);
```

and add the equivalent `produceMetadata()` to `AmbrosianMissal`, then in `buildIndex()`:

```php
        /** @var array<string,MissalMetadata> $allMissals */
        $allMissals       = $this->rite === Rite::AMBROSIAN
            ? AmbrosianMissal::produceMetadata()
            : RomanMissal::produceMetadata();
        $this->allMissals = $allMissals;
```

`AmbrosianMissal::produceMetadata()` mirrors the Roman one, using `self::REGION`, `self::$names`, `self::$yearLimits`, and `Router::$apiPath . '/missals/ambrosian/' . $missal_id`.

- [ ] **Step 6: Run the test to verify it passes**

Run: `vendor/bin/phpunit phpunit_tests/Models/MissalsPath/MissalMetadataMapRiteTest.php`
Expected: PASS (3 tests)

- [ ] **Step 7: Run the missals suites and static analysis**

Run: `vendor/bin/phpunit phpunit_tests/Handlers/MissalsHandlerTest.php phpunit_tests/Handlers/MissalsHandlerI18nTest.php && composer analyse`
Expected: PASS and clean. If a handler test fails on `api_path`, that is the expected consequence of Step 4 — update the assertion to the rite-qualified path.

- [ ] **Step 8: Commit**

```bash
git add src phpunit_tests
git commit -m "feat: rite-scope the missals metadata index

buildIndex() derived missal_id and region from the folder NAME, so the Ambrosian
propriumdesanctis_2024 read as EDITIO_TYPICA_2024 region VA, and RomanMissal::
getName() then threw on a Roman id that does not exist — a 503, not a wrong list.
Identity now comes from the rite's MissalSource; the folder scan answers only
which editions are present.

The APCu key is per rite: a single key let whichever rite built first serve the
other for the whole 600-second TTL.

Refs #953"
```

---

### Task 4: Routing — `/missals/{rite}` and the canonical header

See spec §4.4.

**Files:**

- Modify: `src/Router.php:142` (`extractRiteSegment`), `:213` (`canonicalRiteUrl`), `:343` (`new MissalsHandler`)
- Modify: `src/Handlers/MissalsHandler.php:65` (constructor)
- Test: `phpunit_tests/Handlers/MissalsRiteRoutingTest.php` (new)

**Interfaces:**

- Consumes: `MissalCatalog::for()` (Task 2), rite-aware `MissalMetadataMap` (Task 3).
- Produces: `MissalsHandler::__construct(array $requestPathParams = [], Rite $rite = Rite::ROMAN)`.

- [ ] **Step 1: Write the failing test**

Create `phpunit_tests/Handlers/MissalsRiteRoutingTest.php`:

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Handlers\MissalsHandler;
use LiturgicalCalendar\Api\Router;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(MissalsHandler::class)]
#[CoversClass(Router::class)]
final class MissalsRiteRoutingTest extends AbstractHandlerTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        MissalsHandler::$missalsIndex = null;
    }

    public function testTheRiteSegmentIsStrippedFromTheMissalsRoute(): void
    {
        $parts = ['ambrosian', 'EDITIO_2024'];
        $rite  = Router::extractRiteSegment('missals', $parts);

        self::assertSame(Rite::AMBROSIAN, $rite);
        self::assertSame(['EDITIO_2024'], $parts, 'the rite segment must be consumed so shape parsing is unchanged');
    }

    public function testABareMissalsPathMeansRoman(): void
    {
        $parts = [];
        self::assertSame(Rite::ROMAN, Router::extractRiteSegment('missals', $parts));
        self::assertSame([], $parts);
    }

    /**
     * A missal id can never be mistaken for a rite: rite values are lowercase, missal ids upper.
     */
    public function testAMissalIdIsNotMistakenForARite(): void
    {
        $parts = ['EDITIO_TYPICA_1970'];
        self::assertSame(Rite::ROMAN, Router::extractRiteSegment('missals', $parts));
        self::assertSame(['EDITIO_TYPICA_1970'], $parts, 'a missal id must not be consumed as a rite');
    }

    public function testTheBareSpellingAdvertisesTheCanonicalForm(): void
    {
        $url = Router::canonicalRiteUrl('missals', 'GET', false, Rite::ROMAN, ['EDITIO_TYPICA_1970']);
        self::assertIsString($url);
        self::assertStringEndsWith('/missals/roman/EDITIO_TYPICA_1970', $url);
    }

    public function testAnExplicitRiteHasNoCanonicalForm(): void
    {
        self::assertNull(Router::canonicalRiteUrl('missals', 'GET', true, Rite::ROMAN, ['EDITIO_TYPICA_1970']));
    }

    /**
     * canonicalRiteUrl() is restricted to read methods, so a sanctorale write never carries the
     * header — and neither does the CORS preflight that precedes it.
     */
    public function testAWriteHasNoCanonicalForm(): void
    {
        self::assertNull(Router::canonicalRiteUrl('missals', 'PUT', false, Rite::ROMAN, ['US_2011', 'StZzTest']));
    }

    public function testTheAmbrosianCatalogueIsServed(): void
    {
        $response = ( new MissalsHandler([], Rite::AMBROSIAN) )->handle($this->requestFor('GET', '/missals/ambrosian'));

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeJsonBody($response);
        self::assertIsArray($body['litcal_missals']);
        $ids = array_column($body['litcal_missals'], 'missal_id');
        self::assertSame(['EDITIO_2024'], $ids);
    }

    public function testTheRomanCatalogueStillAnswersOnTheBarePath(): void
    {
        $response = ( new MissalsHandler([], Rite::ROMAN) )->handle($this->requestFor('GET', '/missals'));

        self::assertSame(200, $response->getStatusCode());
        $ids = array_column($this->decodeJsonBody($response)['litcal_missals'], 'missal_id');
        self::assertContains('EDITIO_TYPICA_1970', $ids);
        self::assertNotContains('EDITIO_2024', $ids);
    }
}
```

If the catalogue response key is not `litcal_missals`, read the actual key from `MissalsHandler`'s response assembly and correct both assertions before running.

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit phpunit_tests/Handlers/MissalsRiteRoutingTest.php`
Expected: FAIL — `extractRiteSegment('missals', …)` returns `Rite::ROMAN` without consuming the segment, so `testTheRiteSegmentIsStrippedFromTheMissalsRoute` fails on the
array assertion.

- [ ] **Step 3: Add `missals` to both allow-lists**

`src/Router.php:142`:

```php
        if ($route === 'calendar' || $route === '' || $route === 'events' || $route === 'data' || $route === 'lectionary' || $route === 'missals') {
```

`src/Router.php:213`:

```php
        if ($riteSegmentExplicit || false === in_array($route, ['calendar', 'events', 'data', 'lectionary', 'missals'], true)) {
```

Extend the `extractRiteSegment()` docblock with the disambiguation note: rite values are lowercase (`roman`, `ambrosian`) and missal ids uppercase (`EDITIO_TYPICA_1970`,
`EDITIO_2024`), so a missal id can never be consumed as a rite — the same argument `extractTestsRite()` makes for test names.

- [ ] **Step 4: Thread the rite through the handler**

`src/Router.php:343`:

```php
                $missalsHandler = new MissalsHandler($requestPathParts, $rite);
```

`src/Handlers/MissalsHandler.php`:

```php
    private readonly Rite $rite;

    public function __construct(array $requestPathParams = [], Rite $rite = Rite::ROMAN)
    {
        parent::__construct($requestPathParams);
        $this->rite = $rite;
        // ... existing body unchanged ...
    }
```

and at the index build (`:171-173`):

```php
        if (null === self::$missalsIndex) {
            self::$missalsIndex = new MissalMetadataMap($this->rite);
        }
```

- [ ] **Step 5: Resolve missal statics through the catalog**

Replace every `RomanMissal::` call in `MissalsHandler` with `$source = MissalCatalog::for($this->rite);` followed by `$source->…`, at `:264`, `:269`, `:272`, `:318`, `:329`,
`:421`, `:427`, `:428`, `:436`, `:450` and `:760`. At `:450` the `calendar` value becomes:

```php
            'calendar'        => $source->isEditioTypica($missalId)
                ? ( $this->rite === Rite::AMBROSIAN ? 'AMBROSIAN' : 'GENERAL ROMAN' )
                : $source->regionFor($missalId),
```

At `:760`, `declarationsInOtherMissals()` must iterate only the current rite's missals — a shared `event_key` across rites is not a collision, since the rites are separate
calendars.

- [ ] **Step 6: Run the test to verify it passes**

Run: `vendor/bin/phpunit phpunit_tests/Handlers/MissalsRiteRoutingTest.php`
Expected: PASS (8 tests)

- [ ] **Step 7: Run every missals suite**

Run: `vendor/bin/phpunit phpunit_tests/Handlers/MissalsHandlerTest.php phpunit_tests/Handlers/MissalsHandlerI18nTest.php phpunit_tests/Handlers/MissalsHandlerWriteTest.php
phpunit_tests/Handlers/MissalsQueueModeTest.php && composer analyse`
Expected: PASS and clean.

- [ ] **Step 8: Commit**

```bash
git add src phpunit_tests
git commit -m "feat: make /missals rite-aware

missals joins the existing extractRiteSegment() and canonicalRiteUrl()
allow-lists, so /missals/{rite} is canonical while the bare spelling keeps
working and advertises the canonical form via Link: rel=canonical — not a
redirect, which would downgrade POST to GET and break preflighted CORS.

Refs #953"
```

---

### Task 5: Rite-qualified FGA object ids

See spec §4.5. The ids are interim by design; #955 generalises the type.

**Files:**

- Modify: `src/Http/Middleware/OpenFgaAuthorizationMiddleware.php:383-393` (`forMissals`)
- Modify: `src/Router.php:1021` (the `forMissals` call)
- Create: `scripts/migrate-missal-fga-tuples.php`
- Test: `phpunit_tests/Http/Middleware/MissalsFgaObjectIdTest.php` (new)

**Interfaces:**

- Consumes: `MissalCatalog::for()` (Task 2).
- Produces: `OpenFgaAuthorizationMiddleware::forMissals(OpenFgaClient $client, string $missalId, Rite $rite = Rite::ROMAN): self`.

- [ ] **Step 1: Write the failing test**

Create `phpunit_tests/Http/Middleware/MissalsFgaObjectIdTest.php`:

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Http\Middleware;

use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Services\RiteScopedObjectId;
use PHPUnit\Framework\TestCase;

final class MissalsFgaObjectIdTest extends TestCase
{
    /**
     * The ids are interim: #955 generalises general_roman_calendar into a rite-level tier. The
     * rite qualifier is the part that survives that work, which is why it is introduced now.
     */
    public function testATypicalEditionIsQualifiedByItsRite(): void
    {
        self::assertSame('roman/EDITIO_TYPICA_1970', RiteScopedObjectId::qualify(Rite::ROMAN, 'EDITIO_TYPICA_1970'));
        self::assertSame('ambrosian/EDITIO_2024', RiteScopedObjectId::qualify(Rite::AMBROSIAN, 'EDITIO_2024'));
    }

    public function testAQualifiedIdRoundTrips(): void
    {
        $parsed = RiteScopedObjectId::parse('ambrosian/EDITIO_2024');
        self::assertNotNull($parsed);
        self::assertSame(Rite::AMBROSIAN, $parsed[0]);
        self::assertSame('EDITIO_2024', $parsed[1]);
    }
}
```

- [ ] **Step 2: Run the test to verify it passes or fails**

Run: `vendor/bin/phpunit phpunit_tests/Http/Middleware/MissalsFgaObjectIdTest.php`
Expected: PASS — `RiteScopedObjectId` already behaves this way. This test pins the contract the middleware change depends on; if it fails, stop and re-read
`src/Services/RiteScopedObjectId.php` before continuing.

- [ ] **Step 3: Qualify both branches in `forMissals()`**

```php
    public static function forMissals(OpenFgaClient $client, string $missalId, Rite $rite = Rite::ROMAN): self
    {
        $source = MissalCatalog::for($rite);

        // A typical edition is its rite's normative base, so it authorizes against that rite's
        // rite-level calendar object. The TYPE is still general_roman_calendar, which for the
        // Ambrosian rite is a name that has outgrown its contents — see #955, which generalises it
        // to rite_calendar. The rite-qualified ID below is what survives that rename unchanged.
        if ($source->isEditioTypica($missalId)) {
            return new self($client, 'general_roman_calendar', 'calendar_id', RiteScopedObjectId::qualify($rite, $missalId));
        }

        // A national edition is governed by the national calendar it was approved for.
        return new self($client, 'national_calendar', 'calendar_id', RiteScopedObjectId::qualify($rite, $source->regionFor($missalId)));
    }
```

Delete the now-false comment "Missals live only under the Roman source tree".

- [ ] **Step 4: Pass the rite at the call site**

`src/Router.php:1021`:

```php
                $pipeline->pipe(OpenFgaAuthorizationMiddleware::forMissals($fgaClient, $requestPathParts[0], $rite));
```

Update the surrounding comment: the missal id is still path part 0, because `extractRiteSegment()` strips the rite before `configureAuthorizationPipeline()` is called.

- [ ] **Step 5: Write the tuple migration script**

Create `scripts/migrate-missal-fga-tuples.php`, modelled on `scripts/migrate-rite-data-tuples.php`. It must be **idempotent and additive**: read existing
`general_roman_calendar` tuples, skip any whose `calendar_id` already contains `/`, write the `roman/`-qualified equivalent, and leave the unqualified tuple in place so a
rollback is possible. Print a dry-run summary unless `--apply` is passed.

- [ ] **Step 6: Run the middleware and router suites**

Run: `vendor/bin/phpunit phpunit_tests/Http/ phpunit_tests/Handlers/MissalsHandlerWriteTest.php && composer analyse`
Expected: PASS and clean.

- [ ] **Step 7: Commit**

```bash
git add src scripts phpunit_tests
git commit -m "feat: rite-qualify missal FGA object ids

forMissals() said missals live only under the Roman source tree, which is no
longer true. Both branches now qualify the object id with the rite.

The TYPE is deliberately unchanged: general_roman_calendar denotes a rite-level
tier every rite has, and generalising it to rite_calendar is #955. The rite
qualifier introduced here survives that rename unchanged.

Refs #953, #955"
```

---

### Task 6: OpenAPI contract

See spec §5.4.

**Files:**

- Modify: `jsondata/schemas/openapi.json` (paths `/missals`, `/missals/{missal_id}`, `/missals/{missal_id}/i18n`, `/missals/{missal_id}/{event_key}`; `MissalMetadata.region`)
- Test: `phpunit_tests/Schemas/MissalsRitePathsTest.php` (new)

**Interfaces:**

- Consumes: the routes from Task 4.
- Produces: four new path items `/missals/{rite}`, `/missals/{rite}/{missal_id}`, `/missals/{rite}/{missal_id}/i18n`, `/missals/{rite}/{missal_id}/{event_key}`.

- [ ] **Step 1: Write the failing test**

Create `phpunit_tests/Schemas/MissalsRitePathsTest.php`:

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Schemas;

use PHPUnit\Framework\TestCase;

final class MissalsRitePathsTest extends TestCase
{
    /** @return array<string,mixed> */
    private static function openApi(): array
    {
        $raw = file_get_contents(dirname(__DIR__, 2) . '/jsondata/schemas/openapi.json');
        self::assertIsString($raw);
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode($raw, true);
        return $decoded;
    }

    public function testEveryMissalsShapeHasARiteScopedSpelling(): void
    {
        /** @var array{paths: array<string,mixed>} $doc */
        $doc = self::openApi();

        foreach ([
            '/missals/{rite}',
            '/missals/{rite}/{missal_id}',
            '/missals/{rite}/{missal_id}/i18n',
            '/missals/{rite}/{missal_id}/{event_key}',
        ] as $path) {
            self::assertArrayHasKey($path, $doc['paths'], $path . ' must be documented');
        }
    }

    public function testTheBareSpellingsAreRetained(): void
    {
        /** @var array{paths: array<string,mixed>} $doc */
        $doc = self::openApi();

        foreach (['/missals', '/missals/{missal_id}', '/missals/{missal_id}/i18n', '/missals/{missal_id}/{event_key}'] as $path) {
            self::assertArrayHasKey($path, $doc['paths'], $path . ' must keep working for existing clients');
        }
    }

    public function testTheRegionEnumAdmitsAmbrosian(): void
    {
        /** @var array{components: array{schemas: array<string,mixed>}} $doc */
        $doc = self::openApi();
        $encoded = json_encode($doc['components']['schemas']['MissalMetadata']);
        self::assertIsString($encoded);
        self::assertStringContainsString('AMBROSIAN', $encoded, 'region must admit the Ambrosian rite');
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit phpunit_tests/Schemas/MissalsRitePathsTest.php`
Expected: FAIL — `/missals/{rite} must be documented`

- [ ] **Step 3: Add the four rite-scoped path items**

Copy each existing `/missals…` path item to its `/missals/{rite}…` spelling, adding a `rite` path parameter:

```json
{
  "name": "rite",
  "in": "path",
  "required": true,
  "schema": { "type": "string", "enum": ["roman", "ambrosian"] },
  "description": "The liturgical rite whose missals are addressed. The canonical form of every /missals path carries it; the bare spellings are retained and mean `roman`."
}
```

On each bare spelling, add to its `description`: that it is retained for compatibility, means `roman`, and that the response advertises the canonical rite-scoped form via a
`Link: rel=\"canonical\"` header. Add `AMBROSIAN` to `MissalMetadata.region`.

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit phpunit_tests/Schemas/MissalsRitePathsTest.php`
Expected: PASS (3 tests)

- [ ] **Step 5: Lint the contract — both linters**

Run: `composer lint:openapi && composer lint:jsondata`
Expected: both clean. `lint:jsondata` is the one that catches a non-canonical re-encoding (`ensure_ascii`), and `lint:openapi` alone will not.

- [ ] **Step 6: Commit**

```bash
git add jsondata/schemas/openapi.json phpunit_tests/Schemas/MissalsRitePathsTest.php
git commit -m "docs(openapi): document the rite-scoped /missals paths

Four new path items alongside the four bare spellings, which are retained,
mean roman, and advertise the canonical form. MissalMetadata.region admits
AMBROSIAN.

Refs #953"
```

---

### Task 7: End-to-end verification

**Files:**

- Test: `phpunit_tests/Routes/Readonly/MissalsTest.php` (modify — add rite-scoped cases)

**Interfaces:**

- Consumes: everything above.
- Produces: nothing.

- [ ] **Step 1: Add the route-level assertions**

In `phpunit_tests/Routes/Readonly/MissalsTest.php`, add:

```php
    public function testTheAmbrosianCatalogueIsReachableOverHttp(): void
    {
        $response = self::$http->request('GET', '/missals/ambrosian', ['http_errors' => false]);
        $this->assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        $this->assertIsArray($body);
        $ids = array_column($body['litcal_missals'], 'missal_id');
        $this->assertSame(['EDITIO_2024'], $ids);
    }

    public function testTheBareCatalogueAdvertisesTheCanonicalForm(): void
    {
        $response = self::$http->request('GET', '/missals', ['http_errors' => false]);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('/missals/roman', $response->getHeaderLine('Link'));
        $this->assertStringContainsString('rel="canonical"', $response->getHeaderLine('Link'));
    }

    public function testTheAmbrosianSanctoraleRowsAreReachable(): void
    {
        $response = self::$http->request('GET', '/missals/ambrosian/EDITIO_2024', ['http_errors' => false]);
        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertIsArray($body);
        $this->assertNotEmpty($body, 'the 254 Ambrosian sanctorale rows must be served');
    }
```

- [ ] **Step 2: Restart the local server so it runs this branch**

Run: `./stop-server.sh && composer start`
Expected: "Server started successfully". `Routes/*` tests hit the live server, so an unrestarted server would test the previous code.

- [ ] **Step 3: Run the route tests**

Run: `vendor/bin/phpunit phpunit_tests/Routes/Readonly/MissalsTest.php`
Expected: PASS

- [ ] **Step 4: Run the whole suite and every linter**

Run: `composer test:quick && composer analyse && composer lint && composer lint:md && composer lint:openapi && composer lint:jsondata && composer lint:missals`
Expected: all clean. Record the exact test counts in the PR description.

- [ ] **Step 5: Commit**

```bash
git add phpunit_tests
git commit -m "test: cover the rite-scoped /missals routes end to end

Refs #953"
```

---

## Self-Review

**Spec coverage.** §4.1 → Task 2. §4.2 (`EDITIO_2024`, region `AMBROSIAN`) → Tasks 2 and 3. §4.3 (`isEditioTypica`) → Task 1. §4.4 (routing) → Task 4. §4.5 (FGA) → Task 5.
§5.2 (the index) → Task 3. §5.3 (handler) → Task 4. §5.4 (contract) → Task 6. §6 (validation rules) → Tasks 3, 4 and 7.

**Known gap, deliberately deferred.** §6's rule that "`/missals/ambrosian/{roman_id}` 404s and vice versa" is exercised only indirectly, by Task 3's
`testTheRitesDoNotShareIds` and Task 4's catalogue assertions. If the implementer finds `MissalsHandler` returns a 503 rather than a 404 for a cross-rite id, add the explicit
case to Task 4 rather than accepting the 503.

**Type consistency.** `isEditioTypica()` is used with that spelling in Tasks 1, 2, 4 and 5. `regionFor()` is defined in Task 2 (`RomanMissal`) and consumed in Tasks 3, 4 and
5. `MissalCatalog::for()` is defined in Task 2 and consumed in Tasks 3, 4 and 5. `MissalMetadataMap::__construct(Rite)` is defined in Task 3 and consumed in Task 4.
`forMissals(..., Rite)` is defined in Task 5 and called from `Router` in the same task.

**Ordering.** Task 1 lands first so no later task writes the old name. Task 3 depends on Task 2's interface. Task 4 depends on Task 3's rite-aware index, since routing to an
index that still parses folder names yields the 503 described in §5.2.
