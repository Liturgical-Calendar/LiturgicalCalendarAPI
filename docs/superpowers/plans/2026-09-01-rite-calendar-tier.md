# `rite_calendar` Tier Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps
use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Generalise the `general_roman_calendar` OpenFGA object type into a `rite_calendar` tier whose ids name their rite, so `rite_calendar:roman` and
`rite_calendar:ambrosian` are the same kind of thing and a rite added later needs no new object type.

**Architecture:** Additive throughout. `rite_calendar` is added alongside `general_roman_calendar`; both are accepted for the whole migration window. A legacy fallback on the
authorization middleware's *deny* path means the API authorizes correctly whether or not the tuple migration has run, in either deploy order, and a rollback to pre-#955 code
keeps working. Object ids become `<rite>/<subresource>` via the existing `RiteScopedObjectId`. Missal ids are derived from `MissalCatalog` rather than re-listed.

**Tech Stack:** PHP 8.4, PSR-7/15, OpenFGA, Postgres + Doctrine Migrations, PHPUnit 12, PHPStan level 10, phpcs (PSR-12 + `phpcs.xml`).

**Spec:** `docs/superpowers/specs/2026-09-01-rite-calendar-tier-design.md`

**Issue:** #955 (see also the premise corrections posted as a comment there)

## Global Constraints

- **Worktree:** all work happens in `worktrees/api-955-rite-calendar` on branch `feat/955-rite-calendar-tier`. Never `git checkout` or commit in the main checkout — it is shared.
- **PHP >= 8.4.** Short array syntax, single quotes unless interpolating, 4-space indent, line length not enforced.
- **Never use `--no-verify`.** If a pre-commit hook fails, fix the cause and commit again.
- **Nothing goes in the `slow` group.** It is an exclusion mechanism, not a label — anything in it vanishes from `composer test:quick`.
- **Use `#[Group]` attributes, never `@group` docblocks,** if a group is ever needed. PHPUnit 12 honours only the attribute.
- **Never mutate `jsondata/`** in a test. No task here needs to.
- **Additive only.** No task in this plan removes `general_roman_calendar` or `general_roman_calendar_test` from any allow-list. That is the prune milestone, tracked as a
  follow-up.

- **Verification commands:** `composer test:quick`, `composer analyse`, `composer lint`, `composer lint:md`. Run from the worktree root.
- **PHPStan cache is shared across worktrees.** If `composer analyse` reports "… is not a file", run `rm -rf /tmp/phpstan` — `clear-result-cache` is not enough.
- **Object type vocabulary** (exact strings, used verbatim everywhere):
  `national_calendar`, `diocesan_calendar`, `wider_region`, `rite_calendar`,
  `national_calendar_test`, `diocesan_calendar_test`, `rite_calendar_test`,
  plus the two deprecated `general_roman_calendar` and `general_roman_calendar_test`.

---

### Task 1: `RiteCalendarObjectIds` — the per-rite id catalog

**Files:**

- Create: `src/Services/RiteCalendarObjectIds.php`
- Test: `phpunit_tests/Services/RiteCalendarObjectIdsTest.php`

**Interfaces:**

- Consumes: `LiturgicalCalendar\Api\Enum\Rite`, `LiturgicalCalendar\Api\Enum\MissalCatalog`, `LiturgicalCalendar\Api\Services\RiteScopedObjectId`.
- Produces, all `public static`:
  - `forRite(Rite $rite): array` — `list<string>` of **bare** sub-resource ids for that rite, sorted with non-missal ids first in declaration order, then missal ids in
    `MissalCatalog` order.

  - `qualifiedIdsForRite(Rite $rite): array` — `list<string>` of `<rite>/<sub>` ids.
  - `allQualifiedIds(): array` — `list<string>`, every rite concatenated in `Rite::cases()` order.
  - `isValid(string $objectId): bool` — true iff `$objectId` is a rite-qualified id whose sub-resource is in that rite's set.
  - `label(): string` — human-readable list of every valid qualified id, comma-separated, for error messages.

**Critical derivation rule (verified against the corpus, do not simplify):** a missal id belongs to the set iff **`isEditioTypica($id)` AND `getSanctoraleFileName($id) !==
false`**. Deriving on `isEditioTypica()` alone silently widens the grantable set by five ids that have no sanctorale to edit: Roman `EDITIO_TYPICA_1971` and `EDITIO_TYPICA_1975`,
and Ambrosian `EDITIO_TYPICA_1976`. With both conditions the derived sets are exactly `[EDITIO_TYPICA_1970, EDITIO_TYPICA_2002, EDITIO_TYPICA_2008]` for Roman and
`[EDITIO_TYPICA_2024]` for Ambrosian, which reproduces today's `GRC_OBJECT_IDS` exactly.

**Note on the test base class:** `getSanctoraleFileName()` resolves a path through `JsonData::path()`, which reads `Router::$apiFilePath`. That static is **not** set by
`phpunit_tests/bootstrap.php`, so the test must set it itself in `setUpBeforeClass()`, following `phpunit_tests/LectionaryCorpusTest.php:28`.

- [ ] **Step 1: Write the failing test**

Create `phpunit_tests/Services/RiteCalendarObjectIdsTest.php`:

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services;

use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Router;
use LiturgicalCalendar\Api\Services\RiteCalendarObjectIds;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RiteCalendarObjectIds::class)]
final class RiteCalendarObjectIdsTest extends TestCase
{
    /**
     * `getSanctoraleFileName()` resolves through `JsonData::path()`, which reads
     * `Router::$apiFilePath`. The bootstrap does not set it — see
     * phpunit_tests/LectionaryCorpusTest.php:28 for the same pattern.
     */
    public static function setUpBeforeClass(): void
    {
        Router::$apiFilePath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;
    }

    public function testTheRomanSetReproducesTheLegacyGrcIds(): void
    {
        self::assertSame(
            ['temporale', 'decrees', 'supported_locales', 'EDITIO_TYPICA_1970', 'EDITIO_TYPICA_2002', 'EDITIO_TYPICA_2008'],
            RiteCalendarObjectIds::forRite(Rite::ROMAN)
        );
    }

    public function testTheAmbrosianSetIsItsTemporaleAndItsTypicalEdition(): void
    {
        self::assertSame(
            ['temporale', 'EDITIO_TYPICA_2024'],
            RiteCalendarObjectIds::forRite(Rite::AMBROSIAN)
        );
    }

    /**
     * The derivation is `isEditioTypica() AND getSanctoraleFileName() !== false`, not
     * `isEditioTypica()` alone. Roman 1971/1975 and Ambrosian 1976 are typical editions
     * that ship no sanctorale; admitting them would make a permission grantable over a
     * resource with nothing to edit.
     */
    public function testTypicalEditionsWithoutSanctoraleDataAreExcluded(): void
    {
        $roman = RiteCalendarObjectIds::forRite(Rite::ROMAN);

        self::assertNotContains('EDITIO_TYPICA_1971', $roman);
        self::assertNotContains('EDITIO_TYPICA_1975', $roman);
        self::assertNotContains('EDITIO_TYPICA_1976', RiteCalendarObjectIds::forRite(Rite::AMBROSIAN));
    }

    public function testNationalEditionsAreNotRiteLevelResources(): void
    {
        $roman = RiteCalendarObjectIds::forRite(Rite::ROMAN);

        self::assertNotContains('US_2011', $roman);
        self::assertNotContains('IT_1983', $roman);
    }

    public function testDecreesAndSupportedLocalesAreRomanOnly(): void
    {
        $ambrosian = RiteCalendarObjectIds::forRite(Rite::AMBROSIAN);

        self::assertNotContains('decrees', $ambrosian);
        self::assertNotContains('supported_locales', $ambrosian);
    }

    public function testQualifiedIdsCarryTheirRite(): void
    {
        self::assertContains('roman/temporale', RiteCalendarObjectIds::qualifiedIdsForRite(Rite::ROMAN));
        self::assertContains('ambrosian/EDITIO_TYPICA_2024', RiteCalendarObjectIds::qualifiedIdsForRite(Rite::AMBROSIAN));
    }

    public function testValidationAcceptsOnlyQualifiedIdsOfTheOwningRite(): void
    {
        self::assertTrue(RiteCalendarObjectIds::isValid('roman/decrees'));
        self::assertTrue(RiteCalendarObjectIds::isValid('ambrosian/EDITIO_TYPICA_2024'));

        // Right sub-resource, wrong rite.
        self::assertFalse(RiteCalendarObjectIds::isValid('ambrosian/decrees'));
        self::assertFalse(RiteCalendarObjectIds::isValid('roman/EDITIO_TYPICA_2024'));

        // Bare (legacy) ids are not valid for the NEW type.
        self::assertFalse(RiteCalendarObjectIds::isValid('decrees'));
        self::assertFalse(RiteCalendarObjectIds::isValid('temporale'));

        // Unknown rite, unknown sub-resource, empty.
        self::assertFalse(RiteCalendarObjectIds::isValid('byzantine/temporale'));
        self::assertFalse(RiteCalendarObjectIds::isValid('roman/not_a_resource'));
        self::assertFalse(RiteCalendarObjectIds::isValid(''));
    }

    public function testAllQualifiedIdsCoversEveryRite(): void
    {
        $all = RiteCalendarObjectIds::allQualifiedIds();

        self::assertContains('roman/temporale', $all);
        self::assertContains('ambrosian/EDITIO_TYPICA_2024', $all);
        self::assertSame(count(array_unique($all)), count($all), 'ids must not repeat across rites');
    }

    public function testTheLabelNamesEveryValidId(): void
    {
        $label = RiteCalendarObjectIds::label();

        self::assertStringContainsString('roman/temporale', $label);
        self::assertStringContainsString('ambrosian/EDITIO_TYPICA_2024', $label);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit phpunit_tests/Services/RiteCalendarObjectIdsTest.php`
Expected: FAIL — `Class "LiturgicalCalendar\Api\Services\RiteCalendarObjectIds" not found`.

- [ ] **Step 3: Write the implementation**

Create `src/Services/RiteCalendarObjectIds.php`:

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services;

use LiturgicalCalendar\Api\Enum\MissalCatalog;
use LiturgicalCalendar\Api\Enum\Rite;

/**
 * The object ids valid for the `rite_calendar` OpenFGA type.
 *
 * `rite_calendar` is the tier above nations, wider regions and dioceses — the calendar
 * belonging to a rite as a whole. It generalises the older `general_roman_calendar`, which
 * modelled that tier as though only the Roman rite had one (issue #955).
 *
 * Ids are rite-qualified `<rite>/<subresource>` through {@see RiteScopedObjectId}, like every
 * other object type that names a calendar. The predecessor kept BARE ids on the argument that a
 * missal edition id is already unique across rites — true, and still true, but it does not
 * generalise: `temporale`, `decrees` and `supported_locales` are sub-resource *kinds*, one per
 * rite, not unique ids. `temporale` is ambiguous in the corpus **today**, since
 * `jsondata/sourcedata/rite/ambrosian/missals/propriumdetempore` exists; it only fails to bite
 * because the temporale write route is Roman-only.
 *
 * Missal ids are DERIVED from {@see MissalCatalog}, not re-declared, so an edition added to a
 * rite becomes grantable without editing this class — which is what makes "a rite added later
 * needs no new object type" true of the id list too, and not only of the type.
 */
final class RiteCalendarObjectIds
{
    /**
     * Non-missal sub-resources, per rite.
     *
     * `temporale` exists for both rites: each has a `propriumdetempore` in its own partition of
     * the source tree. `ambrosian/temporale` is grantable although no write route consumes it
     * yet — the same forward-looking allowance
     * {@see \LiturgicalCalendar\Api\Repositories\AccessRequestRepository::isValidNationCode()}
     * makes for prospective nations, so that whoever will own the resource can be granted admin
     * before it exists (#669).
     *
     * `decrees` is Roman-only: only `jsondata/sourcedata/rite/roman/decrees` exists.
     * `supported_locales` is Roman-only because `jsondata/supportedLocales.json` is itself keyed
     * `general_roman_calendar` at its top level. That the API-wide locale set is filed under one
     * rite is a known wart, recorded as a follow-up in the design rather than fixed here.
     *
     * @var array<string, list<string>>
     */
    private const FIXED_IDS = [
        'roman'     => ['temporale', 'decrees', 'supported_locales'],
        'ambrosian' => ['temporale'],
    ];

    /**
     * The bare sub-resource ids valid for one rite.
     *
     * @return list<string>
     */
    public static function forRite(Rite $rite): array
    {
        return array_merge(self::FIXED_IDS[$rite->value] ?? [], self::missalIdsForRite($rite));
    }

    /**
     * The typical editions of a rite that actually carry sanctorale data.
     *
     * BOTH conditions are load-bearing. `isEditioTypica()` alone admits Roman
     * `EDITIO_TYPICA_1971` and `EDITIO_TYPICA_1975` and Ambrosian `EDITIO_TYPICA_1976`, which
     * are typical editions that ship no sanctorale file — a grant over them would authorize
     * editing a resource with nothing in it. `getSanctoraleFileName() !== false` is the same
     * test the original design used to exclude 1971/1975 by hand; deriving it keeps the set
     * correct when an edition gains or loses sanctorale data.
     *
     * @return list<string>
     */
    private static function missalIdsForRite(Rite $rite): array
    {
        $source = MissalCatalog::for($rite);
        $ids    = [];

        foreach ($source->getMissalIds() as $missalId) {
            if ($source->isEditioTypica($missalId) && false !== $source->getSanctoraleFileName($missalId)) {
                $ids[] = $missalId;
            }
        }

        return $ids;
    }

    /**
     * The rite-qualified ids valid for one rite.
     *
     * @return list<string>
     */
    public static function qualifiedIdsForRite(Rite $rite): array
    {
        return array_map(
            static fn (string $id): string => RiteScopedObjectId::qualify($rite, $id),
            self::forRite($rite)
        );
    }

    /**
     * Every rite-qualified id, across every rite.
     *
     * @return list<string>
     */
    public static function allQualifiedIds(): array
    {
        $ids = [];

        foreach (Rite::cases() as $rite) {
            $ids = array_merge($ids, self::qualifiedIdsForRite($rite));
        }

        return $ids;
    }

    /**
     * Whether an object id is valid for the `rite_calendar` type.
     *
     * A bare id — `decrees`, `temporale` — is deliberately INVALID here. Those are legacy
     * `general_roman_calendar` ids; they keep authorizing through that legacy type for the
     * migration window, and are migrated by `scripts/migrate-rite-calendar-tuples.php`. Letting
     * them validate against the new type as well would create a second spelling of the same
     * grant with no migration path off it.
     */
    public static function isValid(string $objectId): bool
    {
        $parsed = RiteScopedObjectId::parse($objectId);

        if (null === $parsed) {
            return false;
        }

        [$rite, $subResource] = $parsed;

        return in_array($subResource, self::forRite($rite), true);
    }

    /**
     * Every valid qualified id, for an error message that tells the caller what to send.
     */
    public static function label(): string
    {
        return implode(', ', self::allQualifiedIds());
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit phpunit_tests/Services/RiteCalendarObjectIdsTest.php`
Expected: PASS, 9 tests.

- [ ] **Step 5: Static analysis and style**

Run: `composer analyse && composer lint`
Expected: both clean. If PHPStan reports "… is not a file", run `rm -rf /tmp/phpstan` first.

- [ ] **Step 6: Commit**

```bash
git add src/Services/RiteCalendarObjectIds.php phpunit_tests/Services/RiteCalendarObjectIdsTest.php
git commit -m "feat(authz): add RiteCalendarObjectIds, the per-rite id catalog for rite_calendar (#955)"
```

---

### Task 2: Accept `rite_calendar` in `AccessRequestRepository`

**Files:**

- Modify: `src/Repositories/AccessRequestRepository.php` (`GRC_OBJECT_IDS` ~line 66, `VALID_OBJECT_TYPES` ~line 73, `ROLE_OBJECT_TYPES` ~line 89, `validIdsLabelForType()` ~line
  128, `isValidObjectIdForType()` ~line 143)

- Test: `phpunit_tests/Repositories/AccessRequestRepositoryConstantsTest.php`

**Interfaces:**

- Consumes: `RiteCalendarObjectIds::isValid()`, `RiteCalendarObjectIds::label()` from Task 1.
- Produces: `'rite_calendar'` present in `AccessRequestRepository::VALID_OBJECT_TYPES` and in `ROLE_OBJECT_TYPES['developer']` and `ROLE_OBJECT_TYPES['calendar_editor']`;
  `isValidObjectIdForType('rite_calendar', $id)` delegating to `RiteCalendarObjectIds::isValid()`.

`GRC_OBJECT_IDS` is **kept**, unchanged, and marked deprecated. It is still the validation source for the legacy `general_roman_calendar` type, which must keep validating for the
whole window.

- [ ] **Step 1: Write the failing test**

Append to `phpunit_tests/Repositories/AccessRequestRepositoryConstantsTest.php`, inside the class:

```php
    public function testRiteCalendarIsAValidObjectType(): void
    {
        self::assertContains('rite_calendar', AccessRequestRepository::VALID_OBJECT_TYPES);
    }

    public function testRiteCalendarIsHeldByTheCalendarEditingRoles(): void
    {
        self::assertContains('rite_calendar', AccessRequestRepository::ROLE_OBJECT_TYPES['calendar_editor']);
        self::assertContains('rite_calendar', AccessRequestRepository::ROLE_OBJECT_TYPES['developer']);
        self::assertNotContains('rite_calendar', AccessRequestRepository::ROLE_OBJECT_TYPES['test_editor']);
    }

    public function testRiteCalendarIdsMustBeRiteQualified(): void
    {
        self::assertTrue(AccessRequestRepository::isValidObjectIdForType('rite_calendar', 'roman/decrees'));
        self::assertTrue(AccessRequestRepository::isValidObjectIdForType('rite_calendar', 'ambrosian/EDITIO_TYPICA_2024'));
        self::assertFalse(AccessRequestRepository::isValidObjectIdForType('rite_calendar', 'decrees'));
        self::assertFalse(AccessRequestRepository::isValidObjectIdForType('rite_calendar', 'ambrosian/decrees'));
    }

    /**
     * The legacy type keeps validating for the whole migration window. Dropping it here
     * would refuse to re-grant a permission that is still live in the store.
     */
    public function testTheLegacyGeneralRomanCalendarTypeStillValidates(): void
    {
        self::assertContains('general_roman_calendar', AccessRequestRepository::VALID_OBJECT_TYPES);
        self::assertTrue(AccessRequestRepository::isValidObjectIdForType('general_roman_calendar', 'decrees'));
        self::assertTrue(AccessRequestRepository::isValidObjectIdForType('general_roman_calendar_test', 'general_roman_calendar'));
    }

    public function testTheRiteCalendarErrorLabelNamesQualifiedIds(): void
    {
        $label = AccessRequestRepository::validIdsLabelForType('rite_calendar');

        self::assertStringContainsString('roman/decrees', $label);
        self::assertStringContainsString('ambrosian/EDITIO_TYPICA_2024', $label);
    }
```

If the file has no `Router::$apiFilePath` setup, add this to the class (the label and validation both reach `MissalCatalog`):

```php
    public static function setUpBeforeClass(): void
    {
        Router::$apiFilePath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;
    }
```

and `use LiturgicalCalendar\Api\Router;` at the top.

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit phpunit_tests/Repositories/AccessRequestRepositoryConstantsTest.php`
Expected: FAIL — `rite_calendar` not in `VALID_OBJECT_TYPES`.

- [ ] **Step 3: Add `rite_calendar` to the two type lists**

In `src/Repositories/AccessRequestRepository.php`, add `'rite_calendar',` to `VALID_OBJECT_TYPES` (immediately before `'general_roman_calendar',`), and to both
`ROLE_OBJECT_TYPES['developer']` and `ROLE_OBJECT_TYPES['calendar_editor']`. Leave `test_editor` untouched.

Mark the old constant deprecated by replacing the `@var` line of `GRC_OBJECT_IDS` with:

```php
     * @deprecated Superseded by {@see \LiturgicalCalendar\Api\Services\RiteCalendarObjectIds}, whose
     *             ids are rite-qualified (#955). Retained unchanged because the legacy
     *             `general_roman_calendar` type must keep validating until the prune milestone.
     *
     * @var array<int, string>
```

- [ ] **Step 4: Add the validation and label arms**

In `validIdsLabelForType()`, add as the first arm of the `match`:

```php
            'rite_calendar'               => RiteCalendarObjectIds::label(),
```

In `isValidObjectIdForType()`, add as the first statement of the method body:

```php
        if ($objectType === 'rite_calendar') {
            return RiteCalendarObjectIds::isValid($objectId);
        }
```

Add `use LiturgicalCalendar\Api\Services\RiteCalendarObjectIds;` to the imports.

Update the method's docblock prose, which currently begins "general_roman_calendar uses a fixed enumerated id set", to:

```php
    /**
     * Validate an object_id for a given object_type.
     *
     * `rite_calendar` requires a rite-qualified `<rite>/<subresource>` id from
     * {@see RiteCalendarObjectIds}. Its predecessor `general_roman_calendar` uses a fixed
     * enumerated BARE id set and is retained until the #955 prune milestone;
     * `general_roman_calendar_test` accepts only the literal id 'general_roman_calendar' and is
     * retained on the same schedule; `rite_calendar_test` accepts only a known Rite value; the
     * scoped test types require a rite-qualified `<rite>/<calendarId>` id, because a bare
     * calendar id does not identify a calendar (see TestScopeResolver); the calendar-naming data
     * types are rite-qualified for the same reason (#786); all other types accept any non-empty
     * id (the resource itself is validated downstream by the handler).
     */
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `vendor/bin/phpunit phpunit_tests/Repositories/AccessRequestRepositoryConstantsTest.php`
Expected: PASS.

- [ ] **Step 6: Run the full quick suite, analysis and style**

Run: `composer test:quick && composer analyse && composer lint`
Expected: all green. `ResourceExistenceCheckerTest` and `ResourceAdminServiceTest` may fail here if they assert an exact type list — that is expected and is fixed in Task 5; note
any such failure and move on only if it is one of those two files.

- [ ] **Step 7: Commit**

```bash
git add src/Repositories/AccessRequestRepository.php phpunit_tests/Repositories/AccessRequestRepositoryConstantsTest.php
git commit -m "feat(authz): accept rite_calendar object type and rite-qualified ids (#955)"
```

---

### Task 3: `ChangeResource` emits `rite_calendar`

**Files:**

- Modify: `src/Services/ChangeResource.php` (`decrees()` ~line 74, `missal()` ~line 108, `supportedLocales()` ~line 133, class docblock ~line 10)
- Modify: `src/Handlers/Admin/LocalesAdminHandler.php:304`, `src/Handlers/DecreesHandler.php:451,468` (only if a `Rite` must be threaded — see Step 3)
- Test: `phpunit_tests/Services/ChangeResourceTest.php`

**Interfaces:**

- Consumes: `RiteScopedObjectId::qualify()`, `MissalCatalog::for()`.
- Produces:
  - `ChangeResource::decrees(Rite $rite = Rite::ROMAN): self` → type `rite_calendar`, id `<rite>/decrees`
  - `ChangeResource::supportedLocales(Rite $rite = Rite::ROMAN): self` → type `rite_calendar`, id `<rite>/supported_locales`
  - `ChangeResource::missal(string $missalId, Rite $rite = Rite::ROMAN): self` → type `rite_calendar`,
    id `<rite>/<missalId>` for a typical edition; unchanged `national_calendar` + `<rite>/<nation>` otherwise.

Both new `Rite` parameters default to `Rite::ROMAN`, so **no call site has to change**. They exist so a future Ambrosian decrees corpus needs no signature change.

- [ ] **Step 1: Write the failing test**

In `phpunit_tests/Services/ChangeResourceTest.php`, replace the body of `testDecreesIsTheGeneralRomanCalendarDecreesObject()` and add the rest. Rename that method to
`testDecreesIsTheRomanRiteCalendarDecreesObject`:

```php
    public function testDecreesIsTheRomanRiteCalendarDecreesObject(): void
    {
        $resource = ChangeResource::decrees();

        self::assertSame('rite_calendar', $resource->type);
        self::assertSame('roman/decrees', $resource->id);
    }

    public function testSupportedLocalesIsTheRomanRiteCalendarLocalesObject(): void
    {
        $resource = ChangeResource::supportedLocales();

        self::assertSame('rite_calendar', $resource->type);
        self::assertSame('roman/supported_locales', $resource->id);
    }

    public function testATypicalEditionIsARiteQualifiedRiteCalendarObject(): void
    {
        $roman = ChangeResource::missal(RomanMissal::EDITIO_TYPICA_2002, Rite::ROMAN);

        self::assertSame('rite_calendar', $roman->type);
        self::assertSame('roman/EDITIO_TYPICA_2002', $roman->id);

        $ambrosian = ChangeResource::missal(AmbrosianMissal::EDITIO_TYPICA_2024, Rite::AMBROSIAN);

        self::assertSame('rite_calendar', $ambrosian->type);
        self::assertSame('ambrosian/EDITIO_TYPICA_2024', $ambrosian->id);
    }

    /**
     * A national edition is still governed by the national calendar whose conference
     * publishes it — unchanged by #955.
     */
    public function testANationalEditionStillBelongsToItsNationalCalendar(): void
    {
        $resource = ChangeResource::missal('IT_1983', Rite::ROMAN);

        self::assertSame('national_calendar', $resource->type);
        self::assertSame('roman/IT', $resource->id);
    }

    /**
     * Every id this class produces must be grantable, or a change request is filed
     * against an object no one can ever hold a permission on.
     */
    public function testEveryEmittedResourceIdIsValidForItsType(): void
    {
        $resources = [
            ChangeResource::decrees(),
            ChangeResource::supportedLocales(),
            ChangeResource::missal(RomanMissal::EDITIO_TYPICA_2002, Rite::ROMAN),
            ChangeResource::missal(AmbrosianMissal::EDITIO_TYPICA_2024, Rite::AMBROSIAN),
            ChangeResource::missal('IT_1983', Rite::ROMAN),
        ];

        foreach ($resources as $resource) {
            self::assertTrue(
                AccessRequestRepository::isValidObjectIdForType($resource->type, $resource->id),
                "{$resource->type}:{$resource->id} must be a grantable object"
            );
        }
    }
```

Add a `setUpBeforeClass()` setting `Router::$apiFilePath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;` and `use LiturgicalCalendar\Api\Router;` if not already present.

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit phpunit_tests/Services/ChangeResourceTest.php`
Expected: FAIL — `'general_roman_calendar'` returned where `'rite_calendar'` expected.

- [ ] **Step 3: Update the three factories**

In `src/Services/ChangeResource.php`:

```php
    /**
     * The decrees corpus of a rite — a fixed sub-resource on the `rite_calendar` type.
     *
     * Only the Roman rite has a decrees corpus on disk today
     * (`jsondata/sourcedata/rite/roman/decrees`), which is why the parameter defaults to it and
     * why `RiteCalendarObjectIds` lists `decrees` for that rite alone. The parameter exists so
     * that a rite which later grows one needs no signature change here.
     */
    public static function decrees(Rite $rite = Rite::ROMAN): self
    {
        return new self('rite_calendar', RiteScopedObjectId::qualify($rite, 'decrees'));
    }
```

```php
    /**
     * The curated set of officially supported locales — a fixed sub-resource on the
     * `rite_calendar` type, exactly like {@see decrees()}.
     *
     * `jsondata/supportedLocales.json` is keyed by `general_roman_calendar` at its top level, so
     * filing it under `roman/` is the honest reading of today's data even though the locale set
     * is API-wide. That mismatch is a known wart, recorded as a follow-up in the #955 design.
     *
     * The accepted consequence is unchanged from #926: whoever administers the Roman rite-level
     * calendar curates its supported locales.
     */
    public static function supportedLocales(Rite $rite = Rite::ROMAN): self
    {
        return new self('rite_calendar', RiteScopedObjectId::qualify($rite, 'supported_locales'));
    }
```

In `missal()`, replace the typical-edition branch:

```php
        if ($source->isEditioTypica($missalId)) {
            return new self('rite_calendar', RiteScopedObjectId::qualify($rite, $missalId));
        }
```

and replace its docblock paragraph that begins "An editio typica is a fixed id on `general_roman_calendar`, bare like `temporale`…" with:

```php
     * A typical edition is a rite-qualified sub-resource on `rite_calendar`, alongside
     * `<rite>/temporale` and `<rite>/decrees` — see {@see RiteCalendarObjectIds}. The ids were
     * bare under the predecessor type on the argument that a missal edition id is already unique
     * across rites; that is still true of missal ids specifically, but does not generalise to the
     * tier's other sub-resources, so #955 qualifies all of them under one rule. A national
     * edition belongs to the national calendar whose conference publishes it, qualified with the
     * same rite the caller passed in.
```

Update the class docblock: replace the sentence "general_roman_calendar keeps bare ids because its ids (temporale, decrees, missal editions) are not calendars and are Roman by
construction." with:

```php
 * `rite_calendar` ids are rite-qualified too, as of #955: its sub-resources are per-rite kinds
 * (`roman/temporale`, `ambrosian/temporale`), not globally unique ids.
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit phpunit_tests/Services/ChangeResourceTest.php`
Expected: PASS.

- [ ] **Step 5: Check the call sites still compile and behave**

Run: `composer test:quick && composer analyse && composer lint`
Expected: green apart from the Task 5 files noted earlier. `LocalesAdminHandler` and `DecreesHandler` need no edit — the new parameters default.

- [ ] **Step 6: Commit**

```bash
git add src/Services/ChangeResource.php phpunit_tests/Services/ChangeResourceTest.php
git commit -m "feat(authz): file source-data change requests against rite_calendar (#955)"
```

---

### Task 4: Middleware — `forRiteCalendar`, `forMissals`, and the legacy fallback

**Files:**

- Modify: `src/Http/Middleware/OpenFgaAuthorizationMiddleware.php` (constructor ~line 106, `process()` ~line 200, `forGeneralRomanCalendar()` ~line 368, `forMissals()` ~line 380,
  class docblock ~line 33)

- Modify: `src/Router.php:1007`, `src/Router.php:1012-1016`, `src/Router.php:1019-1020` (comment)
- Test: `phpunit_tests/Http/OpenFgaAuthorizationMiddlewareTest.php`, `phpunit_tests/Http/Middleware/MissalsFgaObjectIdTest.php`

**Interfaces:**

- Consumes: `RiteScopedObjectId::qualify()`, `Rite`.
- Produces:
  - `OpenFgaAuthorizationMiddleware::forRiteCalendar(OpenFgaClient $client, Rite $rite, string $subResource, ?array $relationMap = null): self`
  - `forMissals(OpenFgaClient $client, string $missalId, Rite $rite = Rite::ROMAN): self` — signature unchanged, object changed.
  - A new private constructor parameter `?array $legacyObject` — `array{0:string,1:string}|null`, checked only when the primary check denies.

`forGeneralRomanCalendar()` is **removed** and replaced by `forRiteCalendar()`; it has exactly two call sites, both in `Router.php`. This is an internal factory, not a published
surface.

- [ ] **Step 1: Write the failing test**

Add to `phpunit_tests/Http/OpenFgaAuthorizationMiddlewareTest.php`. Follow the existing file's mock/stub style for `OpenFgaClient`; if it uses a `MockHandler`-backed client,
mirror that.

```php
    public function testRiteCalendarChecksARiteQualifiedObject(): void
    {
        $client = $this->clientAllowingOnly('user:alice', 'editor', 'rite_calendar:roman/decrees');

        $middleware = OpenFgaAuthorizationMiddleware::forRiteCalendar(
            $client,
            Rite::ROMAN,
            'decrees',
            ['PUT' => 'editor', 'PATCH' => 'editor', 'DELETE' => 'admin']
        );

        self::assertTrue($this->runsThrough($middleware, 'PATCH', 'alice'));
    }

    /**
     * The load-bearing test of the whole change. A tuple written before #955 is on the legacy
     * type; the API must keep authorizing it for the entire migration window, in either deploy
     * order relative to scripts/migrate-rite-calendar-tuples.php. Without this, "additive" is an
     * intention rather than a property of the system.
     */
    public function testALegacyGeneralRomanCalendarTupleStillAuthorizes(): void
    {
        $client = $this->clientAllowingOnly('user:alice', 'editor', 'general_roman_calendar:decrees');

        $middleware = OpenFgaAuthorizationMiddleware::forRiteCalendar(
            $client,
            Rite::ROMAN,
            'decrees',
            ['PUT' => 'editor', 'PATCH' => 'editor', 'DELETE' => 'admin']
        );

        self::assertTrue($this->runsThrough($middleware, 'PATCH', 'alice'));
    }

    public function testNeitherTupleStillDenies(): void
    {
        $client = $this->clientAllowingNothing();

        $middleware = OpenFgaAuthorizationMiddleware::forRiteCalendar($client, Rite::ROMAN, 'decrees');

        $this->expectException(ForbiddenException::class);
        $this->runsThrough($middleware, 'PATCH', 'alice');
    }

    /**
     * A rite that has no such sub-resource produces an object that appears in no valid id set,
     * so it can hold no tuple and the request is refused. Fail-closed by construction rather
     * than by an explicit branch.
     */
    public function testARiteWithoutTheSubResourceIsRefused(): void
    {
        $client = $this->clientAllowingOnly('user:alice', 'editor', 'rite_calendar:roman/decrees');

        $middleware = OpenFgaAuthorizationMiddleware::forRiteCalendar($client, Rite::AMBROSIAN, 'decrees');

        $this->expectException(ForbiddenException::class);
        $this->runsThrough($middleware, 'PATCH', 'alice');
    }
```

In `phpunit_tests/Http/Middleware/MissalsFgaObjectIdTest.php`, update the expected object for typical editions from `general_roman_calendar:EDITIO_TYPICA_2002` to
`rite_calendar:roman/EDITIO_TYPICA_2002`, and `general_roman_calendar:EDITIO_TYPICA_2024` to `rite_calendar:ambrosian/EDITIO_TYPICA_2024`. Leave the national-edition expectations
(`national_calendar:roman/IT`) unchanged.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit phpunit_tests/Http/OpenFgaAuthorizationMiddlewareTest.php phpunit_tests/Http/Middleware/MissalsFgaObjectIdTest.php`
Expected: FAIL — `forRiteCalendar()` undefined.

- [ ] **Step 3: Add the legacy-object constructor parameter and fallback**

In `OpenFgaAuthorizationMiddleware`, add the property and constructor parameter:

```php
    /**
     * The pre-#955 object this check falls back to when the primary check denies.
     *
     * `[objectType, objectId]`, or null when there is no predecessor. Checked ONLY on the deny
     * path, so the allow path still costs exactly one OpenFGA call and the common case is
     * unaffected.
     *
     * This is what makes the #955 migration additive in fact and not merely in intent: the API
     * authorizes correctly whether or not `scripts/migrate-rite-calendar-tuples.php` has run, in
     * either deploy order, and a rollback to pre-#955 code keeps authorizing because the legacy
     * tuples were never deleted. Removed at the prune milestone.
     *
     * @var array{0: string, 1: string}|null
     */
    private ?array $legacyObject;
```

Add `?array $legacyObject = null` as the last constructor parameter with `@phpstan-param array{0: string, 1: string}|null $legacyObject`, and assign `$this->legacyObject =
$legacyObject;`.

In `process()`, replace:

```php
        $allowed = $this->client->check($fgaUser, $relation, $fgaObject);

        if (!$allowed) {
```

with:

```php
        $allowed = $this->client->check($fgaUser, $relation, $fgaObject);

        // Fall back to the pre-#955 object only when the primary check denied, so an allowed
        // request still costs one call. See $legacyObject.
        if (!$allowed && $this->legacyObject !== null) {
            [$legacyType, $legacyId] = $this->legacyObject;
            $allowed                 = $this->client->check($fgaUser, $relation, "{$legacyType}:{$legacyId}");
        }

        if (!$allowed) {
```

- [ ] **Step 4: Replace the two factories**

Replace `forGeneralRomanCalendar()` entirely with:

```php
    /**
     * Create middleware for a rite-level calendar sub-resource (e.g. "temporale", "decrees").
     *
     * The object is `rite_calendar:{rite}/{subResource}`, with the pre-#955
     * `general_roman_calendar:{subResource}` as the legacy fallback. A rite that does not have
     * the sub-resource simply produces an object no tuple can name, so the request is refused
     * without a special case.
     *
     * @param OpenFgaClient             $client      The OpenFGA client
     * @param Rite                      $rite        The rite whose calendar is being edited
     * @param string                    $subResource Fixed sub-resource id (e.g. "temporale")
     * @param array<string,string>|null $relationMap Optional method→relation override
     *                                               (default: PUT/DELETE→admin, PATCH→editor)
     */
    public static function forRiteCalendar(OpenFgaClient $client, Rite $rite, string $subResource, ?array $relationMap = null): self
    {
        return new self(
            $client,
            'rite_calendar',
            'calendar_id',
            RiteScopedObjectId::qualify($rite, $subResource),
            null,
            $relationMap,
            ['general_roman_calendar', $subResource]
        );
    }
```

Replace the typical-edition branch of `forMissals()`:

```php
        // A typical edition is a rite-qualified sub-resource on rite_calendar, alongside
        // `{rite}/temporale` and `{rite}/decrees` (RiteCalendarObjectIds). Missal ids are unique
        // across rites (MissalCatalogTest::testTheRitesDoNotShareIds), so the qualifier adds no
        // disambiguation for THIS id specifically — it is carried for one uniform rule across the
        // whole tier, whose other sub-resources are per-rite kinds and genuinely do need it (#955).
        // The pre-#955 bare object is the legacy fallback.
        if ($source->isEditioTypica($missalId)) {
            return new self(
                $client,
                'rite_calendar',
                'calendar_id',
                RiteScopedObjectId::qualify($rite, $missalId),
                null,
                null,
                ['general_roman_calendar', $missalId]
            );
        }
```

Update the class docblock's object-type map:

```php
 *   /temporale, /decrees    → rite_calendar:{rite}/{fixedId}
 *   /missals/{editio_typica}→ rite_calendar:{rite}/{missalId}
```

- [ ] **Step 5: Rewire the Router**

`src/Router.php:1007`:

```php
                $pipeline->pipe(OpenFgaAuthorizationMiddleware::forRiteCalendar($fgaClient, $rite, 'temporale'));
```

`src/Router.php:1012-1016`:

```php
                $pipeline->pipe(OpenFgaAuthorizationMiddleware::forRiteCalendar(
                    $fgaClient,
                    $rite,
                    'decrees',
                    ['PUT' => 'editor', 'PATCH' => 'editor', 'DELETE' => 'admin']
                ));
```

`src/Router.php:1019-1020`, update the comment:

```php
            // Writes are authorized per-missal: calendar_editor role plus fine-grained FGA
            // (typical edition -> rite_calendar:{rite}/{missalId}, national missal -> national_calendar).
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `vendor/bin/phpunit phpunit_tests/Http/ phpunit_tests/Services/`
Expected: PASS.

- [ ] **Step 7: Full quick suite, analysis, style**

Run: `composer test:quick && composer analyse && composer lint`
Expected: green apart from the Task 5 files.

- [ ] **Step 8: Commit**

```bash
git add src/Http/Middleware/OpenFgaAuthorizationMiddleware.php src/Router.php phpunit_tests/Http/
git commit -m "feat(authz): authorize rite-level writes against rite_calendar, with legacy fallback (#955)"
```

---

### Task 5: Dashboard, existence checks and the authz contract file

**Files:**

- Modify: `src/Services/ResourceAdminService.php` (`ADMIN_OBJECT_TYPES` ~line 33, `VIEWER_OBJECT_TYPES` ~line 56, budget docblock ~line 64)
- Modify: `src/Services/ResourceExistenceChecker.php` (docblock ~line 17, `RESOURCE_TYPES` ~line 45, `exists()` ~line 62)
- Modify: `authz/openfga-expectations.json`
- Test: `phpunit_tests/Services/ResourceAdminServiceTest.php`, `phpunit_tests/Services/ResourceExistenceCheckerTest.php`

**Interfaces:**

- Consumes: nothing new.
- Produces: `'rite_calendar'` in `ResourceAdminService::ADMIN_OBJECT_TYPES` and `::VIEWER_OBJECT_TYPES`, and in `ResourceExistenceChecker::RESOURCE_TYPES` with `exists()`
  returning `true` for it.

- [ ] **Step 1: Write the failing test**

Add to `phpunit_tests/Services/ResourceExistenceCheckerTest.php`:

```php
    public function testRiteCalendarIsAKnownResourceType(): void
    {
        $checker = new ResourceExistenceChecker();

        self::assertTrue($checker->isResourceType('rite_calendar'));
    }

    /**
     * `exists()` decides what the reconciler PURGES, so a false negative destroys a live grant
     * while a false positive merely leaves a stale tuple for the next sweep. It therefore
     * answers `true` for the whole fixed catalog and deliberately does NOT validate the
     * `<rite>/<subresource>` shape — legacy unqualified ids are still in the store for the
     * entire migration window.
     */
    public function testRiteCalendarObjectsAreNeverReportedMissing(): void
    {
        $checker = new ResourceExistenceChecker();

        self::assertTrue($checker->exists('rite_calendar', 'roman/decrees'));
        self::assertTrue($checker->exists('rite_calendar', 'ambrosian/EDITIO_TYPICA_2024'));
        self::assertTrue($checker->exists('rite_calendar', 'decrees'));
    }
```

Add to `phpunit_tests/Services/ResourceAdminServiceTest.php`:

```php
    public function testRiteCalendarIsAnAdminAndViewerObjectType(): void
    {
        self::assertContains('rite_calendar', ResourceAdminService::ADMIN_OBJECT_TYPES);
        self::assertContains('rite_calendar', ResourceAdminService::VIEWER_OBJECT_TYPES);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit phpunit_tests/Services/ResourceExistenceCheckerTest.php phpunit_tests/Services/ResourceAdminServiceTest.php`
Expected: FAIL.

- [ ] **Step 3: Wire the two services**

`ResourceAdminService`: add `'rite_calendar',` to `ADMIN_OBJECT_TYPES` and to `VIEWER_OBJECT_TYPES`, each immediately before `'general_roman_calendar',`.

Update the budget docblock's arithmetic — it currently says "nine of those in a row is 45 seconds" and "~12 ms for nine calls". Replace both counts:

```php
     * Each lookup carries its own 5s read timeout (see `OpenFgaClient::fromEnv()`),
     * which bounds a single stuck call but not a sequence of them: eleven of those in
     * a row is 55 seconds of a php-fpm worker held on a response the caller stopped
```

and

```php
     * 3 seconds is roughly 200x the measured cost of the largest fan-out
     * (~12 ms for nine calls, issue #711; eleven since #955 added rite_calendar to the
     * admin and viewer sets), so it can only ever be reached when
```

`ResourceExistenceChecker`: add `'rite_calendar',` to `RESOURCE_TYPES` immediately before `'general_roman_calendar',`, add `case 'rite_calendar':` immediately above `case
'general_roman_calendar':` in `exists()`, and add a line to the class docblock's table:

```php
 *   rite_calendar                — fixed; always exists
```

- [ ] **Step 4: Update the authz contract file**

In `authz/openfga-expectations.json`, add `"rite_calendar"` to `required_types` (after `"diocesan_calendar"`), and add to `relation_includes`:

```json
    "rite_calendar": {
      "editor": ["admin"],
      "viewer": ["admin"]
    },
```

Do **not** move the legacy types to `forbidden_types` — that is the prune milestone.

- [ ] **Step 5: Run the tests to verify they pass**

Run: `vendor/bin/phpunit phpunit_tests/Services/`
Expected: PASS.

- [ ] **Step 6: Full quick suite, analysis, style**

Run: `composer test:quick && composer analyse && composer lint`
Expected: fully green now — no known remaining failures.

- [ ] **Step 7: Commit**

```bash
git add src/Services/ResourceAdminService.php src/Services/ResourceExistenceChecker.php authz/openfga-expectations.json phpunit_tests/Services/
git commit -m "feat(authz): surface rite_calendar in the admin dashboard and reconciler (#955)"
```

---

### Task 6: Tuple migration script

**Files:**

- Create: `scripts/migrate-rite-calendar-tuples.php`
- Reference (copy the structure, do not import): `scripts/migrate-rite-data-tuples.php`, `scripts/migrate-rite-test-tuples.php`

**Interfaces:**

- Consumes: `RiteCalendarObjectIds`, `MissalCatalog`, `Rite`, `RiteScopedObjectId`, `OpenFgaClient`, and whatever tuple-read/write helpers the two predecessor scripts use —
  **read `migrate-rite-data-tuples.php` first and reuse its exact idioms** for reading tuples, writing, pruning, CLI-only guarding, and `.env` loading.

- Produces: an operator-run script. No PHP code depends on it.

**Mappings:**

```text
general_roman_calendar:<sub>                       → rite_calendar:<rite>/<sub>
general_roman_calendar_test:general_roman_calendar → rite_calendar_test:roman   (#767 leftover)
```

**Rite inference — never a guess.** For a missal id, the rite is whichever `MissalCatalog` source declares it (`isValid($id)` true for exactly one rite — asserted by
`MissalCatalogTest::testTheRitesDoNotShareIds`). For `temporale`, `decrees` and `supported_locales`, the rite is `roman`: those were the only sub-resources the legacy type ever
had, and it modelled the Roman tier. **Any id that matches neither rule is reported and skipped**, exactly as both predecessors do — the script never guesses which grant was
meant.

- [ ] **Step 1: Read the predecessor**

Run: `sed -n '1,200p' scripts/migrate-rite-data-tuples.php`
Note the CLI-only guard, the `.env` loading, the `--dry-run`/`--apply`/`--prune` parsing, the copy-then-prune ordering, and the `TupleAlreadyExistsException` /
`TupleNotFoundException` handling. Reuse all of it verbatim in structure.

- [ ] **Step 2: Write the script**

Create `scripts/migrate-rite-calendar-tuples.php` with this header docblock, then the body modelled on the predecessor:

```php
#!/usr/bin/env php
<?php

/**
 * Idempotent migration: bring every rite-level OpenFGA tuple onto the generalised
 * `rite_calendar` type, and finish #767's leftover test-type rename (issue #955).
 *
 *   general_roman_calendar:<sub>                       → rite_calendar:<rite>/<sub>
 *   general_roman_calendar_test:general_roman_calendar → rite_calendar_test:roman
 *
 * `general_roman_calendar` modelled the rite-level tier as though only the Roman rite had one.
 * Every rite has one, so the type becomes `rite_calendar` and its ids carry their rite, like
 * every other object type that names a calendar. Existing grants have to follow, or every
 * current editor of the temporale, the decrees corpus, the supported-locale set and the typical
 * editions silently loses access.
 *
 * The second mapping is not new work: `rite_calendar_test` has been
 * `general_roman_calendar_test`'s successor since #767, and `TestScopeResolver` stopped emitting
 * the old type then. It is folded in here so the legacy data type and the legacy test type reach
 * their end state in ONE operator window rather than two.
 *
 * Rite inference is never a guess. A missal id's rite is whichever `MissalCatalog` source
 * declares it — exactly one does, asserted by `MissalCatalogTest::testTheRitesDoNotShareIds`.
 * `temporale`, `decrees` and `supported_locales` are Roman: they are the only sub-resources the
 * legacy type ever carried, and it denoted the Roman tier. An id matching neither rule is
 * reported and skipped.
 *
 * The third in a family with `migrate-rite-test-tuples.php` (#767) and
 * `migrate-rite-data-tuples.php` (#786), and deliberately identical in shape to both.
 *
 * Usage:
 *   php scripts/migrate-rite-calendar-tuples.php [--dry-run|--apply] [--prune]
 *
 * Flags:
 *   --dry-run  (default) Print what WOULD be done without touching the store.
 *   --apply             Write the new tuples in OpenFGA.
 *   --prune             Additionally DELETE the superseded tuples. Off by default: the legacy
 *                       types stay valid in every allow-list, and the authorization middleware
 *                       falls back to them, so a rollback to pre-#955 code keeps authorizing.
 *                       Only prune once every deployment runs merged code — and prefer to do it
 *                       in the same operator window as the deferred RBAC `deleter` drop, which
 *                       waits on the identical condition.
 *
 * Safety guarantees:
 *   - Copy-then-prune ordering: a tuple is never deleted before its replacement is confirmed.
 *   - Writing a tuple that already exists is benign (TupleAlreadyExistsException).
 *   - Deleting a tuple that no longer exists is benign (TupleNotFoundException).
 *   - Already-migrated tuples are recognised and left alone.
 *   - Safe to re-run after a partial migration.
 *
 * Required environment variables (loaded from .env* files if present):
 *   OPENFGA_API_URL, OPENFGA_STORE_ID, OPENFGA_MODEL_ID
 *
 * Optional:
 *   OPENFGA_API_TOKEN
 */
```

The rite-inference helper, which is the one piece not copied from the predecessor:

```php
/**
 * The rite a legacy `general_roman_calendar` sub-resource id belongs to.
 *
 * Returns null when the id matches no rule, so the caller can report and skip rather than guess.
 */
function riteForLegacySubResource(string $subResource): ?Rite
{
    if (in_array($subResource, ['temporale', 'decrees', 'supported_locales'], true)) {
        return Rite::ROMAN;
    }

    foreach (Rite::cases() as $rite) {
        if (MissalCatalog::for($rite)->isValid($subResource)) {
            return $rite;
        }
    }

    return null;
}
```

And the mapping loop it feeds, which is the other piece not copied. Everything else in the script —
CLI guard, `.env` loading, flag parsing, tuple reads, the write/prune calls and their exception
handling — is the predecessor's, structurally unchanged:

```php
/**
 * The successor object for one legacy tuple, or null when the tuple should be reported and skipped.
 *
 * @return array{0: string, 1: string}|null [objectType, objectId]
 */
function successorFor(string $objectType, string $objectId): ?array
{
    if ($objectType === 'general_roman_calendar_test') {
        // #767 gave this type exactly one id, denoting the Roman rite-level calendar.
        return $objectId === 'general_roman_calendar' ? ['rite_calendar_test', 'roman'] : null;
    }

    if ($objectType !== 'general_roman_calendar') {
        return null;
    }

    // Already migrated by a previous run: leave it alone rather than double-qualifying it.
    if (null !== RiteScopedObjectId::parse($objectId)) {
        return null;
    }

    $rite = riteForLegacySubResource($objectId);

    return null === $rite ? null : ['rite_calendar', RiteScopedObjectId::qualify($rite, $objectId)];
}
```

Note the already-migrated guard: `RiteScopedObjectId::parse()` returning non-null means the id
already carries a rite, so re-running the script after a partial run is a no-op on those tuples
rather than producing `rite_calendar:roman/roman/decrees`. This is what "safe to re-run" means
concretely, and it is the single easiest thing to get wrong here.

- [ ] **Step 3: Verify the script parses and its dry run is inert**

Run: `php -l scripts/migrate-rite-calendar-tuples.php`
Expected: `No syntax errors detected`.

Run: `php scripts/migrate-rite-calendar-tuples.php --dry-run`
Expected: either a plan printed against a reachable dev store, or a clear message that `OPENFGA_*` is unset. **Either is acceptable**; what must NOT happen is a write. If a local
OpenFGA is not running, that is fine — this script is operator-run and is exercised for real in §9 step 3.

- [ ] **Step 4: Confirm it refuses to run over HTTP**

Run: `grep -n 'php_sapi_name\|PHP_SAPI' scripts/migrate-rite-calendar-tuples.php`
Expected: a CLI-only guard is present, matching the predecessors. These scripts ship to the server and sit under a path whose `.php` files are handed to php-fpm.

- [ ] **Step 5: Style check**

Run: `composer lint && composer parallel-lint`
Expected: clean.

- [ ] **Step 6: Commit**

```bash
git add scripts/migrate-rite-calendar-tuples.php
git commit -m "feat(authz): add the rite_calendar tuple migration script (#955)"
```

---

### Task 7: Doctrine migration for persisted `resource_type`

**Files:**

- Create: `src/Migrations/Version20260901130000.php`
- Test: `phpunit_tests/Repositories/RiteCalendarResourceTypeMigrationTest.php`

**Interfaces:**

- Consumes: nothing from earlier tasks (raw SQL).
- Produces: no PHP surface. Rewrites `sourcedata_change_requests.resource_type` / `.resource_id` and the `access_requests.permissions` JSONB tuple array.

`audit_log` is **deliberately not rewritten** — it records what an operator did under the name in force at the time.

`resource_type` is a plain `VARCHAR` on both tables with no CHECK constraint and no PG enum, so these are plain `UPDATE`s.

- [ ] **Step 1: Write the failing test**

Create `phpunit_tests/Repositories/RiteCalendarResourceTypeMigrationTest.php`, extending `RepositoryTestCase` (it skips cleanly when `DB_*` is unset):

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Repositories;

use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * The #955 rewrite of persisted resource types, exercised as SQL against a real Postgres.
 *
 * Deliberately re-executes the migration's statements rather than invoking Doctrine: the point
 * is to pin the SQL's behaviour on representative rows, especially the JSONB rewrite, which is
 * the part that is easy to get subtly wrong.
 */
#[CoversNothing]
final class RiteCalendarResourceTypeMigrationTest extends RepositoryTestCase
{
    private const REWRITE_SCR = <<<'SQL'
        UPDATE sourcedata_change_requests
           SET resource_type = 'rite_calendar',
               resource_id   = CASE
                   WHEN resource_id LIKE 'roman/%' OR resource_id LIKE 'ambrosian/%' THEN resource_id
                   WHEN resource_id = 'EDITIO_TYPICA_2024' THEN 'ambrosian/' || resource_id
                   ELSE 'roman/' || resource_id
               END
         WHERE resource_type = 'general_roman_calendar'
        SQL;

    private const REWRITE_PERMISSIONS = <<<'SQL'
        UPDATE access_requests
           SET permissions = (
                 SELECT jsonb_agg(
                     CASE
                         WHEN elem->>'object_type' = 'general_roman_calendar' THEN
                             jsonb_set(
                                 jsonb_set(elem, '{object_type}', '"rite_calendar"'),
                                 '{object_id}',
                                 to_jsonb(
                                     CASE
                                         WHEN elem->>'object_id' LIKE 'roman/%' OR elem->>'object_id' LIKE 'ambrosian/%'
                                             THEN elem->>'object_id'
                                         WHEN elem->>'object_id' = 'EDITIO_TYPICA_2024'
                                             THEN 'ambrosian/' || (elem->>'object_id')
                                         ELSE 'roman/' || (elem->>'object_id')
                                     END
                                 )
                             )
                         WHEN elem->>'object_type' = 'general_roman_calendar_test' THEN
                             jsonb_set(
                                 jsonb_set(elem, '{object_type}', '"rite_calendar_test"'),
                                 '{object_id}',
                                 '"roman"'
                             )
                         ELSE elem
                     END
                     ORDER BY t.ord
                 )
                 FROM jsonb_array_elements(permissions) WITH ORDINALITY AS t(elem, ord)
               )
         WHERE permissions @> '[{"object_type": "general_roman_calendar"}]'
            OR permissions @> '[{"object_type": "general_roman_calendar_test"}]'
        SQL;

    public function testChangeRequestRowsAreRetypedAndRiteQualified(): void
    {
        $this->seedChangeRequest('general_roman_calendar', 'decrees');
        $this->seedChangeRequest('general_roman_calendar', 'EDITIO_TYPICA_2024');
        $this->seedChangeRequest('national_calendar', 'roman/US');

        self::$pdo->exec(self::REWRITE_SCR);

        self::assertSame(['rite_calendar', 'roman/decrees'], $this->fetchResource('decrees'));
        self::assertSame(['rite_calendar', 'ambrosian/EDITIO_TYPICA_2024'], $this->fetchResource('EDITIO_TYPICA_2024'));

        // An untouched type keeps both of its values.
        self::assertSame(['national_calendar', 'roman/US'], $this->fetchResource('roman/US'));
    }

    public function testTheRewriteIsIdempotent(): void
    {
        $this->seedChangeRequest('general_roman_calendar', 'decrees');

        self::$pdo->exec(self::REWRITE_SCR);
        self::$pdo->exec(self::REWRITE_SCR);

        self::assertSame(['rite_calendar', 'roman/decrees'], $this->fetchResource('decrees'));
    }

    public function testPendingPermissionTuplesAreRewrittenElementWise(): void
    {
        $this->seedAccessRequest([
            ['object_type' => 'general_roman_calendar', 'object_id' => 'decrees', 'relation' => 'editor'],
            ['object_type' => 'general_roman_calendar_test', 'object_id' => 'general_roman_calendar', 'relation' => 'editor'],
            ['object_type' => 'national_calendar', 'object_id' => 'roman/US', 'relation' => 'admin'],
        ]);

        self::$pdo->exec(self::REWRITE_PERMISSIONS);

        $tuples = $this->fetchPermissions();

        self::assertSame('rite_calendar', $tuples[0]['object_type']);
        self::assertSame('roman/decrees', $tuples[0]['object_id']);
        self::assertSame('editor', $tuples[0]['relation'], 'the relation must survive untouched');

        self::assertSame('rite_calendar_test', $tuples[1]['object_type']);
        self::assertSame('roman', $tuples[1]['object_id']);

        // An unrelated tuple in the same array is left exactly as it was.
        self::assertSame('national_calendar', $tuples[2]['object_type']);
        self::assertSame('roman/US', $tuples[2]['object_id']);
    }

    public function testAuditLogIsNotRewritten(): void
    {
        $this->seedAuditRow('general_roman_calendar', 'decrees');

        self::$pdo->exec(self::REWRITE_SCR);
        self::$pdo->exec(self::REWRITE_PERMISSIONS);

        $row = self::$pdo->query(
            "SELECT resource_type FROM audit_log WHERE resource_id = 'decrees'"
        )->fetchColumn();

        self::assertSame(
            'general_roman_calendar',
            $row,
            'the audit log records what happened under the name in force at the time'
        );
    }
}
```

Write the four `seed*` / `fetch*` helpers against the real column sets — inspect them first with:
`docker compose exec -T db psql -U litcal -d litcal -c '\d sourcedata_change_requests' -c '\d access_requests' -c '\d audit_log'`
and supply every `NOT NULL` column without a default.

- [ ] **Step 2: Run the test to verify it fails or skips**

Run: `vendor/bin/phpunit phpunit_tests/Repositories/RiteCalendarResourceTypeMigrationTest.php`
Expected: PASS once the helpers are right (the SQL is inlined in the test), or SKIP if Postgres is unconfigured. **If it skips, start the stack** — `docker compose up -d --build`
— and re-run; this task's whole value is that the SQL is executed.

- [ ] **Step 3: Write the migration**

Create `src/Migrations/Version20260901130000.php` carrying the **same two statements verbatim** as the test's constants, plus a `down()` that inverts them:

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Migrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Bring persisted resource types onto the generalised `rite_calendar` tier (#955).
 *
 * Two tables are rewritten and one deliberately is not.
 *
 * `sourcedata_change_requests` and `access_requests.permissions` both still drive FUTURE
 * authorization decisions: a queued change request is reviewed against its resource, and a
 * PENDING access request is approved into real OpenFGA tuples. A pending request left naming
 * the legacy type would be approved into a legacy tuple after cutover, re-creating exactly the
 * state this migration exists to remove — which is why the JSONB array is rewritten
 * element-wise rather than being left to the tuple migration script.
 *
 * `audit_log` is NOT rewritten. It records what an operator actually did, under the name in
 * force at the time; rewriting it would falsify the record, and any archived `details` JSONB
 * mentioning the old type would then disagree with its own row. The cutover date is documented
 * in `docs/ops/rite-calendar-migration-runbook.md` so a reader can resolve old names.
 *
 * Both statements are idempotent: their WHERE clauses match only unmigrated rows, and the id
 * rewrite is a no-op on an already-qualified id.
 */
final class Version20260901130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Retype persisted general_roman_calendar resources onto the rite-qualified rite_calendar tier (#955)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !( $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform ),
            'This migration targets PostgreSQL only.'
        );

        // The two statements are the test's REWRITE_SCR and REWRITE_PERMISSIONS, verbatim.
        $this->addSql(<<<'SQL'
            UPDATE sourcedata_change_requests
               SET resource_type = 'rite_calendar',
                   resource_id   = CASE
                       WHEN resource_id LIKE 'roman/%' OR resource_id LIKE 'ambrosian/%' THEN resource_id
                       WHEN resource_id = 'EDITIO_TYPICA_2024' THEN 'ambrosian/' || resource_id
                       ELSE 'roman/' || resource_id
                   END
             WHERE resource_type = 'general_roman_calendar'
            SQL);

        $this->addSql(<<<'SQL'
            UPDATE access_requests
               SET permissions = (
                     SELECT jsonb_agg(
                         CASE
                             WHEN elem->>'object_type' = 'general_roman_calendar' THEN
                                 jsonb_set(
                                     jsonb_set(elem, '{object_type}', '"rite_calendar"'),
                                     '{object_id}',
                                     to_jsonb(
                                         CASE
                                             WHEN elem->>'object_id' LIKE 'roman/%' OR elem->>'object_id' LIKE 'ambrosian/%'
                                                 THEN elem->>'object_id'
                                             WHEN elem->>'object_id' = 'EDITIO_TYPICA_2024'
                                                 THEN 'ambrosian/' || (elem->>'object_id')
                                             ELSE 'roman/' || (elem->>'object_id')
                                         END
                                     )
                                 )
                             WHEN elem->>'object_type' = 'general_roman_calendar_test' THEN
                                 jsonb_set(
                                     jsonb_set(elem, '{object_type}', '"rite_calendar_test"'),
                                     '{object_id}',
                                     '"roman"'
                                 )
                             ELSE elem
                         END
                         ORDER BY t.ord
                     )
                     FROM jsonb_array_elements(permissions) WITH ORDINALITY AS t(elem, ord)
                   )
             WHERE permissions @> '[{"object_type": "general_roman_calendar"}]'
                OR permissions @> '[{"object_type": "general_roman_calendar_test"}]'
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !( $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform ),
            'This migration targets PostgreSQL only.'
        );

        // Refuse rather than corrupt: the rewrite below strips the rite prefix unconditionally,
        // so any rite_calendar value naming a rite other than `roman` would come back as a Roman
        // legacy id. `ambrosian/EDITIO_TYPICA_2024` is exempt — up() produces it and down()
        // returns it to the bare id it came from.
        $unsafeCount = (int) $this->connection->fetchOne(
            <<<'SQL'
                SELECT (
                    SELECT count(*)
                      FROM sourcedata_change_requests
                     WHERE resource_type = 'rite_calendar'
                       AND position('/' in resource_id) > 0
                       AND resource_id NOT LIKE 'roman/%'
                       AND resource_id <> :exempt_resource_id
                ) + (
                    SELECT count(*)
                      FROM access_requests ar,
                           LATERAL jsonb_array_elements(ar.permissions) AS elem
                     WHERE elem->>'object_type' = 'rite_calendar'
                       AND position('/' in elem->>'object_id') > 0
                       AND elem->>'object_id' NOT LIKE 'roman/%'
                       AND elem->>'object_id' <> :exempt_object_id
                )
                SQL,
            [
                'exempt_resource_id' => self::ROUND_TRIPPING_NON_ROMAN_ID,
                'exempt_object_id'   => self::ROUND_TRIPPING_NON_ROMAN_ID,
            ]
        );

        $this->abortIf($unsafeCount > 0, /* message naming the count and the runbook */ '...');

        $this->addSql(<<<'SQL'
            UPDATE sourcedata_change_requests
               SET resource_type = 'general_roman_calendar',
                   resource_id   = regexp_replace(resource_id, '^(roman|ambrosian)/', '')
             WHERE resource_type = 'rite_calendar'
            SQL);

        $this->addSql(<<<'SQL'
            UPDATE access_requests
               SET permissions = (
                     SELECT jsonb_agg(
                         CASE
                             WHEN elem->>'object_type' = 'rite_calendar' THEN
                                 jsonb_set(
                                     jsonb_set(elem, '{object_type}', '"general_roman_calendar"'),
                                     '{object_id}',
                                     to_jsonb(regexp_replace(elem->>'object_id', '^(roman|ambrosian)/', ''))
                                 )
                             ELSE elem
                         END
                         ORDER BY t.ord
                     )
                     FROM jsonb_array_elements(permissions) WITH ORDINALITY AS t(elem, ord)
                   )
             WHERE permissions @> '[{"object_type": "rite_calendar"}]'
            SQL);
    }
}
```

**Two corrections made while executing this step, folded back into the SQL above.**

The `jsonb_agg` calls carry `WITH ORDINALITY` and an explicit `ORDER BY t.ord`. `permissions` is a
JSON *list* that `AccessRequestRepository` decodes straight into a PHP list, so element order is part
of the stored value; `jsonb_agg` has no defined input order without an `ORDER BY`, and the fact that
`jsonb_array_elements` happens to emit rows in array order today is an implementation detail rather
than a guarantee. Leaving it implicit would have made a production data migration silently able to
reorder a pending user's request.

`down()` **refuses** rather than folding a post-cutover non-Roman row. See the limits below.

**Two honest limits of `down()`, to be stated in its docblock rather than papered over.**

It does **not** restore `general_roman_calendar_test` from `rite_calendar_test`: that type has had two
possible provenances since #767, so a `rite_calendar_test:roman` row may predate this migration
entirely, and reverting it would corrupt rows this migration never touched. Down-migrating leaves
those as they are, which is correct — they were already valid before #955.

The Ambrosian id is **hardcoded** in `up()` rather than derived. That is deliberate and is how a
migration should be written: it is a point-in-time artifact recording the id set as it stood on
2026-09-01. Deriving it from `MissalCatalog` would make an already-applied migration's behaviour
change retroactively as the catalog grows, which is precisely what migrations exist to prevent.
`RiteCalendarObjectIds` is the live, derived authority; this is the frozen snapshot.

- [ ] **Step 4: Apply and verify**

Run: `composer db:migrate && composer db:migrations:status`
Expected: the new version applied, status clean.

Run: `vendor/bin/phpunit phpunit_tests/Repositories/`
Expected: PASS.

- [ ] **Step 5: Verify `down()` actually reverses**

Run: `vendor/bin/doctrine-migrations migrations:execute --down 'LiturgicalCalendar\Api\Migrations\Version20260901130000' --no-interaction && composer db:migrate`
Expected: both succeed. A `down()` that errors is a broken migration even if `up()` works.

- [ ] **Step 6: Style and analysis**

Run: `composer lint && composer analyse`
Expected: clean. `src/Migrations` is excluded from coverage but not from lint.

- [ ] **Step 7: Commit**

```bash
git add src/Migrations/Version20260901130000.php phpunit_tests/Repositories/RiteCalendarResourceTypeMigrationTest.php
git commit -m "feat(db): retype persisted resources onto rite_calendar, leaving audit_log intact (#955)"
```

---

### Task 8: Published contract — OpenAPI

**Files:**

- Modify: `jsondata/schemas/openapi.json`

**Interfaces:**

- Consumes: nothing.
- Produces: `rite_calendar` in all eight `object_type` / `resource_type` enums.

The eight sites all currently carry a **byte-identical** value list. Preserve that: it means the vocabulary has one definition in the contract and a drifting site is mechanically
detectable.

**Canonical encoding matters.** `openapi.json` must be written with `ensure_ascii=FALSE` (non-ASCII stays literal). Writing it with `ensure_ascii=True` reds the `jsondata_lint`
CI job. Verify with `composer lint:jsondata`, **not** just `composer lint:openapi`.

- [ ] **Step 1: Confirm the eight sites and their shared value list**

Run:

```bash
python3 - <<'PY'
import json
d=json.load(open('jsondata/schemas/openapi.json'))
hits=[]
def walk(n,p):
    if isinstance(n,dict):
        for k,v in n.items():
            if k=='enum' and isinstance(v,list) and 'general_roman_calendar' in v: hits.append((p,tuple(v)))
            walk(v,p+'/'+str(k))
    elif isinstance(n,list):
        for i,v in enumerate(n): walk(v,p+'/'+str(i))
walk(d,'')
print(len(hits),"sites;",len(set(v for _,v in hits)),"distinct value-sets")
for p,_ in hits: print(" ",p)
PY
```

Expected: `8 sites; 1 distinct value-sets`.

- [ ] **Step 2: Add the value at all eight sites**

Run:

```bash
python3 - <<'PY'
import json,collections
p='jsondata/schemas/openapi.json'
d=json.load(open(p,encoding='utf-8'),object_pairs_hook=collections.OrderedDict)
n=0
def walk(x):
    global n
    if isinstance(x,dict):
        for k,v in x.items():
            if k=='enum' and isinstance(v,list) and 'general_roman_calendar' in v and 'rite_calendar' not in v:
                v.insert(v.index('general_roman_calendar'),'rite_calendar'); n+=1
            walk(v)
    elif isinstance(x,list):
        for v in x: walk(v)
walk(d)
open(p,'w',encoding='utf-8').write(json.dumps(d,indent=2,ensure_ascii=False)+'\n')
print("patched",n,"enums")
PY
```

Expected: `patched 8 enums`.

- [ ] **Step 3: Verify encoding and byte-level sanity**

Run: `composer lint:jsondata && composer lint:openapi && git diff --stat jsondata/schemas/openapi.json`
Expected: both linters clean, and the diff touches only the eight enums — **if the diff is thousands of lines, the re-serialisation changed formatting**; revert and patch the
file textually instead.

- [ ] **Step 4: Rewrite the id-shape prose**

Several descriptions assert the opposite of what is now true — e.g. "general_roman_calendar ids stay bare (e.g. `temporale`)". Find them:

Run: `grep -n 'ids stay bare\|Bare for .general_roman_calendar' jsondata/schemas/openapi.json`

Rewrite each to say that `rite_calendar` ids are rite-qualified like the calendar-naming types, that the deprecated `general_roman_calendar` keeps bare ids until the prune
milestone, and that `general_roman_calendar_test` and `rite_calendar_test` remain bare (the latter's id being the rite itself).

Also update:

- line ~2588 (`/auth/dashboard-scopes` description) — the type list it enumerates.
- lines ~11181, ~11267, ~11353 (decrees summaries/descriptions) — `general_roman_calendar:decrees` → `rite_calendar:roman/decrees`.
- lines ~10056, ~10412, ~10766 (missal entry descriptions) — `general_roman_calendar:{missal_id}` → `rite_calendar:{rite}/{missal_id}`. At ~10766, **remove** the now-satisfied
  parenthetical "(The `general_roman_calendar` type name is rite-agnostic in practice; #955 tracks renaming it.)".

- lines ~4898, ~4958, ~12938 (locale curation) — `general_roman_calendar:supported_locales` → `rite_calendar:roman/supported_locales`.
- line ~16541 (`viewer` object ids description) — the key list.

Add to each of the eight enums' surrounding `description` a deprecation note, e.g.:

> `general_roman_calendar` and `general_roman_calendar_test` are **deprecated** and accepted only until the #955 prune milestone; use `rite_calendar` and `rite_calendar_test`.

- [ ] **Step 5: Re-verify**

Run: `composer lint:jsondata && composer lint:openapi`
Expected: clean.

Run: `vendor/bin/phpunit phpunit_tests/Schemas/`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add jsondata/schemas/openapi.json
git commit -m "docs(openapi): publish rite_calendar and deprecate general_roman_calendar (#955)

Adds the value at all eight object_type/resource_type enum sites and inverts
the id-shape prose. Breaking on the RESPONSE side for four of the eight
(Permission, ChangeRequestBatch, ChangeRequestNotification,
ChangeRequestReviewedNotification): an exhaustive client-side switch on
resource_type must add an arm. The request-side four merely accept more."
```

---

### Task 9: Operator runbook and stale-comment corrections

**Files:**

- Create: `docs/ops/rite-calendar-migration-runbook.md`
- Modify: `scripts/migrate-rite-data-tuples.php` (header docblock — the stale claim)
- Modify: `src/Handlers/TestsHandler.php:516`, `src/Repositories/SourceDataChangeRequestRepository.php:395` (prose)

**Interfaces:** none — documentation only.

- [ ] **Step 1: Correct the stale claim in the #786 script**

`scripts/migrate-rite-data-tuples.php` currently asserts:

> `general_roman_calendar` is deliberately untouched: its ids are `temporale`, `decrees` and missal editions, which are not calendars and are Roman by construction.

That second clause has been false since #953 added the Ambrosian `EDITIO_TYPICA_2024`. Replace with:

```php
 * `general_roman_calendar` is deliberately untouched HERE: its ids are `temporale`, `decrees`,
 * `supported_locales` and missal editions, which are not calendars. They were Roman by
 * construction when this script was written; #953 added the Ambrosian `EDITIO_TYPICA_2024`, and
 * #955 generalises the whole type to `rite_calendar` with rite-qualified ids. That migration is
 * `scripts/migrate-rite-calendar-tuples.php`, not this one.
```

- [ ] **Step 2: Update the two prose references**

`src/Handlers/TestsHandler.php:516` — the passage naming "the legacy `general_roman_calendar_test` it no longer emits but `AccessRequestRepository::VALID_OBJECT_TYPES` still
recognises" is still accurate; add that it is retired at the #955 prune milestone.

`src/Repositories/SourceDataChangeRequestRepository.php:395` — `general_roman_calendar:decrees` → `rite_calendar:roman/decrees`.

- [ ] **Step 3: Write the runbook**

Create `docs/ops/rite-calendar-migration-runbook.md`, modelled on `docs/ops/test-scope-migration-runbook.md`. It must contain:

- **Background** — the type rename and id qualification, with the mapping table.
- **Pre-flight** — `OPENFGA_API_URL` / `OPENFGA_STORE_ID` / `OPENFGA_MODEL_ID` set; API reachable.
- **Step 1 — apply the model version.** State plainly that the model is owned by `cdcf-infra` at `auth/models/LiturgicalCalendar.json`, that the change is additive
  (`rite_calendar` with `admin`/`viewer`/`editor` mirroring `general_roman_calendar`), that an operator uploads it, and that `./scripts/setup-openfga.sh --update-env` re-pins
  `OPENFGA_MODEL_ID` afterwards. **Nothing else can start**: a tuple on a type the model does not carry cannot be written.

- **Step 2 — deploy the API.** Note it is safe in either order relative to step 3, because of the middleware's legacy fallback.
- **Step 3 — `php scripts/migrate-rite-calendar-tuples.php --dry-run`, then `--apply`.** Copy-only; nothing deleted.
- **Step 4 — apply the Doctrine migration** (`composer db:migrate`), and **record the cutover date here**, because `audit_log` is deliberately not rewritten and a reader needs it
  to resolve old names.

- **Step 5 — deploy the Frontend.**
- **Step 6 — prune, later.** Only once every deployment runs merged code. Say explicitly that this should share an operator window with the deferred RBAC `deleter` drop: both
  wait on the identical condition and neither depends on the other. List what prune entails: `--prune`, then an API PR dropping the legacy types from `AccessRequestRepository`,
  `ResourceAdminService`, `ResourceExistenceChecker` and the middleware fallback, then a `cdcf-infra` model version dropping both legacy types, then moving them to
  `forbidden_types` in `authz/openfga-expectations.json`.

- **Rollback** — for each step, what undoes it. Note that steps 1–3 are all non-destructive without `--prune`.

- [ ] **Step 4: Lint the markdown**

Run: `composer lint:md`
Expected: clean. Watch MD060 — tables must have vertically aligned pipes.

- [ ] **Step 5: Full verification**

Run: `composer test:quick && composer analyse && composer lint && composer lint:md && composer lint:jsondata`
Expected: all green.

- [ ] **Step 6: Commit**

```bash
git add docs/ops/rite-calendar-migration-runbook.md scripts/migrate-rite-data-tuples.php src/Handlers/TestsHandler.php src/Repositories/SourceDataChangeRequestRepository.php
git commit -m "docs(ops): add the rite_calendar migration runbook and correct stale claims (#955)"
```

---

## Follow-ups (NOT part of this plan)

Open these as issues when the plan lands; none of them belongs in this branch.

1. **The prune milestone.** Drop `general_roman_calendar` and `general_roman_calendar_test` from every allow-list, remove the middleware fallback, run `--prune`, ship the
   `cdcf-infra` model version, move both to `forbidden_types`. Share the operator window with the deferred RBAC `deleter` drop.
2. **The Frontend mirror** — 22 files in `LiturgicalCalendarFrontend`. Cannot merge before this branch's OpenAPI lands.
3. **`supportedLocales.json`'s `general_roman_calendar` key** — a *data* key sharing only the spelling, out of scope here. Renaming it touches `SupportedLocales`,
   `scripts/lint-locales.php`, `composer lint:locales` and the published `SupportedLocales.json` schema.
4. **`ChangeResource::branch()`** now yields `litcal-data/rite_calendar/roman/temporale` — three path segments where the old form had two. Unused in phase 1, but confirm the
   publisher tolerates it before phase 2.
