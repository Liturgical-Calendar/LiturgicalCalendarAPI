# `/validations` Checkable Inventory Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or
> superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let the API advertise the source data it can validate, so clients stop hardcoding this repo's on-disk layout.

**Architecture:** A typed inventory of checkable items — half derived from `RomanMissal`, half explicit — is served by a
new `GET /validations` endpoint and then reused inside `Health` so the same list resolves schemas for WebSocket checks.
`Health` gains nothing and sheds two hand-maintained lookups.

**Tech Stack:** PHP 8.4, PSR-7/15 handlers, PHPUnit 12, `swaggest/json-schema` for response validation.

## Global Constraints

- Work in the API worktree `/home/johnrdorazio/development/LiturgicalCalendar/LiturgicalCalendarAPI-inventory` on branch
  `feat/806-checkable-inventory` (PR base: `development`). **Never commit in the main checkout**
  `/home/johnrdorazio/development/LiturgicalCalendar/LiturgicalCalendarAPI` — it is shared with other agents.
- Never use `git commit --no-verify`. Commits are GPG-signed; if signing fails, stop and ask rather than disabling it.
- PSR-12 per `phpcs.xml`; short array syntax; 4-space indent; single quotes unless interpolating.
- PHPStan level 10 over `src` only — `phpunit_tests/` is not analysed.
- **Paths come from `JsonData` enum cases, never string literals.**
- **`CheckableItem` must never serialize its `path`.** Keeping filesystem paths off the wire is the entire feature.
- **The endpoint must not touch the filesystem.** Advertising is not verification; `exists` is the first check, not a
  precondition for being listed.
- Response envelope is `litcal_validations`, matching the house `litcal_*` convention.
- No `protocol` field and no query parameters. Versioning is #806 section F; filtering is client-side by decision.
- Spec: `docs/superpowers/specs/2026-08-19-checkable-inventory-design.md`.

---

## File Structure

| File                                                              | Responsibility                                                   |
|-------------------------------------------------------------------|------------------------------------------------------------------|
| `src/Models/ValidationsPath/CheckableItem.php`                    | One item; owns the rule that `path` never crosses the wire       |
| `src/Models/ValidationsPath/CheckableInventory.php`               | Builds the list (derived + explicit); lookup by id and by path   |
| `src/Handlers/ValidationsHandler.php`                             | `GET /validations`; serialization only                           |
| `src/Enum/Route.php`                                              | `case VALIDATIONS = '/validations'`                              |
| `src/Enum/LitSchema.php`                                          | `case VALIDATIONS = '/LitCalValidationsPath.json'`               |
| `src/Router.php`                                                  | `case 'validations'` dispatch                                    |
| `jsondata/schemas/LitCalValidationsPath.json`                     | Response schema, refd by `openapi.json`                          |
| `jsondata/schemas/openapi.json`                                   | `/validations` path entry                                        |
| `src/Health.php`                                                  | Sheds the kind-1 arms of two lookups; delegates to the inventory |
| `phpunit_tests/Models/ValidationsPath/CheckableInventoryTest.php` | Equivalence with today's table, region mapping, scope predicate  |
| `phpunit_tests/Models/ValidationsPath/InventoryDriftTest.php`     | Fails when data exists on disk with no inventory entry           |
| `phpunit_tests/Handlers/ValidationsHandlerTest.php`               | HTTP shape, no-path-leak, method and param handling              |
| `phpunit_tests/Handlers/ReadonlyPathsResponseSchemaTest.php`      | One more case: `/validations` against its schema                 |

### The 18 items

Nine files and their nine `i18n` folders.

**Derived from `RomanMissal`** (5 files + 5 folders). `RomanMissal::getMissalIds()` returns eleven ids;
`getSanctoraleFileName($id)` returns a path for `EDITIO_TYPICA_1970`, `EDITIO_TYPICA_2002`, `EDITIO_TYPICA_2008`,
`US_2011`, `IT_1983` and `false` for the other six. Labels come from `getName()`, i18n folders from
`getSanctoraleI18nFilePath()`, and `region` from the same rule `produceMetadata()` uses — except that this inventory
emits `null` where that method emits `'VA'` (see the spec's "`region` semantics").

**Explicit** (4 files + 4 folders): Roman temporale, Roman decrees, Ambrosian temporale, Ambrosian sanctorale.

The Ambrosian sanctorale's id is `sanctorale:ambrosian`, not the `sanctorale:ambrosian:2024` the spec's illustrative
example shows. There is one Ambrosian sanctorale and no registry to derive an edition from, so baking a year into the
id would promise a dimension that does not exist. The Roman sanctorale ids do carry their missal id, because there
`RomanMissal` genuinely distinguishes five of them.

---

## Task 1: The inventory

**Files:**

- Create: `src/Models/ValidationsPath/CheckableItem.php`
- Create: `src/Models/ValidationsPath/CheckableInventory.php`
- Test: `phpunit_tests/Models/ValidationsPath/CheckableInventoryTest.php`

**Interfaces:**

- Consumes: `LiturgicalCalendar\Api\Enum\Rite` (`Rite::ROMAN`, `Rite::AMBROSIAN`, string-backed);
  `LiturgicalCalendar\Api\Enum\LitSchema` (string-backed, values start with `/`, `path()` returns a filesystem path);
  `LiturgicalCalendar\Api\Enum\JsonData` (cases expose `path()`); `LiturgicalCalendar\Api\Enum\RomanMissal`
  (`getMissalIds(): array`, `getSanctoraleFileName(string): string|false`, `getSanctoraleI18nFilePath(string): string|false`,
  `getName(string): string`).
- Produces: `CheckableItem` with public readonly `id`, `kind`, `rite` (`Rite`), `region` (`?string`), `label`,
  `schema` (`LitSchema`), `steps` (`list<string>`), `path` (`string`); and
  `CheckableInventory::all(): list<CheckableItem>`, `::byId(string $id): ?CheckableItem`,
  `::byPath(string $path): ?CheckableItem`.

- [ ] **Step 1: Confirm the worktree**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LiturgicalCalendarAPI-inventory
git rev-parse --show-toplevel   # must end in -inventory
git branch --show-current       # expect: feat/806-checkable-inventory
ls vendor/bin/phpunit           # must exist
```

- [ ] **Step 2: Write the failing test**

Create `phpunit_tests/Models/ValidationsPath/CheckableInventoryTest.php`:

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Models\ValidationsPath;

use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Enum\LitSchema;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Models\ValidationsPath\CheckableInventory;
use LiturgicalCalendar\Api\Models\ValidationsPath\CheckableItem;
use LiturgicalCalendar\Api\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The inventory of source data the API can validate (#806 step A).
 *
 * Its reason for existing is that the layout was written down in several places that had to be
 * edited in lockstep — the client's path constants, `Health`'s schema table, the `.vscode` globs —
 * and nothing failed loudly when they diverged. These tests pin the two properties that make one
 * list safe to rely on: it resolves everything the old table resolved, and its `region` mapping is
 * exactly what the client's scope predicate assumes.
 */
#[CoversClass(CheckableInventory::class)]
#[CoversClass(CheckableItem::class)]
final class CheckableInventoryTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // JsonData cases build filesystem paths from this prefix.
        Router::$apiFilePath = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR;
    }

    /**
     * The arms of `Health::getPathToSchemaFile()` that name source-data FILES, pasted here as the
     * oracle. The refactor in a later task is proved equivalent against this rather than assumed.
     * The route arms of that table are not source data and stay where they are.
     *
     * @return array<string, LitSchema>
     */
    private static function legacyFileTable(): array
    {
        return [
            JsonData::MISSALS_FOLDER->value . '/propriumdetempore/propriumdetempore.json'                 => LitSchema::PROPRIUMDETEMPORE,
            JsonData::MISSALS_FOLDER->value . '/propriumdesanctis_1970/propriumdesanctis_1970.json'       => LitSchema::PROPRIUMDESANCTIS,
            JsonData::MISSALS_FOLDER->value . '/propriumdesanctis_2002/propriumdesanctis_2002.json'       => LitSchema::PROPRIUMDESANCTIS,
            JsonData::MISSALS_FOLDER->value . '/propriumdesanctis_2008/propriumdesanctis_2008.json'       => LitSchema::PROPRIUMDESANCTIS,
            JsonData::MISSALS_FOLDER->value . '/propriumdesanctis_IT_1983/propriumdesanctis_IT_1983.json' => LitSchema::PROPRIUMDESANCTIS,
            JsonData::MISSALS_FOLDER->value . '/propriumdesanctis_US_2011/propriumdesanctis_US_2011.json' => LitSchema::PROPRIUMDESANCTIS,
            JsonData::AMBROSIAN_TEMPORALE_FILE->value                                                    => LitSchema::PROPRIUMDETEMPORE,
            JsonData::AMBROSIAN_SANCTORALE_FILE->value                                                   => LitSchema::PROPRIUMDESANCTIS,
        ];
    }

    public function testItResolvesEverythingTheOldFileTableResolved(): void
    {
        foreach (self::legacyFileTable() as $path => $schema) {
            $item = CheckableInventory::byPath($path);
            self::assertNotNull($item, "no inventory entry for {$path}");
            self::assertSame($schema, $item->schema, "wrong schema for {$path}");
        }
    }

    public function testEveryItemIsFullyPopulatedAndIdsAreUnique(): void
    {
        $ids = [];
        foreach (CheckableInventory::all() as $item) {
            self::assertNotSame('', $item->id);
            self::assertContains($item->kind, ['file', 'folder']);
            self::assertNotSame('', $item->label);
            self::assertNotSame('', $item->path);
            self::assertSame(['exists', 'parses', 'validates'], $item->steps);
            $ids[] = $item->id;
        }
        self::assertSame(array_unique($ids), $ids, 'inventory ids must be unique');
    }

    public function testItHoldsNineFilesAndNineFolders(): void
    {
        $kinds = array_count_values(array_map(
            static fn (CheckableItem $i): string => $i->kind,
            CheckableInventory::all()
        ));

        self::assertSame(9, $kinds['file']);
        self::assertSame(9, $kinds['folder']);
    }

    public function testTheFiveMissalsWithASanctoraleArePresentAndTheOthersAbsent(): void
    {
        $ids = array_map(static fn (CheckableItem $i): string => $i->id, CheckableInventory::all());

        foreach (['EDITIO_TYPICA_1970', 'EDITIO_TYPICA_2002', 'EDITIO_TYPICA_2008', 'US_2011', 'IT_1983'] as $missalId) {
            self::assertContains("sanctorale:roman:{$missalId}", $ids);
        }
        foreach (['EDITIO_TYPICA_1971', 'EDITIO_TYPICA_1975', 'IT_2020', 'NL_1978', 'CA_2011', 'CA_2016'] as $missalId) {
            self::assertNotContains("sanctorale:roman:{$missalId}", $ids);
        }
    }

    /**
     * The mapping the client's scope predicate depends on: `null` means "applies to the whole rite",
     * a nation code means "only that nation's calendar". Deliberately NOT produceMetadata()'s 'VA',
     * which is a nation code and would be simply false on the Ambrosian items.
     */
    public function testRegionIsNullForUniversalItemsAndANationCodeForNationalEditions(): void
    {
        self::assertNull(CheckableInventory::byId('temporale:roman')?->region);
        self::assertNull(CheckableInventory::byId('sanctorale:roman:EDITIO_TYPICA_1970')?->region);
        self::assertNull(CheckableInventory::byId('temporale:ambrosian')?->region);
        self::assertNull(CheckableInventory::byId('sanctorale:ambrosian')?->region);

        self::assertSame('US', CheckableInventory::byId('sanctorale:roman:US_2011')?->region);
        self::assertSame('IT', CheckableInventory::byId('sanctorale:roman:IT_1983')?->region);
    }

    /**
     * The client's predicate, applied here so the data is proved fit for it:
     *   item.rite === rite && (item.region === null || item.region === nation)
     */
    public function testTheClientScopePredicateSelectsTheRightItems(): void
    {
        $inScope = static fn (CheckableItem $i, Rite $rite, ?string $nation): bool
            => $i->rite === $rite && ( $i->region === null || $i->region === $nation );

        $usa = array_map(
            static fn (CheckableItem $i): string => $i->id,
            array_values(array_filter(
                CheckableInventory::all(),
                static fn (CheckableItem $i): bool => $inScope($i, Rite::ROMAN, 'US')
            ))
        );
        self::assertContains('sanctorale:roman:US_2011', $usa);
        self::assertNotContains('sanctorale:roman:IT_1983', $usa);
        self::assertContains('temporale:roman', $usa);

        $ambrosian = array_map(
            static fn (CheckableItem $i): string => $i->id,
            array_values(array_filter(
                CheckableInventory::all(),
                static fn (CheckableItem $i): bool => $inScope($i, Rite::AMBROSIAN, null)
            ))
        );
        sort($ambrosian);
        self::assertSame(
            ['sanctorale:ambrosian', 'sanctorale:ambrosian:i18n', 'temporale:ambrosian', 'temporale:ambrosian:i18n'],
            $ambrosian
        );
    }

    public function testTheSerializedFormNeverCarriesAPath(): void
    {
        foreach (CheckableInventory::all() as $item) {
            $encoded = json_encode($item, JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString('jsondata', $encoded, "item {$item->id} leaked a path");
            self::assertArrayNotHasKey('path', (array) json_decode($encoded, true, 512, JSON_THROW_ON_ERROR));
        }
    }
}
```

- [ ] **Step 3: Run the test to verify it fails**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LiturgicalCalendarAPI-inventory
vendor/bin/phpunit phpunit_tests/Models/ValidationsPath/CheckableInventoryTest.php
```

Expected: an error, not failures — `Class "LiturgicalCalendar\Api\Models\ValidationsPath\CheckableInventory" not found`.

- [ ] **Step 4: Write `CheckableItem`**

Create `src/Models/ValidationsPath/CheckableItem.php`:

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Models\ValidationsPath;

use LiturgicalCalendar\Api\Enum\LitSchema;
use LiturgicalCalendar\Api\Enum\Rite;

/**
 * One thing the API can be asked to validate: a source-data file, or a folder of i18n files.
 *
 * `$path` is the server-side location a check resolves against, and is deliberately absent from
 * the serialized form. Keeping filesystem paths off the wire is the whole point of `/validations`
 * (#806 step A): clients that hardcoded them had to be edited in lockstep with every layout
 * change, which is #737/UnitTestInterface#38, #795 and #800. The omission lives in this class
 * rather than in the handler so that a second caller cannot forget it.
 *
 * `$region` is `null` when the item applies to its whole rite, and an ISO nation code when it
 * applies only to that nation's calendar. This differs on purpose from
 * {@see \LiturgicalCalendar\Api\Enum\RomanMissal::produceMetadata()}, which reports `'VA'` for the
 * editiones typicae: `'VA'` is a nation code that only reads as "universal" because the General
 * Roman Calendar happens to be served under nation VA, and it would be false on Ambrosian items.
 */
final readonly class CheckableItem implements \JsonSerializable
{
    /**
     * @param 'file'|'folder' $kind
     * @param list<string> $steps
     */
    public function __construct(
        public string $id,
        public string $kind,
        public Rite $rite,
        public ?string $region,
        public string $label,
        public LitSchema $schema,
        public array $steps,
        public string $path
    ) {
    }

    /**
     * @return array{id:string,kind:string,rite:string,region:string|null,label:string,schema:string,steps:list<string>}
     */
    public function jsonSerialize(): array
    {
        return [
            'id'     => $this->id,
            'kind'   => $this->kind,
            'rite'   => $this->rite->value,
            'region' => $this->region,
            'label'  => $this->label,
            // LitSchema values are '/Foo.json'; the wire carries the bare filename.
            'schema' => ltrim($this->schema->value, '/'),
            'steps'  => $this->steps
        ];
    }
}
```

- [ ] **Step 5: Write `CheckableInventory`**

Create `src/Models/ValidationsPath/CheckableInventory.php`:

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Models\ValidationsPath;

use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Enum\LitSchema;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Enum\RomanMissal;
use LiturgicalCalendar\Api\Router;

/**
 * The source data this API can validate, in one place.
 *
 * Previously the same knowledge lived in `Health`'s path-to-schema table, in a parallel branch
 * that matched on slugs instead of paths, and in each client's hardcoded copy of the layout —
 * with nothing detecting divergence. See #806.
 *
 * Half of it need not be written down at all: `RomanMissal` already registers every missal edition
 * and already knows which have a sanctorale file, so those items are derived. The rest have
 * dedicated `JsonData` constants and are listed explicitly.
 *
 * Paths always come from `JsonData` cases. `JsonData` is where this repo's layout is written down;
 * this class must not become a second copy of it.
 */
final class CheckableInventory
{
    /** @var list<string> Every item is checked the same three ways today. */
    private const STEPS = ['exists', 'parses', 'validates'];

    /** @var list<CheckableItem>|null */
    private static ?array $items = null;

    /** @return list<CheckableItem> */
    public static function all(): array
    {
        if (null === self::$items) {
            self::$items = array_merge(self::derivedRomanSanctorale(), self::explicitItems());
        }

        return self::$items;
    }

    public static function byId(string $id): ?CheckableItem
    {
        return array_find(self::all(), static fn (CheckableItem $i): bool => $i->id === $id);
    }

    /**
     * `Health` compares against `JsonData::*->value`, i.e. repo-relative paths, while this
     * inventory stores the absolute form the `JsonData` and `RomanMissal` accessors return.
     * Normalise here so neither caller has to know which representation it is holding.
     */
    public static function byPath(string $path): ?CheckableItem
    {
        $needle = str_starts_with($path, Router::$apiFilePath)
            ? $path
            : Router::$apiFilePath . ltrim($path, '/');
        $needle = rtrim($needle, '/');

        return array_find(
            self::all(),
            static fn (CheckableItem $i): bool => rtrim($i->path, '/') === $needle
        );
    }

    /**
     * The Roman sanctorale, derived from the missal registry rather than restated.
     *
     * `getSanctoraleFileName()` returns false for the editions that have no sanctorale file on
     * disk, which is exactly how the five that do were picked in the old hand-written table. A new
     * edition with a sanctorale file joins the inventory with no edit here.
     *
     * @return list<CheckableItem>
     */
    private static function derivedRomanSanctorale(): array
    {
        $items = [];
        foreach (RomanMissal::getMissalIds() as $missalId) {
            $file = RomanMissal::getSanctoraleFileName($missalId);
            if (false === $file) {
                continue;
            }

            $name = RomanMissal::getName($missalId);
            // 'VA' in produceMetadata() means "not nation-specific"; this inventory says so with null.
            $region = str_starts_with($missalId, 'EDITIO_TYPICA_') ? null : explode('_', $missalId)[0];

            $items[] = new CheckableItem(
                "sanctorale:roman:{$missalId}",
                'file',
                Rite::ROMAN,
                $region,
                $name,
                LitSchema::PROPRIUMDESANCTIS,
                self::STEPS,
                $file
            );

            $i18n = RomanMissal::getSanctoraleI18nFilePath($missalId);
            if (false !== $i18n) {
                $items[] = new CheckableItem(
                    "sanctorale:roman:{$missalId}:i18n",
                    'folder',
                    Rite::ROMAN,
                    $region,
                    "{$name} translations",
                    LitSchema::I18N,
                    self::STEPS,
                    rtrim($i18n, '/')
                );
            }
        }

        return $items;
    }

    /** @return list<CheckableItem> */
    private static function explicitItems(): array
    {
        return [
            new CheckableItem(
                'temporale:roman',
                'file',
                Rite::ROMAN,
                null,
                'Roman Proprium de Tempore',
                LitSchema::PROPRIUMDETEMPORE,
                self::STEPS,
                JsonData::TEMPORALE_FILE->path()
            ),
            new CheckableItem(
                'temporale:roman:i18n',
                'folder',
                Rite::ROMAN,
                null,
                'Roman Proprium de Tempore translations',
                LitSchema::I18N,
                self::STEPS,
                JsonData::TEMPORALE_I18N_FOLDER->path()
            ),
            new CheckableItem(
                'decrees:roman',
                'file',
                Rite::ROMAN,
                null,
                'Memorials from Decrees',
                LitSchema::DECREES_SRC,
                self::STEPS,
                JsonData::DECREES_FILE->path()
            ),
            new CheckableItem(
                'decrees:roman:i18n',
                'folder',
                Rite::ROMAN,
                null,
                'Memorials from Decrees translations',
                LitSchema::I18N,
                self::STEPS,
                JsonData::DECREES_I18N_FOLDER->path()
            ),
            new CheckableItem(
                'temporale:ambrosian',
                'file',
                Rite::AMBROSIAN,
                null,
                'Ambrosian Proprium de Tempore',
                LitSchema::PROPRIUMDETEMPORE,
                self::STEPS,
                JsonData::AMBROSIAN_TEMPORALE_FILE->path()
            ),
            new CheckableItem(
                'temporale:ambrosian:i18n',
                'folder',
                Rite::AMBROSIAN,
                null,
                'Ambrosian Proprium de Tempore translations',
                LitSchema::I18N,
                self::STEPS,
                JsonData::AMBROSIAN_TEMPORALE_I18N_FOLDER->path()
            ),
            new CheckableItem(
                'sanctorale:ambrosian',
                'file',
                Rite::AMBROSIAN,
                null,
                'Ambrosian Proprium de Sanctis',
                LitSchema::PROPRIUMDESANCTIS,
                self::STEPS,
                JsonData::AMBROSIAN_SANCTORALE_FILE->path()
            ),
            new CheckableItem(
                'sanctorale:ambrosian:i18n',
                'folder',
                Rite::AMBROSIAN,
                null,
                'Ambrosian Proprium de Sanctis translations',
                LitSchema::I18N,
                self::STEPS,
                JsonData::AMBROSIAN_SANCTORALE_I18N_FOLDER->path()
            )
        ];
    }
}
```

- [ ] **Step 6: Know which path representation each side holds**

This is settled, not something to discover: `RomanMissal::getSanctoraleFileName()` and every `JsonData::*->path()`
return **absolute** paths prefixed with `Router::$apiFilePath`, so that is what the inventory stores.
`Health::getPathToSchemaFile()` compares against `JsonData::*->value`, which is **repo-relative and unprefixed**. That
is why `byPath()` normalises its argument rather than either side converting.

The test's `legacyFileTable()` therefore quotes the old table verbatim, in the unprefixed form `Health` actually uses,
and `byPath()` is expected to resolve it. If that assertion fails, the bug is in `byPath()`'s normalisation — do not
"fix" it by prefixing the oracle, which would stop the test proving that `Health`'s own inputs still resolve.

Note also that `getSanctoraleI18nFilePath()` returns a path with a **trailing slash**, which is why the item stores
`rtrim($i18n, '/')` — the drift test compares against `<dir>/i18n` with no trailing slash.

- [ ] **Step 7: Run the test to verify it passes**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LiturgicalCalendarAPI-inventory
vendor/bin/phpunit phpunit_tests/Models/ValidationsPath/CheckableInventoryTest.php
```

Expected: OK, 7 tests.

- [ ] **Step 8: Lint and analyse**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LiturgicalCalendarAPI-inventory
composer lint
composer analyse
```

Expected: clean. If `composer analyse` complains that some path "is not a file", the PHPStan result cache is shared
across worktrees and stale: `rm -rf /tmp/phpstan` and retry.

- [ ] **Step 9: Commit**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LiturgicalCalendarAPI-inventory
git add src/Models/ValidationsPath phpunit_tests/Models/ValidationsPath
git commit -m "feat(validations): one inventory of the source data we can check

The layout was written down in several places that had to be edited in
lockstep — each client's path constants, Health's schema table, the .vscode
globs — and nothing failed loudly when they diverged. That is #737/UTI#38,
#795 and #800.

This is the one list. Half of it is not written down at all: RomanMissal
already registers every edition and already knows which have a sanctorale
file, so those ten items derive, and a new edition joins with no edit here.
The other eight have dedicated JsonData constants.

CheckableItem drops its path in jsonSerialize() rather than leaving that to
the handler. Keeping filesystem paths off the wire is the feature, so it
belongs in the type where a second caller cannot forget it.

region is null for not-nation-specific rather than produceMetadata()'s
'VA': that is a nation code which only reads as universal because the
General Roman calendar is served under nation VA, and it would be false on
the Ambrosian items.

Refs #806

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## Task 2: The drift test

**Files:**

- Create: `phpunit_tests/Models/ValidationsPath/InventoryDriftTest.php`

**Interfaces:**

- Consumes: `CheckableInventory::all()` returning `list<CheckableItem>`, each with a `path` property.

- [ ] **Step 1: Write the test**

This is the test that earns the whole feature: it fails when source data exists on disk that no inventory entry
covers. It would have failed the moment the Ambrosian data landed without a match-table entry, which is #800.

Create `phpunit_tests/Models/ValidationsPath/InventoryDriftTest.php`:

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Models\ValidationsPath;

use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Models\ValidationsPath\CheckableInventory;
use LiturgicalCalendar\Api\Models\ValidationsPath\CheckableItem;
use LiturgicalCalendar\Api\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Source data that exists on disk but appears in no inventory entry.
 *
 * This is the failure #800 was: the Ambrosian temporale was present and unvalidatable, because
 * nothing listed it. Divergence between the data and the list that describes it was silent, and
 * stayed silent until someone went looking. Here it is a red test.
 *
 * Only this direction is asserted. An inventory entry with nothing on disk is deliberately NOT a
 * failure here: that is what the `exists` step reports at check time, and asserting it would stop
 * the inventory from advertising data a given deployment is missing — reintroducing the same
 * blindness from the other side.
 */
#[CoversClass(CheckableInventory::class)]
final class InventoryDriftTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        Router::$apiFilePath = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR;
    }

    /** @return list<string> every path the inventory claims to cover */
    private static function coveredPaths(): array
    {
        return array_map(
            static fn (CheckableItem $i): string => rtrim($i->path, '/'),
            CheckableInventory::all()
        );
    }

    public function testEveryMissalDataFileAndI18nFolderIsCovered(): void
    {
        $covered = self::coveredPaths();
        $root    = rtrim(JsonData::SOURCEDATA_FOLDER->path(), '/');

        $missalDirs = glob($root . '/rite/*/missals/*', GLOB_ONLYDIR);
        self::assertNotEmpty($missalDirs, 'no missal directories found — is the glob root right?');

        foreach ($missalDirs as $dir) {
            foreach (glob($dir . '/*.json') ?: [] as $file) {
                self::assertContains(
                    $file,
                    $covered,
                    "source data with no inventory entry: {$file} — add it to CheckableInventory"
                );
            }
            if (is_dir($dir . '/i18n')) {
                self::assertContains(
                    $dir . '/i18n',
                    $covered,
                    "i18n folder with no inventory entry: {$dir}/i18n — add it to CheckableInventory"
                );
            }
        }
    }

    public function testEveryDecreesDataFileAndI18nFolderIsCovered(): void
    {
        $covered = self::coveredPaths();
        $root    = rtrim(JsonData::SOURCEDATA_FOLDER->path(), '/');

        $decreeDirs = glob($root . '/rite/*/decrees', GLOB_ONLYDIR);
        self::assertNotEmpty($decreeDirs, 'no decrees directories found — is the glob root right?');

        foreach ($decreeDirs as $dir) {
            foreach (glob($dir . '/*.json') ?: [] as $file) {
                self::assertContains($file, $covered, "source data with no inventory entry: {$file}");
            }
            if (is_dir($dir . '/i18n')) {
                self::assertContains($dir . '/i18n', $covered, "i18n folder with no inventory entry: {$dir}/i18n");
            }
        }
    }
}
```

- [ ] **Step 2: Run it**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LiturgicalCalendarAPI-inventory
vendor/bin/phpunit phpunit_tests/Models/ValidationsPath/InventoryDriftTest.php
```

Expected: OK, 2 tests. If it fails, the inventory is genuinely missing something the repo ships — **add the entry to
`CheckableInventory`**, do not weaken the glob. If it fails because a lectionary or other subdirectory is being picked
up that is not a checkable propriumdesanctis/propriumdetempore file, narrow the `*.json` glob to exclude it and say so
in your report, naming the file you excluded and why.

- [ ] **Step 3: Prove the test can fail**

Temporarily delete the `'sanctorale:ambrosian'` entry from `CheckableInventory::explicitItems()`, re-run, and confirm
`testEveryMissalDataFileAndI18nFolderIsCovered` fails naming the Ambrosian sanctorale file. Restore the entry. Record
the observed failure message. A drift test that cannot fail is worse than no drift test, because it reads like cover.

- [ ] **Step 4: Commit**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LiturgicalCalendarAPI-inventory
git add phpunit_tests/Models/ValidationsPath/InventoryDriftTest.php
git commit -m "test(validations): fail when source data has no inventory entry

#800 was data on disk that no client could validate, because nothing listed
it, and the divergence was silent until someone went looking. This makes
that a red test.

Only one direction is asserted. An entry with nothing on disk is not a
failure here — that is what the exists step reports at check time, and
asserting it would stop the inventory from advertising data a deployment is
missing, which is the same blindness from the other side.

Refs #806, #800

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## Task 3: The `GET /validations` endpoint

**Files:**

- Create: `src/Handlers/ValidationsHandler.php`
- Create: `jsondata/schemas/LitCalValidationsPath.json`
- Modify: `src/Enum/Route.php` (add a case after `MISSALS`)
- Modify: `src/Enum/LitSchema.php` (add a case after `SCHEMAS`)
- Modify: `src/Router.php` (add a `case 'validations'` beside `case 'schemas'`)
- Modify: `jsondata/schemas/openapi.json` (a `/validations` path entry)
- Test: `phpunit_tests/Handlers/ValidationsHandlerTest.php`
- Test: `phpunit_tests/Handlers/ReadonlyPathsResponseSchemaTest.php` (one added case)

**Interfaces:**

- Consumes: `CheckableInventory::all(): list<CheckableItem>` from Task 1; `CheckableItem::jsonSerialize()` which emits
  `id`, `kind`, `rite`, `region`, `label`, `schema`, `steps` and never `path`.
- Produces: `Route::VALIDATIONS` (`'/validations'`), `LitSchema::VALIDATIONS` (`'/LitCalValidationsPath.json'`),
  and the `litcal_validations` response envelope.

- [ ] **Step 1: Write the failing handler test**

Create `phpunit_tests/Handlers/ValidationsHandlerTest.php`:

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Handlers\ValidationsHandler;
use LiturgicalCalendar\Api\Http\Exception\MethodNotAllowedException;
use LiturgicalCalendar\Api\Http\Exception\NotAcceptableException;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * `GET /validations` — what this API can be asked to check (#806 step A).
 *
 * The endpoint exists so clients stop hardcoding this repo's on-disk layout, so the assertion that
 * matters most is the negative one: no response may contain a filesystem path.
 */
#[CoversClass(ValidationsHandler::class)]
final class ValidationsHandlerTest extends AbstractHandlerTestCase
{
    public function testOptionsPreflightSucceeds(): void
    {
        $response = ( new ValidationsHandler() )->handle(
            $this->requestFor('OPTIONS', '/validations', [
                'Origin'                        => 'https://app.example.test',
                'Access-Control-Request-Method' => 'GET',
            ])
        );

        self::assertSame(204, $response->getStatusCode());
    }

    public function testGetReturnsTheInventoryEnvelope(): void
    {
        $response = ( new ValidationsHandler() )->handle($this->requestFor('GET', '/validations'));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));

        $body = $this->decodeJsonBody($response);
        self::assertArrayHasKey('litcal_validations', $body);
        self::assertCount(18, $body['litcal_validations']);
    }

    public function testEveryItemCarriesTheAdvertisedFields(): void
    {
        $body = $this->decodeJsonBody(
            ( new ValidationsHandler() )->handle($this->requestFor('GET', '/validations'))
        );

        foreach ($body['litcal_validations'] as $item) {
            foreach (['id', 'kind', 'rite', 'region', 'label', 'schema', 'steps'] as $key) {
                self::assertArrayHasKey($key, $item);
            }
            self::assertContains($item['kind'], ['file', 'folder']);
            self::assertContains($item['rite'], ['roman', 'ambrosian']);
            self::assertTrue($item['region'] === null || preg_match('/^[A-Z]{2}$/', $item['region']) === 1);
            self::assertStringEndsWith('.json', $item['schema']);
            self::assertSame(['exists', 'parses', 'validates'], $item['steps']);
        }
    }

    /** The reason the endpoint exists: no client should ever see a path again. */
    public function testTheResponseLeaksNoFilesystemPath(): void
    {
        $raw = (string) ( new ValidationsHandler() )->handle($this->requestFor('GET', '/validations'))->getBody();

        self::assertStringNotContainsString('jsondata', $raw);
        self::assertStringNotContainsString('sourcedata', $raw);
        self::assertStringNotContainsString('"path"', $raw);
    }

    public function testAnUnacceptableAcceptHeaderIsRejected(): void
    {
        $this->expectException(NotAcceptableException::class);
        ( new ValidationsHandler() )->handle(
            $this->requestFor('GET', '/validations', ['Accept' => 'image/png'])
        );
    }

    public function testANonGetVerbIsRejected(): void
    {
        $this->expectException(MethodNotAllowedException::class);
        ( new ValidationsHandler() )->handle($this->requestFor('DELETE', '/validations'));
    }

    public function testAPathParameterIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        ( new ValidationsHandler(['roman']) )->handle($this->requestFor('GET', '/validations/roman'));
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LiturgicalCalendarAPI-inventory
vendor/bin/phpunit phpunit_tests/Handlers/ValidationsHandlerTest.php
```

Expected: error — `Class "LiturgicalCalendar\Api\Handlers\ValidationsHandler" not found`.

If instead every test is *skipped*, the base class needs `JWT_SECRET` (32+ chars) in the environment; set it in
`.env.local` per CLAUDE.md before continuing, because skipped tests are not evidence.

- [ ] **Step 3: Add the Route and LitSchema cases**

In `src/Enum/Route.php`, after `case MISSALS = '/missals';`:

```php
    case VALIDATIONS        = '/validations';
```

In `src/Enum/LitSchema.php`, after `case SCHEMAS = '/LitCalSchemasPath.json';`:

```php
    case VALIDATIONS       = '/LitCalValidationsPath.json';
```

- [ ] **Step 4: Write the handler**

Create `src/Handlers/ValidationsHandler.php`:

```php
<?php

/**
 * Advertise the source data this API can validate.
 *
 * Clients used to hardcode this repo's on-disk layout and had to be edited in lockstep with every
 * change to it — see #806. They now read this list and send back an opaque id, so no filesystem
 * path crosses the wire.
 *
 * This endpoint deliberately does not touch the filesystem. Advertising is not verification:
 * `exists` is the first check, not a precondition for being listed, so a missing file surfaces as
 * a failed check rather than as a silent absence from the list.
 *
 * @author    John Romano D'Orazio <priest@johnromanodorazio.com>
 * @license   https://www.apache.org/licenses/LICENSE-2.0.txt Apache License 2.0
 * @link      https://litcal.johnromanodorazio.com
 */

namespace LiturgicalCalendar\Api\Handlers;

use LiturgicalCalendar\Api\Http\Enum\AcceptabilityLevel;
use LiturgicalCalendar\Api\Http\Enum\RequestMethod;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use LiturgicalCalendar\Api\Models\ValidationsPath\CheckableInventory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class ValidationsHandler extends AbstractHandler
{
    /** @param string[] $requestPathParams */
    public function __construct(array $requestPathParams = [])
    {
        parent::__construct($requestPathParams);
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $response = static::initResponse($request);

        $method = RequestMethod::from($request->getMethod());

        if ($method === RequestMethod::OPTIONS) {
            return $this->handlePreflightRequest($request, $response);
        }

        $response = $this->setAccessControlAllowOriginHeader($request, $response);
        $this->validateRequestMethod($request);

        $mime     = $this->validateAcceptHeader($request, AcceptabilityLevel::LAX);
        $response = $response->withHeader('Content-Type', $mime);

        $pathParamCount = count($this->requestPathParams);
        if ($pathParamCount > 0) {
            throw new ValidationException(
                'Invalid number of path parameters, expected 0, received ' . $pathParamCount
            );
        }

        $payload                      = new \stdClass();
        $payload->litcal_validations  = CheckableInventory::all();

        return $this->encodeResponseBody($response, $payload);
    }
}
```

- [ ] **Step 5: Wire the route**

In `src/Router.php`, beside `case 'schemas':`, add:

```php
            case 'validations':
                $validationsHandler = new ValidationsHandler($requestPathParts);
                $validationsHandler->setAllowedRequestMethods([RequestMethod::GET]);
                $this->handler = $validationsHandler;
                break;
```

Add `use LiturgicalCalendar\Api\Handlers\ValidationsHandler;` to the file's imports, keeping them alphabetical with
the neighbouring handler imports.

- [ ] **Step 6: Run the handler test to verify it passes**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LiturgicalCalendarAPI-inventory
vendor/bin/phpunit phpunit_tests/Handlers/ValidationsHandlerTest.php
```

Expected: OK, 7 tests.

- [ ] **Step 7: Write the response schema**

Create `jsondata/schemas/LitCalValidationsPath.json`:

```json
{
    "$schema": "https://json-schema.org/draft/2020-12/schema",
    "$id": "https://litcal.johnromanodorazio.com/api/dev/schemas/LitCalValidationsPath.json",
    "title": "Liturgical Calendar Validations Path",
    "description": "The source data this API can be asked to validate. Clients iterate this list and send back an item's opaque id, so no filesystem path crosses the wire.",
    "type": "object",
    "additionalProperties": false,
    "required": ["litcal_validations"],
    "properties": {
        "litcal_validations": {
            "type": "array",
            "minItems": 1,
            "items": {
                "type": "object",
                "additionalProperties": false,
                "required": ["id", "kind", "rite", "region", "label", "schema", "steps"],
                "properties": {
                    "id": {
                        "type": "string",
                        "minLength": 1,
                        "description": "Opaque identifier a client sends back to request this check."
                    },
                    "kind": {
                        "type": "string",
                        "enum": ["file", "folder"]
                    },
                    "rite": {
                        "type": "string",
                        "enum": ["roman", "ambrosian"]
                    },
                    "region": {
                        "type": ["string", "null"],
                        "pattern": "^[A-Z]{2}$",
                        "description": "Null when the item applies to its whole rite; an ISO nation code when it applies only to that nation's calendar."
                    },
                    "label": {
                        "type": "string",
                        "minLength": 1
                    },
                    "schema": {
                        "type": "string",
                        "pattern": "^[A-Za-z0-9]+\\.json$",
                        "description": "Bare filename of the JSON schema this item validates against, resolvable under /schemas."
                    },
                    "steps": {
                        "type": "array",
                        "minItems": 1,
                        "items": {
                            "type": "string",
                            "enum": ["exists", "parses", "validates"]
                        }
                    }
                }
            }
        }
    }
}
```

- [ ] **Step 8: Add the response-schema test case**

In `phpunit_tests/Handlers/ReadonlyPathsResponseSchemaTest.php`, add the import
`use LiturgicalCalendar\Api\Handlers\ValidationsHandler;` and this method:

```php
    public function testValidationsResponseValidatesAgainstValidationsPathSchema(): void
    {
        $this->validateHandlerResponse(new ValidationsHandler(), '/validations', LitSchema::VALIDATIONS);
    }
```

Run it:

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LiturgicalCalendarAPI-inventory
vendor/bin/phpunit phpunit_tests/Handlers/ReadonlyPathsResponseSchemaTest.php
```

Expected: OK, with the new case passing.

- [ ] **Step 9: Add the OpenAPI path entry**

In `jsondata/schemas/openapi.json`, add this entry to `paths`, placed after the `/schemas` entries so the file keeps
its existing grouping:

```json
    "/validations": {
      "get": {
        "tags": [
          "Validations"
        ],
        "security": [
          {}
        ],
        "summary": "Retrieve the source data this API can be asked to validate",
        "description": "Clients iterate this list and send back an item's opaque id, so no filesystem path crosses the wire. Advertising is not verification: an item appearing here does not assert that the underlying data is present, only that it is checkable. Filtering by rite and region is done by the client.",
        "operationId": "validationsGET",
        "responses": {
          "200": {
            "description": "the source data this API can validate",
            "content": {
              "application/json": {
                "schema": {
                  "$ref": "./LitCalValidationsPath.json"
                },
                "example": {
                  "litcal_validations": [
                    {
                      "id": "temporale:roman",
                      "kind": "file",
                      "rite": "roman",
                      "region": null,
                      "label": "Roman Proprium de Tempore",
                      "schema": "PropriumDeTempore.json",
                      "steps": [
                        "exists",
                        "parses",
                        "validates"
                      ]
                    },
                    {
                      "id": "sanctorale:roman:US_2011:i18n",
                      "kind": "folder",
                      "rite": "roman",
                      "region": "US",
                      "label": "Roman Missal, USA edition (2011) translations",
                      "schema": "LitCalTranslation.json",
                      "steps": [
                        "exists",
                        "parses",
                        "validates"
                      ]
                    }
                  ]
                }
              }
            }
          },
          "406": {
            "description": "the requested Accept header is not supported by this endpoint"
          }
        }
      }
    },
```

The `label` in that second example must match what `RomanMissal::getName('US_2011')` actually returns — check it and
correct the example if it differs, rather than leaving the schema documenting a label the API does not emit:

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LiturgicalCalendarAPI-inventory
php -r 'require "vendor/autoload.php"; echo LiturgicalCalendar\Api\Enum\RomanMissal::getName("US_2011"), "\n";'
```

Then lint the schema:

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LiturgicalCalendarAPI-inventory
composer lint:openapi
```

Expected: clean. Redocly may report errors resolving `$ref`s to external schema files; that is pre-existing on this
document and not introduced here — confirm the same output appears on the unmodified file (`git stash`, re-run,
`git stash pop`) before treating any of it as yours.

- [ ] **Step 10: Lint, analyse, and run the wider suite**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LiturgicalCalendarAPI-inventory
composer lint
composer analyse
composer test:quick
```

Use the composer script for the suite, never a bare `phpunit --exclude-group` — a CLI `--exclude-group` overrides the
XML config and un-fences the golden-master-generate group, which silently rewrites the fixtures it is checked against.

Expected: no new failures. `Routes/*` tests target the main checkout's server on `:8000` and their result says nothing
about this branch; judge on the new tests plus the absence of *new* failures elsewhere, checking anything ambiguous
against the base commit rather than assuming.

- [ ] **Step 11: Commit**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LiturgicalCalendarAPI-inventory
git add src/Handlers/ValidationsHandler.php src/Enum/Route.php src/Enum/LitSchema.php src/Router.php \
        jsondata/schemas/LitCalValidationsPath.json jsondata/schemas/openapi.json \
        phpunit_tests/Handlers/ValidationsHandlerTest.php phpunit_tests/Handlers/ReadonlyPathsResponseSchemaTest.php
git commit -m "feat(validations): serve the checkable inventory at GET /validations

Clients hardcoded this repo's on-disk layout and had to be edited in
lockstep with every change to it. They can now read the list and send back
an opaque id.

The endpoint does not stat the filesystem. Advertising is not verification:
exists is the first check, not a precondition for being listed, so a
missing file surfaces as a failed check rather than as a silent absence
from the list — which is how #800 stayed invisible.

No query parameters. Both consumers fetch once at load and filter in the
browser, so changing rite or calendar re-filters an array instead of making
another request.

Refs #806

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## Task 4: `Health` delegates to the inventory

**Files:**

- Modify: `src/Health.php` — `getPathToSchemaFile()` and the `sourceDataCheck` branch of `retrieveSchemaForCategory()`
- Test: `phpunit_tests/HealthSchemaMappingTest.php` and `phpunit_tests/HealthSchemaCategoryTest.php` (existing; must
  keep passing unchanged)

**Interfaces:**

- Consumes: `CheckableInventory::byPath(string $path): ?CheckableItem` and `::byId(string $id): ?CheckableItem` from
  Task 1; `CheckableItem::$schema` is a `LitSchema`, so callers need `->schema->path()` to get the filesystem path
  these two methods have always returned.

- [ ] **Step 1: Read the existing tests first**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LiturgicalCalendarAPI-inventory
vendor/bin/phpunit phpunit_tests/HealthSchemaMappingTest.php phpunit_tests/HealthSchemaCategoryTest.php
```

Record the passing output. These two suites are the safety net for this refactor: they must pass **unchanged**
afterwards. Do not edit them to accommodate the new code — if one fails, the refactor is wrong.

- [ ] **Step 2: Delegate `getPathToSchemaFile()` for source-data files only**

`getPathToSchemaFile()` currently mixes two unrelated kinds in one `match`: source-data **file** paths, and API
**route** paths (`Route::CALENDARS->path()`, `Route::DECREES->path()` and so on). Only the first kind is in the
inventory. Replace the eight source-data arms with an inventory lookup and keep the route arms exactly as they are:

```php
    private static function getPathToSchemaFile(string $dataFile): ?string
    {
        // Source-data files come from the one inventory (#806 step A); the arms below are API
        // routes, which are a different kind of thing and stay here.
        $item = CheckableInventory::byPath($dataFile);
        if (null !== $item) {
            return $item->schema->path();
        }

        return match ($dataFile) {
            Route::CALENDARS->path() => LitSchema::METADATA->path(),
            Route::DECREES->path()   => LitSchema::DECREES->path(),
            Route::EVENTS->path()    => LitSchema::EVENTS->path(),
            Route::TESTS->path()     => LitSchema::TESTS->path(),
            Route::EASTER->path()    => LitSchema::EASTER->path(),
            Route::MISSALS->path()   => LitSchema::MISSALS->path(),
            Route::DATA->path()      => LitSchema::DATA->path(),
            Route::SCHEMAS->path()   => LitSchema::SCHEMAS->path(),
            default                  => null
        };
    }
```

Add `use LiturgicalCalendar\Api\Models\ValidationsPath\CheckableInventory;` to `Health.php`'s imports.

The inventory stores whatever representation Task 1 established — note its report before writing this. If `Health`
passes an unprefixed repo-relative path while the inventory stores a prefixed one, normalise **at the lookup here**,
not by changing what the inventory stores, and say so in a comment.

- [ ] **Step 3: Run the mapping tests**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LiturgicalCalendarAPI-inventory
vendor/bin/phpunit phpunit_tests/HealthSchemaMappingTest.php
```

Expected: the same passing output as Step 1, unchanged.

- [ ] **Step 4: Delegate the `sourceDataCheck` slug branch for inventory-owned slugs**

In `retrieveSchemaForCategory()`, the `sourceDataCheck` case resolves a `validate` slug through eight anchored
`preg_match` calls. Four of those name things the inventory owns — `memorials-from-decrees`, `-i18n$`,
`proprium-de-sanctis-…`, `proprium-de-tempore`. The other four (`wider-region-…`, `national-calendar-…`,
`diocesan-calendar-…`, `tests-…`) are per-calendar data, which is out of scope and stays.

Add an inventory lookup **before** the pattern list, mapping the client's legacy slugs onto inventory ids:

```php
            case 'sourceDataCheck':
                // Legacy slugs from the runner pages, mapped onto inventory ids. #806 step A gives
                // every item an id; until the clients send those ids (UnitTestInterface#42), the
                // old vocabulary keeps working through this table.
                $legacySlugToId = [
                    'memorials-from-decrees'      => 'decrees:roman',
                    'memorials-from-decrees-i18n' => 'decrees:roman:i18n',
                    'proprium-de-tempore'         => 'temporale:roman',
                    'proprium-de-tempore-i18n'    => 'temporale:roman:i18n'
                ];
                $item = CheckableInventory::byId($legacySlugToId[$dataPath] ?? $dataPath);
                if (null !== $item) {
                    return $item->schema->path();
                }

                // …the existing preg_match list continues here, unchanged…
```

Leave every existing `preg_match` in place beneath it. This is deliberately additive: the inventory answers what it
owns, the patterns answer the rest, and no client changes behaviour. Removing the now-shadowed patterns is
UnitTestInterface#42's business, once clients send ids.

- [ ] **Step 5: Run the category tests**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LiturgicalCalendarAPI-inventory
vendor/bin/phpunit phpunit_tests/HealthSchemaCategoryTest.php phpunit_tests/HealthSchemaMappingTest.php
```

Expected: the same passing output as Step 1, unchanged. If a slug now resolves to a *different* schema than before,
stop — that is a behaviour change, not a refactor, and it means a legacy slug is mapped to the wrong inventory id.

- [ ] **Step 6: Run the full local suite**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LiturgicalCalendarAPI-inventory
composer lint
composer analyse
composer test:quick
```

Expected: clean lint and analysis, no new failures.

- [ ] **Step 7: Commit**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LiturgicalCalendarAPI-inventory
git add src/Health.php
git commit -m "refactor(health): resolve source-data schemas from the one inventory

Health resolved the same files two ways: getPathToSchemaFile() matched on a
path, and a separate branch matched the same files on a slug, with two
different vocabularies depending on which runner page asked. That is
ambiguity 4 in #806.

Both now consult CheckableInventory first. Neither loses a capability: the
route arms of the path table are API endpoints rather than source data and
stay put, and the per-calendar patterns in the slug branch — wider region,
national, diocesan, tests — are out of this scope and stay too.

Additive on purpose. The legacy slugs keep working through a small mapping
table, so no client changes behaviour today; retiring them belongs with
UnitTestInterface#42, once clients send inventory ids.

HealthSchemaMappingTest and HealthSchemaCategoryTest pass unchanged, which
is the point: this is meant to be a refactor, and they are the proof.

Refs #806

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## Task 5: Publish

**Files:** none — repository metadata only.

- [ ] **Step 1: Push**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LiturgicalCalendarAPI-inventory
git push -u origin feat/806-checkable-inventory
```

- [ ] **Step 2: Open the PR**

Base `development`. The body must state: what the endpoint advertises and what it deliberately omits (per-calendar
items and the API's own endpoints, with the reason); that half the inventory derives from `RomanMissal` rather than
being restated; that the endpoint never stats the filesystem, and why that is deliberate rather than an oversight; that
`Health` sheds two lookups and its existing tests pass unchanged; what the drift test guards and which direction it
deliberately does not assert; and the verification output from each task.

Note explicitly that this ships useful with no client change, and that the client half — filtering by rite and region —
is gated on UnitTestInterface#48 and #42, not on this PR.
