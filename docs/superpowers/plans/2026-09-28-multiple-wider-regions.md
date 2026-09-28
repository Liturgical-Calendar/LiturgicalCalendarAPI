# Multiple Wider Regions per National Calendar Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or
> superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a national calendar declare an ordered list of wider regions, assemble `/calendar` and `/events` from
each in order, publish the membership on `/calendars`, and keep OpenFGA `member_nation` tuples in step with the
published source files.

**Architecture:** `NationalMetadata::$wider_region` (string) becomes `$wider_regions` (list), reading the legacy string
during a transition. Two new units carry the logic: `WiderRegionLayers` loads a nation's regions as ordered, named
layers for both calendar handlers, and `WiderRegionMembershipSync` diffs a nation's region lists into outbox rows for
the `/data` write paths. `WiderRegionMembershipReconciler` applies the same diff against OpenFGA directly, for the
post-merge step and the seeder.

**Tech Stack:** PHP 8.4, PHPUnit 12, PHPStan level 10, phpcs (PSR-12 variant), JSON Schema (draft 7 via Swaggest),
OpenFGA, PostgreSQL outbox.

**Spec:** `docs/superpowers/specs/2026-09-28-multiple-wider-regions-design.md`

## Global Constraints

- Work only in the worktree `/home/johnrdorazio/development/LiturgicalCalendar/wt-1005-wider-regions`, branch
  `feat/1005-multiple-wider-regions`. Never `git checkout` or commit in the main checkout. Begin every shell command
  with `cd /home/johnrdorazio/development/LiturgicalCalendar/wt-1005-wider-regions &&` and confirm
  `git rev-parse --show-toplevel` prints that path before the first commit of each task.
- Never mutate `jsondata/` in a test. Tests that write source data use `ShadowProjectRootTrait`
  (`phpunit_tests/Support/ShadowProjectRootTrait.php`), with its setup in `setUpBeforeClass()` and guards that `throw`,
  never `self::fail()`.
- Region name shape, one rule everywhere: `^[A-Z][A-Za-z]*( [A-Z][A-Za-z]*)*$`.
- Application order: General Roman Calendar + missal propria → `wider_regions[0]` → … → `wider_regions[n]` → national.
- `member_nation` tuples are rite-qualified on both sides: `national_calendar:roman/{N}` `member_nation`
  `wider_region:roman/{R}`.
- Outbox idempotency key: `member_nation:{episode}:{write|delete}:wider_region:{R}:national_calendar:{N}`.
- Deprecated output rule (`/calendars` and `GET /data/nation/{nation}`): emit `wider_region` only when the nation
  declares exactly one region.
- Legacy input rule: `wider_region` alone is read as a one-element list (`""` as `[]`); both fields are accepted only
  when `wider_regions === [wider_region]`.
- Mark a test `#[Group('slow')]` only when you have measured a real runtime cost; never use a `@group` docblock.
- `composer test:quick`, `composer analyse`, `composer lint` must pass at the end of every task. Do not run two
  suites concurrently (they share one Postgres). Never pass a CLI `--exclude-group`: it un-fences the golden-master
  generator.
- A fresh worktree without `.env.local` skips every `Handlers/*` test and still exits 0. The worktree already has one;
  confirm a new handler test actually ran (not `S`) before treating it as green.
- Commit messages end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. Never use `--no-verify`.
- `openapi.json` canonical encoding is non-ASCII literal; after editing it, run `composer lint:jsondata` and
  `composer lint:openapi`.
- Markdown must pass `composer lint:md` (180-char lines, aligned tables).

## Review Focus

1. **A nation that declares no region (`[]`) or omits both fields.** `/calendar`, `/events` and `/calendars` must work
   and emit no wider-region layer and no "Could not find a `wider_region`" message. Pinned in Task 1 (model) and
   Task 4 (handler).
2. **Today's frontend round trip.** Load `GET /data/nation/IT`, then `PATCH` with only `wider_region: "Europe"`: must be
   accepted, stored as `wider_regions: ["Europe"]`, with a warning. Pinned in Task 6.
3. **A client echoing a GET response.** A `PATCH` carrying both `wider_region: "Europe"` and
   `wider_regions: ["Europe"]` must be accepted. Pinned in Task 1 and Task 6.
4. **Re-adding a region after removing it.** The second `WRITE_TUPLE` must not be swallowed by the outbox's
   idempotency (distinct episode token per write). Pinned in Task 7.
5. **A merged batch that deletes a national calendar.** The post-merge step must remove every `member_nation` tuple of
   that nation, reading the "after" list as `[]` from a `delete` row that has no content. Pinned in Task 9.

---

## File structure

| Path                                                             | Responsibility                                                     |
|------------------------------------------------------------------|--------------------------------------------------------------------|
| `src/Models/RegionalData/WiderRegionName.php` (new)              | The one region-name shape rule                                     |
| `src/Models/RegionalData/NationalData/NationalMetadata.php`      | `wider_regions` list, legacy read, agreement check                 |
| `src/Models/RegionalData/NationalData/NationalData.php`          | `hasWiderRegion()` fix                                             |
| `src/Models/Metadata/MetadataNationalCalendarItem.php`           | `/calendars` national item: list + deprecated single               |
| `src/Models/Metadata/MetadataWiderRegionItem.php`                | `/calendars` region item: member nations                           |
| `src/Services/CalendarMetadataProvider.php`                      | Computes each region's member nations                              |
| `src/Services/WiderRegionLayers.php` (new)                       | Loads a nation's regions as ordered, named layers                  |
| `src/Services/WiderRegionLayer.php` (new)                        | One layer: region, data, lectionary file                           |
| `src/Services/WiderRegionNaming.php` (new)                       | Strict (`/calendar`) or lenient (`/events`) naming                 |
| `src/Handlers/CalendarHandler.php`                               | Applies layers; region lectionaries before the nation's            |
| `src/Handlers/EventsHandler.php`                                 | Applies layers                                                     |
| `src/Handlers/RegionalDataHandler.php`                           | Validation, normalisation, warnings, GET compat, tuple rows        |
| `src/Services/WiderRegionMembershipSync.php` (new)               | Pure diff: lists → outbox rows / tuple triples                     |
| `src/Services/WiderRegionMembershipReconciler.php` (new)         | Applies the diff to OpenFGA directly                               |
| `src/Services/WiderRegionMembership.php`                         | `regionsOf()` reads the list                                       |
| `src/Services/WiderRegionMembershipSeeder.php`                   | Full reconcile from source files                                   |
| `scripts/seed-wider-region-membership.php`                       | CLI wrapper: dry run / `--apply`                                   |
| `src/Services/SourceData/MergePollRunner.php`                    | Post-merge membership sync                                         |
| `src/Services/SourceData/SourceDataPublisherFactory.php`         | Wires the reconciler into the runner                               |
| `src/Health.php`, `src/Handlers/Ops/HealthHandler.php`           | `wider_region_membership` block                                    |
| `jsondata/schemas/*.json`                                        | `WiderRegionName`, `wider_regions`, `/calendars` fields, OpenAPI   |
| `jsondata/sourcedata/rite/roman/calendars/nations/*/*.json`      | Migrated to `wider_regions`                                        |

---

### Task 1: `WiderRegionName` and the `wider_regions` model

**Files:**

- Create: `src/Models/RegionalData/WiderRegionName.php`
- Modify: `src/Models/RegionalData/NationalData/NationalMetadata.php`
- Modify: `src/Models/RegionalData/NationalData/NationalData.php` (`hasWiderRegion()`)
- Modify (interim reads): `src/Handlers/CalendarHandler.php` (`loadWiderRegionData()`, `applyCalendarI18nData()`),
  `src/Handlers/EventsHandler.php` (the `hasWiderRegion()` block), `src/Handlers/RegionalDataHandler.php`
  (`createNationalCalendar()`)
- Test: `phpunit_tests/Models/RegionalData/NationalMetadataTest.php` (new)

**Interfaces:**

- Produces: `WiderRegionName::PATTERN` (string regex), `WiderRegionName::isValid(string): bool`;
  `NationalMetadata::$wider_regions` (`list<string>`), `NationalMetadata::$usedLegacyWiderRegion` (`bool`);
  `NationalData::hasWiderRegion(): bool` (true iff the list is non-empty).

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Models\RegionalData;

use LiturgicalCalendar\Api\Models\RegionalData\NationalData\NationalMetadata;
use LiturgicalCalendar\Api\Models\RegionalData\WiderRegionName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(NationalMetadata::class)]
#[CoversClass(WiderRegionName::class)]
final class NationalMetadataTest extends TestCase
{
    /** @param array<string,mixed> $extra */
    private static function metadata(array $extra): NationalMetadata
    {
        $data = (object) array_merge(['nation' => 'SE', 'locales' => ['sv_SE'], 'missals' => []], $extra);

        return NationalMetadata::fromObject($data);
    }

    public function testAListIsReadInItsDeclaredOrder(): void
    {
        $metadata = self::metadata(['wider_regions' => ['Europe', 'Nordic']]);

        self::assertSame(['Europe', 'Nordic'], $metadata->wider_regions);
        self::assertFalse($metadata->usedLegacyWiderRegion);
    }

    public function testTheLegacyStringIsReadAsAOneElementList(): void
    {
        $metadata = self::metadata(['wider_region' => 'Europe']);

        self::assertSame(['Europe'], $metadata->wider_regions);
        self::assertTrue($metadata->usedLegacyWiderRegion);
    }

    public function testAnEmptyLegacyStringIsNoRegion(): void
    {
        self::assertSame([], self::metadata(['wider_region' => ''])->wider_regions);
    }

    public function testNeitherFieldIsNoRegion(): void
    {
        $metadata = self::metadata([]);

        self::assertSame([], $metadata->wider_regions);
        self::assertFalse($metadata->usedLegacyWiderRegion);
    }

    public function testBothFieldsAreAcceptedWhenTheyAgree(): void
    {
        $metadata = self::metadata(['wider_region' => 'Europe', 'wider_regions' => ['Europe']]);

        self::assertSame(['Europe'], $metadata->wider_regions);
        self::assertFalse($metadata->usedLegacyWiderRegion);
    }

    public function testBothFieldsAreRefusedWhenTheyDisagree(): void
    {
        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage('disagree');

        self::metadata(['wider_region' => 'Europe', 'wider_regions' => ['Europe', 'Nordic']]);
    }

    public function testDuplicatesAreRefused(): void
    {
        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage('more than once');

        self::metadata(['wider_regions' => ['Europe', 'Europe']]);
    }

    /** @return array<string, array{mixed}> */
    public static function badRegionProvider(): array
    {
        return [
            'lowercase'      => ['europe'],
            'trailing space' => ['Europe '],
            'digit'          => ['Region1'],
            'not a string'   => [42],
        ];
    }

    #[DataProvider('badRegionProvider')]
    public function testAnItemFailingTheNameShapeIsRefused(mixed $region): void
    {
        $this->expectException(\ValueError::class);

        self::metadata(['wider_regions' => [$region]]);
    }

    public function testMultiWordNamesMatchTheShape(): void
    {
        self::assertTrue(WiderRegionName::isValid('Middle East'));
        self::assertTrue(WiderRegionName::isValid('Central America'));
        self::assertFalse(WiderRegionName::isValid('Middle  East'));
    }
}
```

Also add to `phpunit_tests/Models/RegionalData/NationalMetadataTest.php` a test for the `NationalData` fix, using the
shipped HR file (it declares one region):

```php
    public function testHasWiderRegionIsFalseForAnEmptyList(): void
    {
        $hr                          = \LiturgicalCalendar\Api\Utilities::jsonFileToObject(
            dirname(__DIR__, 3) . '/jsondata/sourcedata/rite/roman/calendars/nations/HR/HR.json'
        );
        $hr->metadata->wider_regions = [];
        unset($hr->metadata->wider_region);

        self::assertFalse(\LiturgicalCalendar\Api\Models\RegionalData\NationalData\NationalData::fromObject($hr)->hasWiderRegion());
    }
```

Add `#[CoversClass(\LiturgicalCalendar\Api\Models\RegionalData\NationalData\NationalData::class)]` to the class.

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit phpunit_tests/Models/RegionalData/NationalMetadataTest.php`
Expected: errors, `Class "LiturgicalCalendar\Api\Models\RegionalData\WiderRegionName" not found` and
`Undefined property ... wider_regions`.

- [ ] **Step 3: Create `WiderRegionName`**

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Models\RegionalData;

/**
 * The one shape rule for a wider region name (#1005): one or more capitalised words separated by single spaces
 * (`Europe`, `Middle East`). Whether a region with that name exists is checked at runtime against
 * `wider_regions/{name}/{name}.json`, not here; the JSON schemas mirror this pattern as `WiderRegionName`.
 */
final class WiderRegionName
{
    public const PATTERN = '/^[A-Z][A-Za-z]*( [A-Z][A-Za-z]*)*$/';

    public static function isValid(string $name): bool
    {
        return 1 === preg_match(self::PATTERN, $name);
    }
}
```

- [ ] **Step 4: Rewrite `NationalMetadata`'s region handling**

In both phpstan types, replace `wider_region?:string,` with `wider_region?:string, wider_regions?:string[],`.

Replace the property `public readonly ?string $wider_region;` with:

```php
    /** @var list<string> The wider regions, most general first; the order is the order their layers apply. */
    public readonly array $wider_regions;

    /** Whether the regions were read from the deprecated `wider_region` string (for a deprecation warning). */
    public readonly bool $usedLegacyWiderRegion;
```

Change the constructor signature and region validation. Replace the parameter `?string $wider_region` with
`array $wider_regions` and add a trailing `bool $usedLegacyWiderRegion = false`. Replace the
`is_string($wider_region) && 1 !== preg_match(...)` block with:

```php
        foreach ($wider_regions as $region) {
            if (false === is_string($region) || false === WiderRegionName::isValid($region)) {
                throw new \ValueError('`metadata.wider_regions` must be a list of wider region names such as `Europe` or `Middle East`');
            }
        }
        if (count(array_unique($wider_regions)) !== count($wider_regions)) {
            throw new \ValueError('`metadata.wider_regions` must not name a wider region more than once');
        }
```

and the assignments `$this->wider_region = $wider_region;` with:

```php
        $this->wider_regions         = array_values($wider_regions);
        $this->usedLegacyWiderRegion = $usedLegacyWiderRegion;
```

Update the constructor docblock: `@param list<string> $wider_regions Wider region names, most general first.` and
`@param bool $usedLegacyWiderRegion Whether they came from the deprecated string field.`

Add a private static reader used by both factories:

```php
    /**
     * Resolves the two spellings of a nation's wider regions (#1005).
     *
     * `wider_regions` is the list; the deprecated `wider_region` string is read as a one-element list (`""` as none).
     * Both may be present only when they agree, which is what a client echoing a `GET /data/nation/{nation}` response
     * sends during the transition.
     *
     * @return array{0: list<string>, 1: bool} The regions, and whether the deprecated string was the source.
     * @throws \ValueError When a field has the wrong type, or the two fields disagree.
     */
    private static function readWiderRegions(mixed $list, mixed $legacy): array
    {
        if (null !== $legacy && false === is_string($legacy)) {
            throw new \ValueError('`metadata.wider_region` (deprecated) must be a string; send `metadata.wider_regions` instead');
        }
        if (null !== $list && false === is_array($list)) {
            throw new \ValueError('`metadata.wider_regions` must be a list of wider region names');
        }

        if (null === $list) {
            return [null === $legacy || '' === $legacy ? [] : [$legacy], null !== $legacy];
        }

        $list = array_values($list);
        if (null !== $legacy && $list !== ( '' === $legacy ? [] : [$legacy] )) {
            throw new \ValueError('`metadata.wider_region` (deprecated) and `metadata.wider_regions` disagree; send `metadata.wider_regions` alone');
        }

        /** @var list<string> $list validated item by item in the constructor */
        return [$list, false];
    }
```

In `fromArrayInternal()`, replace the `isset($data['wider_region']) ...` argument with a call and pass both results:

```php
        [$regions, $legacy] = self::readWiderRegions($data['wider_regions'] ?? null, $data['wider_region'] ?? null);

        return new static(
            $data['nation'],
            $data['locales'],
            $regions,
            isset($data['missals']) ? $data['missals'] : [],
            $legacy
        );
```

and in `fromObjectInternal()` the same with `$data->wider_regions ?? null` and `$data->wider_region ?? null`.
Update both factories' docblocks: `wider_regions (string[])` and `wider_region (string, deprecated)`.

- [ ] **Step 5: Fix `hasWiderRegion()`**

In `NationalData.php`:

```php
    /**
     * Whether the national calendar declares at least one wider region.
     */
    public function hasWiderRegion(): bool
    {
        return $this->metadata->wider_regions !== [];
    }
```

- [ ] **Step 6: Interim reads of the first region**

Until Tasks 4, 5 and 7 replace them, every reader of `->metadata->wider_region` on a `NationalMetadata` reads the first
region. Find them with `grep -n "metadata->wider_region\b" src/Handlers/*.php`. Expected sites: `CalendarHandler.php`
(`loadWiderRegionData()` and `applyCalendarI18nData()`), `EventsHandler.php` (two, in the `hasWiderRegion()` block),
`RegionalDataHandler.php` (`createNationalCalendar()`, `$widerRegion = ...`). Replace each
`->metadata->wider_region` with `->metadata->wider_regions[0]`, except in `createNationalCalendar()`, where the line
becomes:

```php
        $widerRegion = $payload->metadata->wider_regions[0] ?? '';
```

All three handlers already guard these reads with `hasWiderRegion()` or a null check, which is now a non-empty check.

- [ ] **Step 7: Run the new tests and the suite**

Run: `vendor/bin/phpunit phpunit_tests/Models/RegionalData/NationalMetadataTest.php`
Expected: PASS (all tests).
Run: `composer test:quick && composer analyse && composer lint`
Expected: all pass. The shipped national files still use the legacy string, which the model reads as a list, so the
golden masters are unchanged.

- [ ] **Step 8: Commit**

```bash
git add src/Models/RegionalData/WiderRegionName.php src/Models/RegionalData/NationalData/ src/Handlers/ \
  phpunit_tests/Models/RegionalData/NationalMetadataTest.php
git commit -m "feat(model): a national calendar declares a list of wider regions (#1005)"
```

---

### Task 2: Schemas, source-data migration, and `regionsOf()`

**Files:**

- Modify: `jsondata/schemas/CommonDef.json` (`WiderRegionNames` → `WiderRegionName`)
- Modify: `jsondata/schemas/NationalCalendar.json` (`NationalCalendarMetadata`)
- Modify: `jsondata/schemas/WiderRegionCalendar.json` (`CalendarMetadata.wider_region`)
- Modify: every `"$ref": "./CommonDef.json#/definitions/WiderRegionNames"` in `jsondata/schemas/*.json` (16 in
  `openapi.json`, 3 in `LitCalMetadata.json`, 1 in `NationalCalendar.json`)
- Modify: `jsondata/sourcedata/rite/roman/calendars/nations/{CA,HR,IT,NL,US}/*.json`
- Modify: `src/Services/WiderRegionMembership.php` (`regionsOf()`)
- Test: `phpunit_tests/Schemas/PayloadValidationTest.php`, `phpunit_tests/Services/WiderRegionMembershipTest.php`

**Interfaces:**

- Consumes: `WiderRegionName::PATTERN` (Task 1): the schema pattern is its body without delimiters.
- Produces: schema definition `CommonDef.json#/definitions/WiderRegionName`; source files carrying `wider_regions`.

- [ ] **Step 1: Write the failing tests**

In `phpunit_tests/Schemas/PayloadValidationTest.php` (it already imports `Swaggest\JsonSchema\Schema` and has
`self::loadFixture()`), add:

```php
    public function testANationalPayloadDeclaringSeveralWiderRegionsValidates(): void
    {
        $schema  = Schema::import(LitSchema::NATIONAL->path());
        $payload = self::loadFixture('valid_national_calendar.json');
        unset($payload->metadata->wider_region);
        $payload->metadata->wider_regions = ['Europe', 'Middle East'];

        $schema->in($payload);
        $this->addToAssertionCount(1);
    }

    public function testANationalPayloadWithAWiderRegionOfTheWrongShapeIsRefused(): void
    {
        $schema                           = Schema::import(LitSchema::NATIONAL->path());
        $payload                          = self::loadFixture('valid_national_calendar.json');
        $payload->metadata->wider_regions = ['europe'];

        $this->expectException(\Swaggest\JsonSchema\InvalidValue::class);
        $schema->in($payload);
    }
```

The fixture `valid_national_calendar.json` keeps its legacy `wider_region`: it stays valid and covers the legacy
input. (`PayloadValidationTest`'s assertion on `$dto->metadata->wider_region` near line 586 is on a *wider region's*
own id, `WiderRegionMetadata`, and is unaffected.)

In `phpunit_tests/Services/WiderRegionMembershipTest.php`, add:

```php
    public function testEveryRegionTheNationDeclaresCounts(): void
    {
        $root                = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wrm-' . bin2hex(random_bytes(4)) . DIRECTORY_SEPARATOR;
        $saved               = Router::$apiFilePath;
        Router::$apiFilePath = $root;
        $nationFolder        = dirname(strtr(JsonData::NATIONAL_CALENDAR_FILE->path(), ['{nation}' => 'SE']));
        try {
            self::assertTrue(mkdir($nationFolder, 0777, true));
            self::assertTrue(mkdir(JsonData::WIDER_REGIONS_FOLDER->path(), 0777, true));
            file_put_contents("{$nationFolder}/SE.json", '{"metadata": {"wider_regions": ["Europe", "Nordic"]}}');

            self::assertSame(['Europe', 'Nordic'], WiderRegionMembership::regionsOf('SE'));
        } finally {
            Router::$apiFilePath = $saved;
            exec('rm -rf ' . escapeshellarg($root));
        }
    }
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit phpunit_tests/Schemas/PayloadValidationTest.php phpunit_tests/Services/WiderRegionMembershipTest.php`
Expected: `testANationalPayloadDeclaringSeveralWiderRegionsValidates` FAILS (`wider_regions` is not an allowed
property, and the fixture no longer carries the then-required `wider_region`); `testEveryRegionTheNationDeclaresCounts`
FAILS with `[]`.

- [ ] **Step 3: Replace the enum with a shape rule**

In `CommonDef.json`, replace the whole `"WiderRegionNames": { ... }` definition with:

```json
        "WiderRegionName": {
            "type": "string",
            "pattern": "^[A-Z][A-Za-z]*( [A-Z][A-Za-z]*)*$",
            "description": "A wider region name: capitalised words separated by single spaces, e.g. `Europe`, `Middle East`. Whether the region exists is checked at runtime against the wider region files."
        },
```

Rename every reference:

```bash
grep -rl 'definitions/WiderRegionNames"' jsondata/schemas \
  | xargs sed -i 's#definitions/WiderRegionNames"#definitions/WiderRegionName"#g'
grep -rn 'WiderRegionNames' jsondata/schemas   # expected: no output
```

In `NationalCalendar.json` (`NationalCalendarMetadata.properties`), replace the `"wider_region"` property with:

```json
                "wider_regions": {
                    "type": "array",
                    "uniqueItems": true,
                    "items": {
                        "$ref": "./CommonDef.json#/definitions/WiderRegionName"
                    },
                    "description": "The wider regions this nation belongs to, most general first. Each one's calendar layer is applied in this order, before the nation's own."
                },
                "wider_region": {
                    "$ref": "./CommonDef.json#/definitions/WiderRegionName",
                    "deprecated": true,
                    "description": "Deprecated: a single wider region, read as `wider_regions: [wider_region]`. When both are sent they must agree."
                },
```

and remove `"wider_region"` from that object's `required` list.

In `WiderRegionCalendar.json` (`CalendarMetadata.properties.wider_region`), replace the inline 5-name enum with
`{ "$ref": "./CommonDef.json#/definitions/WiderRegionName" }`.

- [ ] **Step 4: Migrate the five national files**

In each of `jsondata/sourcedata/rite/roman/calendars/nations/{CA,HR,IT,NL,US}/{X}.json`, replace the one line
`"wider_region": "<Region>",` with `"wider_regions": ["<Region>"],` at the same indentation, keeping the rest of the
file byte-identical:

```bash
for n in CA HR IT NL US; do
  f=jsondata/sourcedata/rite/roman/calendars/nations/$n/$n.json
  sed -i -E 's/"wider_region": "([^"]+)"/"wider_regions": ["\1"]/' "$f"
done
git diff --stat jsondata/sourcedata   # expected: 5 files, 1 line each
jq -c '.metadata.wider_regions' jsondata/sourcedata/rite/roman/calendars/nations/*/*.json
```

- [ ] **Step 5: `regionsOf()` reads the list**

In `WiderRegionMembership::regionsOf()`, replace the block reading `$metadata['wider_region']` with:

```php
            $declared = is_array($metadata) ? ( $metadata['wider_regions'] ?? [] ) : [];
            if (is_array($declared)) {
                foreach ($declared as $region) {
                    if (is_string($region) && $region !== '') {
                        $regions[] = $region;
                    }
                }
            }
```

Update its docblock line describing the nation's `metadata.wider_region` to say `metadata.wider_regions`.

- [ ] **Step 6: Run and verify**

Run: `vendor/bin/phpunit phpunit_tests/Schemas/PayloadValidationTest.php phpunit_tests/Services/WiderRegionMembershipTest.php`
Expected: PASS.
Run: `composer lint:openapi && composer lint:jsondata && composer test:quick && composer analyse && composer lint`
Expected: all pass; `Schemas/SchemaValidationTest` validates the migrated source files; golden masters unchanged.

- [ ] **Step 7: Commit**

```bash
git add jsondata/ src/Services/WiderRegionMembership.php phpunit_tests/
git commit -m "feat(schema): wider_regions list and a WiderRegionName shape rule; migrate the national files (#1005)"
```

---

### Task 3: `/calendars` publishes the lists and each region's members

**Files:**

- Modify: `src/Models/Metadata/MetadataNationalCalendarItem.php`
- Modify: `src/Models/Metadata/MetadataWiderRegionItem.php`
- Modify: `src/Models/Metadata/MetadataCalendars.php` (phpstan shapes only)
- Modify: `src/Services/CalendarMetadataProvider.php` (`buildWiderRegionData()`)
- Modify: `src/Handlers/RegionalDataHandler.php` (`checkWiderRegionCalendarConditions()`, the DELETE guard)
- Modify: `jsondata/schemas/LitCalMetadata.json`
- Test: `phpunit_tests/Services/CalendarMetadataProviderTest.php`, `phpunit_tests/Models/Metadata/MetadataCalendarsTest.php`,
  `phpunit_tests/Routes/Readonly/CalendarsTest.php`, `phpunit_tests/fixtures/api/calendars*`

**Interfaces:**

- Consumes: source files carrying `wider_regions` (Task 2).
- Produces: `MetadataNationalCalendarItem::$wider_regions` (`list<string>`);
  `MetadataWiderRegionItem::$national_calendars` (`list<string>`, nation codes, sorted).

- [ ] **Step 1: Write the failing tests**

In `phpunit_tests/Services/CalendarMetadataProviderTest.php`, add (reuse the file's existing Router-path setup):

```php
    public function testNationsPublishTheirWiderRegionsAsAList(): void
    {
        $metadata = CalendarMetadataProvider::create();
        $it       = array_find($metadata->national_calendars, static fn ($n) => $n->calendar_id === 'IT');
        $va       = array_find($metadata->national_calendars, static fn ($n) => $n->calendar_id === 'VA');

        self::assertNotNull($it);
        self::assertSame(['Europe'], $it->wider_regions);
        self::assertSame('Europe', $it->jsonSerialize()['wider_region'], 'Deprecated single form while exactly one region');
        self::assertNotNull($va);
        self::assertSame([], $va->jsonSerialize()['wider_regions']);
        self::assertArrayNotHasKey('wider_region', $va->jsonSerialize());
    }

    public function testTheDeprecatedSingleFormIsOmittedForSeveralRegions(): void
    {
        $item = \LiturgicalCalendar\Api\Models\Metadata\MetadataNationalCalendarItem::fromArray([
            'calendar_id'   => 'SE',
            'locales'       => ['sv_SE'],
            'missals'       => [],
            'wider_regions' => ['Europe', 'Nordic'],
        ]);

        self::assertSame(['Europe', 'Nordic'], $item->wider_regions);
    }

    public function testEachRegionListsTheNationsThatDeclareIt(): void
    {
        $metadata = CalendarMetadataProvider::create();
        $europe   = array_find($metadata->wider_regions, static fn ($r) => $r->name === 'Europe');
        $asia     = array_find($metadata->wider_regions, static fn ($r) => $r->name === 'Asia');

        self::assertNotNull($europe);
        self::assertSame(['HR', 'IT', 'NL'], $europe->national_calendars);
        self::assertNotNull($asia);
        self::assertSame([], $asia->national_calendars, 'China and Japan are on the roster but have no calendar');
    }
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit phpunit_tests/Services/CalendarMetadataProviderTest.php`
Expected: FAIL, `Undefined property ... wider_regions` / `national_calendars`.

- [ ] **Step 3: `MetadataNationalCalendarItem`**

In both phpstan types, replace `wider_region?:string,` with `wider_regions?:string[], wider_region?:string,`. Replace
`public ?string $wider_region;` with:

```php
    /** @var list<string> The wider regions the nation declares, most general first. */
    public array $wider_regions;
```

Constructor: replace `?string $wider_region = null` with `array $wider_regions = []` (docblock
`@param list<string> $wider_regions`) and the assignment with `$this->wider_regions = array_values($wider_regions);`.

`jsonSerialize()`: replace the `if ($this->wider_region !== null)` block with:

```php
        $retArr['wider_regions'] = $this->wider_regions;
        // Deprecated (#1005): the single form is kept for clients written for one region, but only when the nation
        // has exactly one, so such a client never sees a list truncated to its first element.
        if (count($this->wider_regions) === 1) {
            $retArr['wider_region'] = $this->wider_regions[0];
        }
```

and add `wider_regions:string[]` to its `@return` shape.

Both factories: replace the `isset(... 'wider_region' ...)` argument with a shared reader:

```php
    /**
     * @return list<string>
     */
    private static function widerRegionsFrom(mixed $list, mixed $legacy): array
    {
        if (is_array($list)) {
            return array_values(array_filter($list, 'is_string'));
        }

        return is_string($legacy) && $legacy !== '' ? [$legacy] : [];
    }
```

called as `self::widerRegionsFrom($data['wider_regions'] ?? null, $data['wider_region'] ?? null)` and
`self::widerRegionsFrom($data->wider_regions ?? null, $data->wider_region ?? null)`.

In `MetadataCalendars.php`, add `wider_regions?:string[],` beside each of the three `wider_region?:string` phpstan
shapes, and `national_calendars?:string[]` to the region item shape if one is declared there.

- [ ] **Step 4: `MetadataWiderRegionItem` and the provider**

Add the property and constructor parameter (last, defaulting to `[]`):

```php
    /** @var list<string> Codes of the nations that have a calendar and declare this region, sorted. */
    public array $national_calendars;
```

set `$this->national_calendars = array_values($national_calendars);`, add `'national_calendars' => $this->national_calendars`
to `jsonSerialize()`, and read `$data['national_calendars'] ?? []` / `$data->national_calendars ?? []` in the two
factories (with matching phpstan shapes).

In `CalendarMetadataProvider::buildWiderRegionData()` (it runs after `buildNationalCalendarData()`, so the nations are
already indexed), compute the members inside the loop before `MetadataWiderRegionItem::fromArray()`:

```php
                $members = array_map(
                    static fn (MetadataNationalCalendarItem $nation): string => $nation->calendar_id,
                    array_values(array_filter(
                        $metadata->national_calendars,
                        static fn (MetadataNationalCalendarItem $nation): bool => in_array($widerRegionId, $nation->wider_regions, true)
                    ))
                );
                sort($members);
```

and pass `'national_calendars' => $members` in the array. Import `MetadataNationalCalendarItem` if needed.

- [ ] **Step 5: The region DELETE guard**

In `RegionalDataHandler::checkWiderRegionCalendarConditions()`, replace `fn ($el) => $el->wider_region === $params->key`
with `fn ($el) => in_array($params->key, $el->wider_regions, true)`.

- [ ] **Step 6: `LitCalMetadata.json`**

In the national calendar item's `properties`, next to the existing `wider_region` (now `$ref` `WiderRegionName`),
add:

```json
                            "wider_regions": {
                                "type": "array",
                                "uniqueItems": true,
                                "items": {
                                    "$ref": "./CommonDef.json#/definitions/WiderRegionName"
                                },
                                "description": "The wider regions the nation declares, most general first."
                            },
```

mark `wider_region` with `"deprecated": true` and a description ("Present only when the nation declares exactly one
wider region; read `wider_regions`."), and add `"wider_regions"` to that item's `required`.

In `WiderRegionDef.properties`, add:

```json
                "national_calendars": {
                    "type": "array",
                    "uniqueItems": true,
                    "items": {
                        "$ref": "./CommonDef.json#/definitions/Nation"
                    },
                    "description": "Codes of the nations that have a calendar and declare this wider region."
                },
```

add `"national_calendars"` to its `required`, and in the `api_path` pattern replace the alternation
`(?:Africa|Alsace|...|West Indies)` with `[A-Z][A-Za-z]*(?: [A-Z][A-Za-z]*)*`.

- [ ] **Step 7: Update the `/calendars` tests and fixtures**

- `phpunit_tests/Routes/Readonly/CalendarsTest.php`: the assertion that a nation's `wider_region` is a string (around
  line 144) becomes an assertion that `wider_regions` is an array of strings matching `WiderRegionName::PATTERN`, and
  that `wider_region`, when present, equals `wider_regions[0]` and appears only when `count(wider_regions) === 1`.
  Replace the 5-name `WIDER_REGION_PATTERN` constant with `WiderRegionName::PATTERN`. Assert every region item has a
  `national_calendars` array. (These are `ApiTestCase` tests: they run against the server on `:8000`, which serves the
  main checkout, so they are verified by CI, not by a worktree run.)
- `phpunit_tests/fixtures/api/calendars*`: add `wider_regions` beside each `wider_region` and `national_calendars` to
  each region, so any test reading the fixture sees the new shape.
- `phpunit_tests/Models/Metadata/MetadataCalendarsTest.php`, `phpunit_tests/Handlers/MetadataHandlerTest.php`: update
  any construction or assertion that uses `wider_region` as a constructor argument or scalar property.

- [ ] **Step 8: Run and verify**

Run: `vendor/bin/phpunit phpunit_tests/Services/CalendarMetadataProviderTest.php phpunit_tests/Handlers/MetadataHandlerTest.php`
Expected: PASS (confirm the handler test ran, not skipped).
Run: `composer lint:openapi && composer test:quick && composer analyse && composer lint`
Expected: all pass.

- [ ] **Step 9: Commit**

```bash
git add src/ jsondata/schemas/LitCalMetadata.json phpunit_tests/
git commit -m "feat(calendars): publish each nation's wider_regions and each region's member nations (#1005)"
```

---

### Task 4: `WiderRegionLayers`, and `CalendarHandler` applies them in order

**Files:**

- Create: `src/Services/WiderRegionLayer.php`, `src/Services/WiderRegionLayers.php`, `src/Services/WiderRegionNaming.php`
- Modify: `src/Handlers/CalendarHandler.php` (`$WiderRegionData` property, `loadWiderRegionData()`,
  `loadNationalCalendarData()`, `applyNationalCalendar()`, `applyCalendarI18nData()`)
- Modify: `phpunit_tests/fixtures/golden-master/nation-IT-2023.json`, `phpunit_tests/fixtures/golden-master/diocese-romamo-2023.json`
  (regenerated, own commit)
- Test: `phpunit_tests/Services/WiderRegionLayersTest.php` (new), `phpunit_tests/Handlers/WiderRegionLayerOrderTest.php` (new)

**Interfaces:**

- Consumes: `NationalMetadata::$wider_regions`, `NationalData::hasWiderRegion()` (Task 1).
- Produces:
  - `enum WiderRegionNaming { case Strict; case Lenient; }`
  - `final class WiderRegionLayer { public function __construct(public readonly string $region, public readonly WiderRegionData $data, public readonly ?string $lectionaryFile) }`
  - `WiderRegionLayers::for(NationalData $nation, string $locale, WiderRegionNaming $naming): list<WiderRegionLayer>`

- [ ] **Step 1: Write the failing unit tests**

`phpunit_tests/Services/WiderRegionLayersTest.php`. It builds a shadow root with a synthetic `Nordic` region beside the
shipped `Europe`:

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services;

use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Http\Exception\ServiceUnavailableException;
use LiturgicalCalendar\Api\Models\RegionalData\NationalData\NationalData;
use LiturgicalCalendar\Api\Router;
use LiturgicalCalendar\Api\Services\WiderRegionLayers;
use LiturgicalCalendar\Api\Services\WiderRegionNaming;
use LiturgicalCalendar\Api\Utilities;
use LiturgicalCalendar\Tests\Support\PinsRouterPathsTrait;
use LiturgicalCalendar\Tests\Support\ShadowProjectRootTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WiderRegionLayers::class)]
final class WiderRegionLayersTest extends TestCase
{
    use PinsRouterPathsTrait;
    use ShadowProjectRootTrait;

    private static string $root = '';

    public static function setUpBeforeClass(): void
    {
        self::pinRouterPaths();
        self::$root          = self::createShadowProjectRoot(Router::$apiFilePath, 'litcal-wider-region-layers');
        Router::$apiFilePath = self::$root . DIRECTORY_SEPARATOR;
        self::writeNordicRegion();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$root !== '') {
            self::removeTree(self::$root);
        }
        self::restoreRouterPaths();
    }

    /** A synthetic Nordic region acting on St Benedict and St Catherine, with Italian names, and Italy as a member. */
    public static function writeNordicRegion(): void
    {
        $europe = json_decode((string) file_get_contents(strtr(JsonData::WIDER_REGION_FILE->path(), ['{wider_region}' => 'Europe'])), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($europe);
        $nordic           = $europe;
        $nordic['litcal'] = [];
        foreach ($europe['litcal'] as $row) {
            if (in_array($row['liturgical_event']['event_key'], ['StBenedict', 'StCatherineSiena'], true)) {
                $row['liturgical_event']['grade'] = 5;
                $nordic['litcal'][]               = $row;
            }
        }
        $nordic['national_calendars']       = ['Italy' => 'IT'];
        $nordic['metadata']['wider_region'] = 'Nordic';
        $nordic['metadata']['locales']      = ['it_IT'];

        $folder = dirname(strtr(JsonData::WIDER_REGION_FILE->path(), ['{wider_region}' => 'Nordic']));
        if (!is_dir("{$folder}/i18n") && !mkdir("{$folder}/i18n", 0777, true)) {
            throw new \RuntimeException("Cannot create {$folder}/i18n");
        }
        file_put_contents("{$folder}/Nordic.json", json_encode($nordic, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        file_put_contents("{$folder}/i18n/it_IT.json", json_encode(['StBenedict' => 'Nordic Benedict', 'StCatherineSiena' => 'Nordic Catherine'], JSON_THROW_ON_ERROR));
    }

    /** @param list<string> $regions */
    private static function italyIn(array $regions): NationalData
    {
        $it = Utilities::jsonFileToObject(strtr(JsonData::NATIONAL_CALENDAR_FILE->path(), ['{nation}' => 'IT']));
        $it->metadata->wider_regions = $regions;

        return NationalData::fromObject($it);
    }

    public function testLayersFollowTheDeclaredOrder(): void
    {
        $layers = WiderRegionLayers::for(self::italyIn(['Europe', 'Nordic']), 'it_IT', WiderRegionNaming::Strict);

        self::assertSame(['Europe', 'Nordic'], array_map(static fn ($l) => $l->region, $layers));
    }

    public function testEachLayerIsNamedFromItsOwnI18n(): void
    {
        [, $nordic] = WiderRegionLayers::for(self::italyIn(['Europe', 'Nordic']), 'it_IT', WiderRegionNaming::Strict);

        $names = [];
        foreach ($nordic->data->litcal as $item) {
            $names[$item->getEventKey()] = $item->liturgical_event->name;
        }
        self::assertSame('Nordic Benedict', $names['StBenedict']);
    }

    public function testTheRegionsOwnLectionaryIsFoundAndAbsentOnesAreNull(): void
    {
        [$europe, $nordic] = WiderRegionLayers::for(self::italyIn(['Europe', 'Nordic']), 'it_IT', WiderRegionNaming::Strict);

        self::assertSame(strtr(JsonData::WIDER_REGION_LECTIONARY_FILE->path(), ['{wider_region}' => 'Europe', '{locale}' => 'it_IT']), $europe->lectionaryFile);
        self::assertNull($nordic->lectionaryFile);
    }

    public function testNoRegionMeansNoLayers(): void
    {
        self::assertSame([], WiderRegionLayers::for(self::italyIn([]), 'it_IT', WiderRegionNaming::Strict));
    }

    public function testAMissingRegionFileFailsLoudly(): void
    {
        $this->expectException(ServiceUnavailableException::class);

        WiderRegionLayers::for(self::italyIn(['Atlantis']), 'it_IT', WiderRegionNaming::Strict);
    }
}
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit phpunit_tests/Services/WiderRegionLayersTest.php`
Expected: errors, `Class "LiturgicalCalendar\Api\Services\WiderRegionLayers" not found`.

- [ ] **Step 3: Implement the three units**

`src/Services/WiderRegionNaming.php`:

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services;

/**
 * How a wider region layer's names are applied from its i18n file.
 *
 * `Strict` is `/calendar`'s behaviour: every name-bearing item must have a translation
 * ({@see \LiturgicalCalendar\Api\Models\RegionalData\WiderRegionData\WiderRegionData::setNames()} throws otherwise).
 * `Lenient` is `/events`' behaviour: an item is renamed when a translation exists, and left alone when it does not.
 * Both are preserved exactly, so that sharing the loader changes neither endpoint's output.
 */
enum WiderRegionNaming
{
    case Strict;
    case Lenient;
}
```

`src/Services/WiderRegionLayer.php`:

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services;

use LiturgicalCalendar\Api\Models\RegionalData\WiderRegionData\WiderRegionData;

/** One wider region's calendar layer, loaded and named for a request's locale. */
final class WiderRegionLayer
{
    public function __construct(
        public readonly string $region,
        public readonly WiderRegionData $data,
        /** The region's lectionary for the locale, or null when it has none. */
        public readonly ?string $lectionaryFile
    ) {
    }
}
```

`src/Services/WiderRegionLayers.php`:

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services;

use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Models\RegionalData\NationalData\NationalData;
use LiturgicalCalendar\Api\Models\RegionalData\WiderRegionData\WiderRegionData;
use LiturgicalCalendar\Api\Utilities;

/**
 * Loads a nation's wider regions as calendar layers, in the nation's declared order (#1005).
 *
 * Shared by `CalendarHandler` and `EventsHandler`, so that loading, naming and lectionary resolution live in one
 * place. They used to be duplicated, which is how `/calendar` came to load the nation's own lectionary a second time
 * as "the wider region's".
 *
 * A missing region file, or a missing i18n file for the locale, throws: a calendar quietly missing a region's
 * patrons would be wrong output.
 */
final class WiderRegionLayers
{
    /**
     * @return list<WiderRegionLayer> In the nation's declared order, most general first.
     */
    public static function for(NationalData $nation, string $locale, WiderRegionNaming $naming): array
    {
        $layers = [];
        foreach ($nation->metadata->wider_regions as $region) {
            $substitutions = ['{wider_region}' => $region, '{locale}' => $locale];

            $data  = WiderRegionData::fromObject(Utilities::jsonFileToObject(strtr(JsonData::WIDER_REGION_FILE->path(), $substitutions)));
            $names = self::names(strtr(JsonData::WIDER_REGION_I18N_FILE->path(), $substitutions));

            if ($naming === WiderRegionNaming::Strict) {
                $data->setNames($names);
            } else {
                foreach ($data->litcal as $item) {
                    if (array_key_exists($item->liturgical_event->event_key, $names)) {
                        $item->setName($names[$item->liturgical_event->event_key]);
                    }
                }
            }

            $lectionary = strtr(JsonData::WIDER_REGION_LECTIONARY_FILE->path(), $substitutions);
            $layers[]   = new WiderRegionLayer($region, $data, is_file($lectionary) && is_readable($lectionary) ? $lectionary : null);
        }

        return $layers;
    }

    /**
     * @return array<string, string>
     */
    private static function names(string $file): array
    {
        $names = Utilities::jsonFileToArray($file);
        if (array_filter(array_keys($names), 'is_string') !== array_keys($names)) {
            throw new \Exception('We expected all the keys of the array to be strings.');
        }
        if (array_filter($names, 'is_string') !== $names) {
            throw new \Exception('We expected all the values of the array to be strings.');
        }

        /** @var array<string, string> $names */
        return $names;
    }
}
```

(The two exception messages are the ones `CalendarHandler` throws today for the same checks; keep them.)

- [ ] **Step 4: Run the unit tests**

Run: `vendor/bin/phpunit phpunit_tests/Services/WiderRegionLayersTest.php`
Expected: PASS.

- [ ] **Step 5: Write the failing end-to-end order test**

`phpunit_tests/Handlers/WiderRegionLayerOrderTest.php` extends `AbstractHandlerTestCase`. In a shadow root it writes
the Nordic region (reuse `WiderRegionLayersTest::writeNordicRegion()`), sets IT's `wider_regions` to
`["Europe", "Nordic"]`, adds a St Benedict `makePatron` to IT's own `litcal`, and names it in IT's `it_IT` i18n:

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Handlers\CalendarHandler;
use LiturgicalCalendar\Api\Router;
use LiturgicalCalendar\Api\Services\WiderRegionLayers;
use LiturgicalCalendar\Tests\Services\WiderRegionLayersTest;
use LiturgicalCalendar\Tests\Support\ShadowProjectRootTrait;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(WiderRegionLayers::class)]
#[CoversClass(CalendarHandler::class)]
final class WiderRegionLayerOrderTest extends AbstractHandlerTestCase
{
    use ShadowProjectRootTrait;

    private static string $root = '';

    /** @var array<string, mixed> */
    private static array $savedServer = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$root          = self::createShadowProjectRoot(Router::$apiFilePath, 'litcal-wider-region-order');
        Router::$apiFilePath = self::$root . DIRECTORY_SEPARATOR;
        WiderRegionLayersTest::writeNordicRegion();

        $itFile = strtr(JsonData::NATIONAL_CALENDAR_FILE->path(), ['{nation}' => 'IT']);
        $it     = json_decode((string) file_get_contents($itFile), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($it);
        $it['metadata']['wider_regions'] = ['Europe', 'Nordic'];
        $it['litcal'][]                  = [
            'liturgical_event' => ['event_key' => 'StBenedict', 'grade' => 4],
            'metadata'         => ['action' => 'makePatron', 'since_year' => 1964, 'url' => 'https://example.test/'],
        ];
        file_put_contents($itFile, json_encode($it, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        $itI18n                = strtr(JsonData::NATIONAL_CALENDAR_I18N_FILE->path(), ['{nation}' => 'IT', '{locale}' => 'it_IT']);
        $names                 = json_decode((string) file_get_contents($itI18n), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($names);
        $names['StBenedict']   = 'Italian Benedict';
        file_put_contents($itI18n, json_encode($names, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$root !== '') {
            self::removeTree(self::$root);
            self::$root = '';
        }
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Router::isLocalhost() bypasses the engine cache, which hashes source data but not PHP (see CLAUDE.md).
        self::$savedServer         = $_SERVER;
        $_SERVER['SERVER_NAME']    = 'localhost';
    }

    protected function tearDown(): void
    {
        $_SERVER = self::$savedServer;
        parent::tearDown();
    }

    /** @return array<string, array<string, mixed>> event_key => event */
    private function italy2024(): array
    {
        $response = ( new CalendarHandler(['nation', 'IT', '2024']) )
            ->handle($this->requestFor('GET', '/calendar/nation/IT/2024', ['Accept' => 'application/json']));
        self::assertSame(200, $response->getStatusCode());

        $events = [];
        foreach ($this->decodeJsonBody($response)['litcal'] as $event) {
            $events[$event['event_key']] = $event;
        }

        return $events;
    }

    public function testAMoreSpecificRegionActsAfterAMoreGeneralOne(): void
    {
        $catherine = $this->italy2024()['StCatherineSiena'];

        self::assertSame('Nordic Catherine', $catherine['name']);
        self::assertSame(5, $catherine['grade']);
    }

    public function testTheNationActsAfterEveryRegion(): void
    {
        self::assertSame('Italian Benedict', $this->italy2024()['StBenedict']['name']);
    }
}
```

`/calendar` responses carry `litcal`, a list of events each with `event_key`, `name` and `grade` (see any file in
`phpunit_tests/fixtures/golden-master/`).

- [ ] **Step 6: Run to verify failure**

Run: `vendor/bin/phpunit phpunit_tests/Handlers/WiderRegionLayerOrderTest.php`
Expected: FAIL: only Europe is applied (Task 1's interim read of `wider_regions[0]`), so St Catherine carries
Europe's name and grade 4.

- [ ] **Step 7: Rewire `CalendarHandler`**

1. Property: replace `private ?WiderRegionData $WiderRegionData = null;` with
   `/** @var list<WiderRegionLayer> In the nation's declared order; built with the locale in applyCalendarI18nData(). */`
   `private array $WiderRegionLayers = [];`, and import `WiderRegionLayer`, `WiderRegionLayers`, `WiderRegionNaming`.
   Remove the now-unused `WiderRegionData` import if PHPStan reports it.
2. Delete `loadWiderRegionData()`.
3. In `loadNationalCalendarData()`, delete the whole `if ($this->NationalData->hasWiderRegion()) { ... } else { ... }`
   block, including the "Could not find a %1$s property…" message.
4. In `applyNationalCalendar()`, replace the `if ($this->WiderRegionData !== null && property_exists(...))` block with:

   ```php
           // Apply each wider region's layer in the nation's declared order, most general first, so a more specific
           // region acts after a more general one and the nation, below, after all of them (#1005).
           foreach ($this->WiderRegionLayers as $layer) {
               $this->handleNationalCalendarEvents($layer->data->litcal);
           }
   ```

5. In `applyCalendarI18nData()`'s national block (`if ($this->CalendarParams->NationalCalendar !== null && $this->NationalData !== null)`):
   delete the whole `if ($this->WiderRegionData !== null) { ... }` block (i18n, `setNames`, and the misnamed
   lectionary). At the start of the national block, before the national i18n is read, insert:

   ```php
               $this->WiderRegionLayers = WiderRegionLayers::for($this->NationalData, $this->CalendarParams->Locale, WiderRegionNaming::Strict);
               // Region lectionaries load before the nation's own, because a later file overrides an earlier one
               // (ReadingsMap::offsetSet), and the nation must win over its regions.
               foreach ($this->WiderRegionLayers as $layer) {
                   if (null !== $layer->lectionaryFile) {
                       $this->Cal::$lectionary->addSanctoraleReadingsFromFile($layer->lectionaryFile);
                   }
               }
   ```

   Verify with `grep -n "WiderRegionData" src/Handlers/CalendarHandler.php` that only the import (if still used) remains.

- [ ] **Step 8: Run the order test and the golden masters**

Run: `vendor/bin/phpunit phpunit_tests/Handlers/WiderRegionLayerOrderTest.php`
Expected: PASS (confirm it ran: two `.`, no `S`).
Run: `rm -rf engineCache/ && vendor/bin/phpunit --filter CalendarGoldenMaster phpunit_tests/Handlers/CalendarGoldenMasterTest.php`
Expected: exactly `nation-IT-2023` and `diocese-romamo-2023` FAIL (Europe's `it_IT` lectionary now loads); every
`general-*` case and `nation-US-2023` PASS.

- [ ] **Step 9: Commit the code**

```bash
git add src/Services/WiderRegionLayer.php src/Services/WiderRegionLayers.php src/Services/WiderRegionNaming.php \
  src/Handlers/CalendarHandler.php phpunit_tests/Services/WiderRegionLayersTest.php phpunit_tests/Handlers/WiderRegionLayerOrderTest.php
git commit -m "feat(calendar): apply every wider region layer in order; load each region's own lectionary (#1005)"
```

- [ ] **Step 10: Regenerate the two golden masters, in their own commit**

Read `phpunit_tests/Handlers/CalendarGoldenMasterGenerateTest.php`'s docblock for how it is run (it is fenced by a
group that `composer test:quick` excludes; run it only with the exact command it documents, filtered to the two
labels). Then:

```bash
rm -rf engineCache/
git diff --stat phpunit_tests/fixtures/golden-master/        # expected: exactly the two files
git diff phpunit_tests/fixtures/golden-master/nation-IT-2023.json | grep '^[-+]' | grep -v '^[-+][-+]' | head -40
```

Expected diff: only `readings` of events Europe makes patron (St Benedict, Sts Cyril and Methodius, St Bridget,
St Catherine of Siena, St Teresa Benedicta of the Cross). If anything else changed, stop and investigate before
committing. Run `vendor/bin/phpunit --filter CalendarGoldenMaster phpunit_tests/Handlers/CalendarGoldenMasterTest.php`
again: all PASS.

```bash
git add phpunit_tests/fixtures/golden-master/nation-IT-2023.json phpunit_tests/fixtures/golden-master/diocese-romamo-2023.json
git commit -m "test(golden): Italy and Rome gain Europe's patron readings from the region lectionary (#1005)"
```

Record the event keys whose readings changed; the PR description lists them.

---

### Task 5: `EventsHandler` applies every layer

**Files:**

- Modify: `src/Handlers/EventsHandler.php` (`$WiderRegionData`, the `hasWiderRegion()` load block, the apply block)
- Test: `phpunit_tests/Handlers/WiderRegionLayerOrderTest.php` (add `/events` tests)

**Interfaces:**

- Consumes: `WiderRegionLayers::for(..., WiderRegionNaming::Lenient)` (Task 4).

- [ ] **Step 1: Write the failing tests**

Add to `WiderRegionLayerOrderTest`:

```php
    /** @return array<string, array<string, mixed>> event_key => event */
    private function italyEvents(): array
    {
        $response = ( new \LiturgicalCalendar\Api\Handlers\EventsHandler(['nation', 'IT']) )
            ->handle($this->requestFor('GET', '/events/nation/IT', ['Accept' => 'application/json', 'Accept-Language' => 'it-IT']));
        self::assertSame(200, $response->getStatusCode());

        $events = [];
        foreach ($this->decodeJsonBody($response)['litcal_events'] as $event) {
            $events[$event['event_key']] = $event;
        }

        return $events;
    }

    public function testEventsAppliesEveryRegionInOrder(): void
    {
        self::assertSame('Nordic Catherine', $this->italyEvents()['StCatherineSiena']['name']);
    }
```

Add `#[CoversClass(\LiturgicalCalendar\Api\Handlers\EventsHandler::class)]`. `/events` responses carry `litcal_events`, a
list of events each with `event_key` and `name` (as `EventsHandlerTest` reads them).

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit phpunit_tests/Handlers/WiderRegionLayerOrderTest.php --filter Events`
Expected: FAIL: only Europe is loaded.

- [ ] **Step 3: Rewire `EventsHandler`**

1. Replace `private static ?WiderRegionData $WiderRegionData = null;` with
   `/** @var list<WiderRegionLayer> */ private static array $WiderRegionLayers = [];`. Find where
   `self::$WiderRegionData` is reset between requests (`grep -n 'WiderRegionData' src/Handlers/EventsHandler.php`) and
   reset `self::$WiderRegionLayers = []` there instead.
2. Replace the whole `if (self::$NationalData->hasWiderRegion()) { ... }` block with:

   ```php
               self::$WiderRegionLayers = WiderRegionLayers::for(self::$NationalData, $this->EventsParams->Locale, WiderRegionNaming::Lenient);
   ```

3. Replace `if (self::$WiderRegionData !== null) { foreach (self::$WiderRegionData->litcal as $litCalItem) { ... } }`
   with a loop over the layers, keeping the inner `if/elseif` chain unchanged:

   ```php
               foreach (self::$WiderRegionLayers as $layer) {
                   foreach ($layer->data->litcal as $litCalItem) {
                       // ... the existing if/elseif chain, unchanged ...
                   }
               }
   ```

- [ ] **Step 4: Run and verify**

Run: `vendor/bin/phpunit phpunit_tests/Handlers/WiderRegionLayerOrderTest.php`
Expected: PASS.
Run: `composer test:quick && composer analyse && composer lint`
Expected: all pass (the `/events` handler tests, e.g. `EventsHandlerRiteRoutingTest`, stay green).

- [ ] **Step 5: Commit**

```bash
git add src/Handlers/EventsHandler.php phpunit_tests/Handlers/WiderRegionLayerOrderTest.php
git commit -m "feat(events): apply every wider region layer in order (#1005)"
```

---

### Task 6: National writes: validation, normalisation, warnings, and read compatibility

**Files:**

- Modify: `src/Handlers/RegionalDataHandler.php` (`checkNationalCalendarConditions()`, `handle()`'s NATION payload
  branch, `createNationalCalendar()`, `updateNationalCalendar()`, `getCalendar()`)
- Modify: `jsondata/schemas/openapi.json` (national PUT/PATCH responses and descriptions, prose, examples)
- Test: `phpunit_tests/Handlers/RegionalDataHandlerTest.php`

**Interfaces:**

- Consumes: `NationalMetadata::$wider_regions`, `$usedLegacyWiderRegion` (Task 1); `MetadataNationalCalendarItem::$wider_regions` (Task 3).
- Produces: write responses may carry `warnings: list<string>`.

- [ ] **Step 1: Write the failing tests**

In `RegionalDataHandlerTest` (it already has a shadow root and `shippedNationalCalendarPayload()`, `mtNationalCalendarPayload()`):

```php
    public function testPutRefusesAnUnknownWiderRegion(): void
    {
        $this->requireMtNationAbsent();
        $payload = self::mtNationalCalendarPayload();
        unset($payload['metadata']['wider_region']);
        $payload['metadata']['wider_regions'] = ['Europe', 'Atlantis'];

        try {
            ( new RegionalDataHandler(['nation', 'MT']) )->handle($this->requestFor('PUT', '/data/nation/MT', [], $payload));
            self::fail('An unknown wider region must be refused.');
        } catch (UnprocessableContentException $e) {
            self::assertStringContainsString('Atlantis', $e->getMessage());
            self::assertStringContainsString('Europe', $e->getMessage(), 'Names the known regions');
        }
    }

    public function testPutRefusesARegionWhoseRosterLacksTheNation(): void
    {
        $this->requireMtNationAbsent();
        $payload = self::mtNationalCalendarPayload();
        unset($payload['metadata']['wider_region']);
        $payload['metadata']['wider_regions'] = ['Americas'];

        $this->expectException(UnprocessableContentException::class);
        $this->expectExceptionMessage('Americas');

        ( new RegionalDataHandler(['nation', 'MT']) )->handle($this->requestFor('PUT', '/data/nation/MT', [], $payload));
    }

    public function testALegacyPatchIsAcceptedStoredAsAListAndWarned(): void
    {
        $payload = self::shippedNationalCalendarPayload('HR');
        unset($payload['metadata']['wider_regions']);
        $payload['metadata']['wider_region'] = 'Europe';

        $response = ( new RegionalDataHandler(['nation', 'HR']) )
            ->handle($this->requestFor('PATCH', '/data/nation/HR', ['Accept-Language' => 'hr-HR'], $payload));

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeJsonBody($response);
        self::assertStringContainsString('wider_regions', implode(' ', $body['warnings'] ?? []));

        $stored = json_decode((string) file_get_contents(self::hrCalendarFile(Router::$apiFilePath)), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['Europe'], $stored['metadata']['wider_regions']);
        self::assertArrayNotHasKey('wider_region', $stored['metadata']);
    }

    public function testAPatchEchoingBothAgreeingFieldsIsAccepted(): void
    {
        $payload                             = self::shippedNationalCalendarPayload('HR');
        $payload['metadata']['wider_region'] = 'Europe';   // beside the stored wider_regions: ["Europe"]

        $response = ( new RegionalDataHandler(['nation', 'HR']) )
            ->handle($this->requestFor('PATCH', '/data/nation/HR', ['Accept-Language' => 'hr-HR'], $payload));

        self::assertSame(200, $response->getStatusCode());
    }

    public function testALegacyPatchToANationInSeveralRegionsIsRefused(): void
    {
        // Give HR a second region in the shadow root: a synthetic Balkans region whose roster lists Croatia.
        self::writeRegion('Balkans', ['Croatia' => 'HR'], ['hr_HR']);
        $file                      = self::hrCalendarFile(Router::$apiFilePath);
        $hr                        = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        $hr['metadata']['wider_regions'] = ['Europe', 'Balkans'];
        file_put_contents($file, json_encode($hr, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        $payload = self::shippedNationalCalendarPayload('HR');
        unset($payload['metadata']['wider_regions']);
        $payload['metadata']['wider_region'] = 'Europe';

        $this->expectException(UnprocessableContentException::class);
        $this->expectExceptionMessage('wider_regions');

        ( new RegionalDataHandler(['nation', 'HR']) )
            ->handle($this->requestFor('PATCH', '/data/nation/HR', ['Accept-Language' => 'hr-HR'], $payload));
    }

    public function testGetKeepsTheDeprecatedSingleFormForOneRegion(): void
    {
        $response = ( new RegionalDataHandler(['nation', 'HR']) )
            ->handle($this->requestFor('GET', '/data/nation/HR', ['Accept-Language' => 'hr-HR']));

        $body = $this->decodeJsonBody($response);
        self::assertSame(['Europe'], $body['metadata']['wider_regions']);
        self::assertSame('Europe', $body['metadata']['wider_region']);
    }

    public function testDeletingARegionANationStillDeclaresIsRefused(): void
    {
        $this->expectException(UnprocessableContentException::class);

        ( new RegionalDataHandler(['widerregion', 'Europe']) )->handle($this->requestFor('DELETE', '/data/widerregion/Europe'));
    }
```

Add the helper (writes a minimal valid region into the shadow root, reusing Europe's file as a template):

```php
    /**
     * @param array<string,string> $members name => nation code
     * @param list<string>         $locales
     */
    private static function writeRegion(string $name, array $members, array $locales): void
    {
        $europe = json_decode((string) file_get_contents(strtr(JsonData::WIDER_REGION_FILE->path(), ['{wider_region}' => 'Europe'])), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($europe);
        $region                       = $europe;
        $region['litcal']             = [$europe['litcal'][0]];
        $region['national_calendars'] = $members;
        $region['metadata']           = ['wider_region' => $name, 'locales' => $locales];

        $folder = dirname(strtr(JsonData::WIDER_REGION_FILE->path(), ['{wider_region}' => $name]));
        if (!is_dir("{$folder}/i18n") && !mkdir("{$folder}/i18n", 0777, true)) {
            throw new \RuntimeException("Cannot create {$folder}/i18n");
        }
        file_put_contents("{$folder}/{$name}.json", json_encode($region, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        foreach ($locales as $locale) {
            file_put_contents("{$folder}/i18n/{$locale}.json", json_encode([$europe['litcal'][0]['liturgical_event']['event_key'] => $name], JSON_THROW_ON_ERROR));
        }
    }
```

The class's `setUp()` already resets the calendar trees from the pristine copy before each test, so the `Balkans`
region and the modified HR file do not leak between tests (confirm by reading `setUp()`; `wider_regions/` is inside
the calendars tree it resets).

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit phpunit_tests/Handlers/RegionalDataHandlerTest.php --filter 'WiderRegion|LegacyPatch|EchoingBoth|DeprecatedSingle|RegionANation'`
Expected: several FAIL (no validation, no warning, legacy form stored, no `wider_region` on GET).

- [ ] **Step 3: Validate declared regions in `checkNationalCalendarConditions()`**

At the end of the method, after the existing `#994` locale check:

```php
        if (in_array($method, [RequestMethod::PUT, RequestMethod::PATCH], true) && $params->payload instanceof NationalData) {
            $this->assertWiderRegionsDeclarable($params->payload, $currentNation->wider_regions ?? []);
        }
```

and add:

```php
    /**
     * Refuse wider regions a nation may not declare (#1005).
     *
     * Each declared region must exist and must list the nation in its own `national_calendars` roster. A PATCH in the
     * deprecated single-string form is refused for a nation that currently declares two or more regions: today's
     * frontend sends one string, and accepting it would silently drop every region but one.
     *
     * @param list<string> $stored The regions the stored file declares; [] on PUT.
     * @throws UnprocessableContentException
     */
    private function assertWiderRegionsDeclarable(NationalData $payload, array $stored): void
    {
        $nation = $payload->metadata->nation;

        if ($payload->metadata->usedLegacyWiderRegion && count($stored) > 1) {
            throw new UnprocessableContentException(sprintf(
                'National calendar %s declares the wider regions %s; send `metadata.wider_regions` (a list) instead of the deprecated `metadata.wider_region`, which can name only one.',
                $nation,
                implode(', ', $stored)
            ));
        }

        foreach ($payload->metadata->wider_regions as $region) {
            if (false === in_array($region, $this->CalendarsMetadata->wider_regions_keys, true)) {
                throw new UnprocessableContentException(sprintf(
                    'Unknown wider region %s. Known wider regions: %s.',
                    $region,
                    implode(', ', $this->CalendarsMetadata->wider_regions_keys)
                ));
            }
            $roster = WiderRegionData::fromObject(Utilities::jsonFileToObject(strtr(JsonData::WIDER_REGION_FILE->path(), ['{wider_region}' => $region])))->national_calendars;
            if (false === in_array($nation, $roster, true)) {
                throw new UnprocessableContentException(sprintf(
                    'Wider region %s does not list %s among its nations (`national_calendars`); add the nation to the region first.',
                    $region,
                    $nation
                ));
            }
        }
    }
```

- [ ] **Step 4: Normalise the stored file and warn on the legacy form**

In `handle()`'s `PathCategory::NATION` branch, right after `$params['payload'] = NationalData::fromObject($payload);`
succeeds, normalise the raw payload that will be written to disk:

```php
                        // Whatever form was sent, store the list (#1005).
                        unset($payload->metadata->wider_region);
                        $payload->metadata->wider_regions = $params['payload']->metadata->wider_regions;
```

(`$params['rawPayload']` holds the same object, so the written file follows.) In `createNationalCalendar()` and
`updateNationalCalendar()`, after `$responseObj->success = ...;`, add:

```php
        if ($payload->metadata->usedLegacyWiderRegion) {
            $responseObj->warnings = ['`metadata.wider_region` is deprecated: send `metadata.wider_regions`, a list of wider regions, most general first.'];
        }
```

- [ ] **Step 5: Keep the deprecated single form on GET**

In `getCalendar()`, just before `return $this->encodeResponseBody($response, $CalendarData);`:

```php
            // Transition (#1005): today's frontend reads a nation's region from `metadata.wider_region`. Emit it
            // beside the list while the nation declares exactly one region; the stored file is not changed.
            if (
                $this->params->category === PathCategory::NATION
                && is_array($CalendarData->metadata->wider_regions ?? null)
                && count($CalendarData->metadata->wider_regions) === 1
            ) {
                $CalendarData->metadata->wider_region = $CalendarData->metadata->wider_regions[0];
            }
```

- [ ] **Step 6: Document the contract in `openapi.json`**

For the PUT `201` and PATCH `200` responses of both `/data/nation/{key}` and `/data/roman/nation/{key}` (four
responses), add to `properties`:

```json
          "warnings": {
            "type": "array",
            "items": { "type": "string" },
            "description": "Advisory notices that did not stop the write, such as the use of a deprecated field."
          },
```

Append to each of those four PUT/PATCH `description`s (textual edit, keep the file's encoding):

```text
 `metadata.wider_regions` lists the nation's wider regions, most general first; each must exist and must list the nation in its own `national_calendars`, or the request is rejected with 422. The deprecated `metadata.wider_region` string is still accepted as a one-element list (with a `warnings` entry), except on a PATCH to a nation that declares two or more regions, which is rejected with 422.
```

Update the IT (`"wider_region": "Europe"`) and US (`"wider_region": "Americas"`) examples to `"wider_regions": [...]`,
and the `PUT /data/widerregion/{key}/{locale}` prose "a national calendar's `metadata.wider_region`" to
"`metadata.wider_regions`" (find each with `grep -n 'wider_region"' jsondata/schemas/openapi.json` and
`grep -n "metadata.wider_region\`" jsondata/schemas/openapi.json`).

- [ ] **Step 7: Run and verify**

Run: `vendor/bin/phpunit phpunit_tests/Handlers/RegionalDataHandlerTest.php phpunit_tests/Handlers/RegionalDataWriteResponseSchemaTest.php`
Expected: PASS (the response-schema test validates the new `warnings` field against the documented schema).
Run: `composer lint:openapi && composer lint:jsondata && composer test:quick && composer analyse && composer lint`
Expected: all pass.

- [ ] **Step 8: Commit**

```bash
git add src/Handlers/RegionalDataHandler.php jsondata/schemas/openapi.json phpunit_tests/Handlers/RegionalDataHandlerTest.php
git commit -m "feat(data): validate a nation's wider regions, store the list, and keep the legacy form readable (#1005)"
```

---

### Task 7: `WiderRegionMembershipSync`, and the national write paths keep tuples in step

**Files:**

- Create: `src/Services/WiderRegionMembershipSync.php`
- Modify: `src/Handlers/RegionalDataHandler.php` (`createNationalCalendar()`, `updateNationalCalendar()`, `deleteCalendar()`)
- Test: `phpunit_tests/Services/WiderRegionMembershipSyncTest.php` (new), `phpunit_tests/Handlers/RegionalDataHandlerTest.php`

**Interfaces:**

- Produces:
  - `WiderRegionMembershipSync::RELATION = 'member_nation'`
  - `WiderRegionMembershipSync::user(string $nation): string` → `national_calendar:roman/{N}`
  - `WiderRegionMembershipSync::object(string $region): string` → `wider_region:roman/{R}`
  - `WiderRegionMembershipSync::rowsFor(string $nation, array $before, array $after, string $episode): list<array>`,
    each row shaped `array{operation: OutboxOperation, fga_user: string, fga_relation: string, fga_object: string,
    idempotency_key: string, metadata: array<string, bool>}`
  - `WiderRegionMembershipSync::newEpisode(): string` (16 hex chars)

- [ ] **Step 1: Write the failing unit tests**

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services;

use LiturgicalCalendar\Api\Services\Outbox\OutboxOperation;
use LiturgicalCalendar\Api\Services\WiderRegionMembershipSync;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WiderRegionMembershipSync::class)]
final class WiderRegionMembershipSyncTest extends TestCase
{
    /** @param list<array<string,mixed>> $rows @return list<string> */
    private static function summary(array $rows): array
    {
        return array_map(static fn (array $r): string => $r['operation']->value . ' ' . $r['fga_object'], $rows);
    }

    public function testCreatingWritesEveryRegion(): void
    {
        $rows = WiderRegionMembershipSync::rowsFor('SE', [], ['Europe', 'Nordic'], 'ep');

        self::assertSame(['write_tuple wider_region:roman/Europe', 'write_tuple wider_region:roman/Nordic'], self::summary($rows));
        self::assertSame('national_calendar:roman/SE', $rows[0]['fga_user']);
        self::assertSame('member_nation', $rows[0]['fga_relation']);
    }

    public function testAddingAndRemovingDiffs(): void
    {
        $rows = WiderRegionMembershipSync::rowsFor('SE', ['Europe', 'Scandinavia'], ['Europe', 'Nordic'], 'ep');

        self::assertSame(['write_tuple wider_region:roman/Nordic', 'delete_tuple wider_region:roman/Scandinavia'], self::summary($rows));
    }

    public function testReorderingChangesNothing(): void
    {
        self::assertSame([], WiderRegionMembershipSync::rowsFor('SE', ['Europe', 'Nordic'], ['Nordic', 'Europe'], 'ep'));
    }

    public function testDeletingRemovesEveryRegion(): void
    {
        $rows = WiderRegionMembershipSync::rowsFor('SE', ['Europe', 'Nordic'], [], 'ep');

        self::assertSame(['delete_tuple wider_region:roman/Europe', 'delete_tuple wider_region:roman/Nordic'], self::summary($rows));
    }

    public function testKeysCarryTheEpisodeSoAReAddIsNotSwallowed(): void
    {
        $first  = WiderRegionMembershipSync::rowsFor('SE', [], ['Nordic'], WiderRegionMembershipSync::newEpisode());
        $second = WiderRegionMembershipSync::rowsFor('SE', [], ['Nordic'], WiderRegionMembershipSync::newEpisode());

        self::assertNotSame($first[0]['idempotency_key'], $second[0]['idempotency_key']);
        self::assertMatchesRegularExpression('/^member_nation:[0-9a-f]{16}:write:wider_region:Nordic:national_calendar:SE$/', $first[0]['idempotency_key']);
    }
}
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit phpunit_tests/Services/WiderRegionMembershipSyncTest.php`
Expected: errors, class not found.

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services;

use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Services\Outbox\OutboxOperation;

/**
 * The `member_nation` tuples a nation's wider regions imply, as a diff (#1005).
 *
 * Pure: it knows nothing about files, the outbox or OpenFGA. The `/data` write paths turn its rows into outbox rows;
 * {@see WiderRegionMembershipReconciler} applies the same diff to OpenFGA directly.
 *
 * Every key carries an episode token. The outbox drops a row whose key already exists (ON CONFLICT DO NOTHING), so a
 * stable key would silently discard the write that re-adds a region after an earlier add and remove; this is the
 * pattern {@see ResourceTuplePurgeService} uses for the same reason.
 */
final class WiderRegionMembershipSync
{
    public const RELATION = 'member_nation';

    public static function user(string $nation): string
    {
        return 'national_calendar:' . RiteScopedObjectId::qualify(Rite::ROMAN, $nation);
    }

    public static function object(string $region): string
    {
        return 'wider_region:' . RiteScopedObjectId::qualify(Rite::ROMAN, $region);
    }

    public static function newEpisode(): string
    {
        return bin2hex(random_bytes(8));
    }

    /**
     * @param list<string> $before The nation's regions before the write ([] on create).
     * @param list<string> $after  The nation's regions after the write ([] on delete).
     * @return list<array{operation: OutboxOperation, fga_user: string, fga_relation: string, fga_object: string, idempotency_key: string, metadata: array<string, bool>}>
     */
    public static function rowsFor(string $nation, array $before, array $after, string $episode): array
    {
        $rows = [];
        foreach (array_values(array_diff($after, $before)) as $region) {
            $rows[] = self::row(OutboxOperation::WRITE_TUPLE, 'write', $nation, $region, $episode);
        }
        foreach (array_values(array_diff($before, $after)) as $region) {
            $rows[] = self::row(OutboxOperation::DELETE_TUPLE, 'delete', $nation, $region, $episode);
        }

        return $rows;
    }

    /**
     * @return array{operation: OutboxOperation, fga_user: string, fga_relation: string, fga_object: string, idempotency_key: string, metadata: array<string, bool>}
     */
    private static function row(OutboxOperation $operation, string $verb, string $nation, string $region, string $episode): array
    {
        return [
            'operation'       => $operation,
            'fga_user'        => self::user($nation),
            'fga_relation'    => self::RELATION,
            'fga_object'      => self::object($region),
            'idempotency_key' => "member_nation:{$episode}:{$verb}:wider_region:{$region}:national_calendar:{$nation}",
            'metadata'        => ['member_nation_sync' => true],
        ];
    }
}
```

- [ ] **Step 4: Run the unit tests**

Run: `vendor/bin/phpunit phpunit_tests/Services/WiderRegionMembershipSyncTest.php`
Expected: PASS.

- [ ] **Step 5: Write the failing handler tests**

In `RegionalDataHandlerTest`, add tests using an injected outbox mock (the pattern of
`testCreateNationalCalendarEnqueuesMemberNationTuple`, which forces OpenFGA "configured" and needs Postgres). Write a
private helper that captures inserted rows:

```php
    /** @return array{0: RegionalDataHandler, 1: \ArrayObject<int, array<string,mixed>>} */
    private function handlerCapturingOutbox(array $path): array
    {
        $handler  = new RegionalDataHandler($path);
        $captured = new \ArrayObject();
        $repo     = $this->createMock(OutboxBatchInsertInterface::class);
        $repo->method('insertBatch')->willReturnCallback(static function (array $rows) use ($captured): array {
            foreach ($rows as $row) {
                $captured->append($row);
            }
            return range(1, count($rows));
        });
        $handler->setOutboxRepository($repo);

        return [$handler, $captured];
    }
```

and tests (without forcing OpenFGA configured, so rows are captured and nothing is processed synchronously):

```php
    public function testPatchWritesAddedRegionsAndDeletesRemovedOnes(): void
    {
        self::writeRegion('Balkans', ['Croatia' => 'HR'], ['hr_HR']);
        $payload                              = self::shippedNationalCalendarPayload('HR');
        $payload['metadata']['wider_regions'] = ['Balkans'];

        [$handler, $rows] = $this->handlerCapturingOutbox(['nation', 'HR']);
        $handler->handle($this->requestFor('PATCH', '/data/nation/HR', ['Accept-Language' => 'hr-HR'], $payload));

        $summary = array_map(static fn ($r) => $r['operation']->value . ' ' . $r['fga_object'], $rows->getArrayCopy());
        self::assertSame(['write_tuple wider_region:roman/Balkans', 'delete_tuple wider_region:roman/Europe'], $summary);
    }

    public function testPatchKeepingTheSameRegionsEnqueuesNothing(): void
    {
        [$handler, $rows] = $this->handlerCapturingOutbox(['nation', 'HR']);
        $handler->handle($this->requestFor('PATCH', '/data/nation/HR', ['Accept-Language' => 'hr-HR'], self::shippedNationalCalendarPayload('HR')));

        self::assertCount(0, $rows);
    }

    public function testDeletingANationRemovesItsMembership(): void
    {
        [$handler, $rows] = $this->handlerCapturingOutbox(['nation', 'HR']);
        $handler->handle($this->requestFor('DELETE', '/data/nation/HR'));

        $summary = array_map(static fn ($r) => $r['operation']->value . ' ' . $r['fga_object'], $rows->getArrayCopy());
        self::assertSame(['delete_tuple wider_region:roman/Europe'], $summary);
    }
```

Update the existing `testCreateNationalCalendarEnqueuesMemberNationTuple` only if its callback depends on the old
metadata or key shape (it matches `fga_user`, `fga_relation`, `fga_object`, which do not change).

In `phpunit_tests/Handlers/RegionalDataQueueModeTest.php`, add a test that a queued national PUT enqueues no outbox
rows: inject the capturing mock the same way (read that file's setup for how it forces queue mode), issue the PUT,
and `self::assertCount(0, $rows)`.

- [ ] **Step 6: Run to verify failure**

Run: `vendor/bin/phpunit phpunit_tests/Handlers/RegionalDataHandlerTest.php phpunit_tests/Handlers/RegionalDataQueueModeTest.php --filter 'Membership|Region|Queued'`
Expected: FAIL: PATCH and DELETE enqueue nothing; a queued PUT enqueues a row.

- [ ] **Step 7: One enqueue helper, used by all three paths**

Extract the enqueue-and-process body of `createNationalCalendar()` (from `$repo = $this->getOutboxRepository();`
through the `processSync()` loop) into:

```php
    /**
     * Enqueue a nation's membership diff and, when OpenFGA is configured, process it synchronously (#1005).
     *
     * Only for a write that was applied: a queued change request has changed nothing yet, and its membership is synced
     * by MergePollRunner once it merges.
     *
     * @param list<string> $before
     * @param list<string> $after
     */
    private function syncWiderRegionMembership(string $nation, array $before, array $after): void
    {
        $rows = WiderRegionMembershipSync::rowsFor($nation, $before, $after, WiderRegionMembershipSync::newEpisode());
        if ($rows === [] || !( OpenFgaClient::isConfigured() || $this->outboxRepository !== null )) {
            return;
        }

        $repo = $this->getOutboxRepository();
        $pdo  = OpenFgaClient::isConfigured() ? $this->getOutboxPdo() : null;
        if ($pdo !== null) {
            $pdo->beginTransaction();
        }
        try {
            $ids = $repo->insertBatch($rows);
            if ($pdo !== null) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($pdo !== null && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        if (OpenFgaClient::isConfigured()) {
            $processor = new OutboxProcessor(new OutboxRepository($this->getOutboxPdo()), $this->getFgaClient());
            foreach ($ids as $id) {
                $processor->processSync($id);
            }
        }
    }
```

Then:

- `createNationalCalendar()`: replace the whole `$widerRegion = ...; if ($widerRegion !== '' && ...) { ... }` block with

  ```php
          if (( $changeRequest['disposition'] ?? null ) === 'applied') {
              $this->syncWiderRegionMembership($nation, [], $payload->metadata->wider_regions);
          }
  ```

- `updateNationalCalendar()`: after `commitStagedFiles()`, with `$nationEntry` (already looked up at the top of the
  method from `$this->CalendarsMetadata`, so it holds the stored file's list):

  ```php
          if (( $changeRequest['disposition'] ?? null ) === 'applied') {
              $this->syncWiderRegionMembership($key, $nationEntry->wider_regions, $payload->metadata->wider_regions);
          }
  ```

- `deleteCalendar()`: inside the existing `if (( $changeRequest['disposition'] ?? null ) === 'applied')` block, for the
  nation category only, before the purge:

  ```php
              if ($this->params->category === PathCategory::NATION) {
                  $stored = array_find($this->CalendarsMetadata->national_calendars, fn ($n) => $n->calendar_id === $this->params->key);
                  $this->syncWiderRegionMembership((string) $this->params->key, $stored->wider_regions ?? [], []);
              }
  ```

Import `WiderRegionMembershipSync`. Confirm with `grep -n "member_nation" src/Handlers/RegionalDataHandler.php` that
no inline row construction remains.

- [ ] **Step 8: Run and verify**

Run: `vendor/bin/phpunit phpunit_tests/Handlers/RegionalDataHandlerTest.php phpunit_tests/Handlers/RegionalDataQueueModeTest.php phpunit_tests/Services/WiderRegionMembershipSyncTest.php`
Expected: PASS (including the existing `testCreateNationalCalendarEnqueuesMemberNationTuple`, which needs Postgres;
confirm it ran rather than skipped).
Run: `composer test:quick && composer analyse && composer lint`
Expected: all pass.

- [ ] **Step 9: Commit**

```bash
git add src/Services/WiderRegionMembershipSync.php src/Handlers/RegionalDataHandler.php phpunit_tests/
git commit -m "feat(access): keep member_nation tuples in step on national PUT, PATCH and DELETE (#1005)"
```

---

### Task 8: `WiderRegionMembershipReconciler`, and the seeder becomes a reconcile

**Files:**

- Create: `src/Services/WiderRegionMembershipReconciler.php`
- Modify: `src/Services/WiderRegionMembershipSeeder.php`, `scripts/seed-wider-region-membership.php`
- Test: `phpunit_tests/Services/WiderRegionMembershipReconcilerTest.php` (new),
  `phpunit_tests/Services/WiderRegionMembershipSeederTest.php`

**Interfaces:**

- Consumes: `WiderRegionMembershipSync::user()`, `object()`, `RELATION` (Task 7); `OpenFgaClient::readTuples()`,
  `writeTuple()`, `deleteTuple()`.
- Produces:
  - `new WiderRegionMembershipReconciler(OpenFgaClient $client)`
  - `->currentRegions(string $nation): array{qualified: list<string>, legacy: list<array{user: string, relation: string, object: string}>}`
  - `->syncNation(string $nation, array $regions, bool $apply = true): array{writes: list<string>, deletes: list<string>}`
    (the triples as `object#relation@user` strings; performed only when `$apply`)
  - `->nationsWithTuples(): list<string>`
  - `WiderRegionMembershipSeeder::declaredRegions(string $nationsDir): array<string, list<string>>` (nation => regions)
  - `WiderRegionMembershipSeeder::reconcile(WiderRegionMembershipReconciler $r, string $nationsDir, bool $apply): array{writes: list<string>, deletes: list<string>}`

- [ ] **Step 1: Write the failing tests**

`phpunit_tests/Services/WiderRegionMembershipReconcilerTest.php`, using the Guguzzle `MockHandler` pattern from
`phpunit_tests/Services/OpenFgaClientTest.php` (read it first and reuse its client-construction helper). Queue one
`read` response per `readTuples()` call, then one `200 {}` per write or delete, and inspect the recorded requests:

```php
    public function testSyncWritesMissingRegionsAndDeletesUndeclaredOnes(): void
    {
        // Read #1 (qualified user): the nation is in Europe and Scandinavia. Read #2 (legacy unqualified user): none.
        [$client, $history] = $this->clientWith([
            self::readResponse([
                ['national_calendar:roman/SE', 'member_nation', 'wider_region:roman/Europe'],
                ['national_calendar:roman/SE', 'member_nation', 'wider_region:roman/Scandinavia'],
            ]),
            self::readResponse([]),
            new Response(200, [], '{}'), // write Nordic
            new Response(200, [], '{}'), // delete Scandinavia
        ]);

        $result = ( new WiderRegionMembershipReconciler($client) )->syncNation('SE', ['Europe', 'Nordic']);

        self::assertSame(['wider_region:roman/Nordic#member_nation@national_calendar:roman/SE'], $result['writes']);
        self::assertSame(['wider_region:roman/Scandinavia#member_nation@national_calendar:roman/SE'], $result['deletes']);
        self::assertCount(4, $history);
    }

    public function testUnqualifiedLegacyTuplesAreReplacedByQualifiedOnes(): void
    {
        [$client] = $this->clientWith([
            self::readResponse([]),
            self::readResponse([['national_calendar:IT', 'member_nation', 'wider_region:Europe']]),
            new Response(200, [], '{}'), // write qualified
            new Response(200, [], '{}'), // delete legacy
        ]);

        $result = ( new WiderRegionMembershipReconciler($client) )->syncNation('IT', ['Europe']);

        self::assertSame(['wider_region:roman/Europe#member_nation@national_calendar:roman/IT'], $result['writes']);
        self::assertSame(['wider_region:Europe#member_nation@national_calendar:IT'], $result['deletes']);
    }

    public function testADryRunPlansWithoutWriting(): void
    {
        [$client, $history] = $this->clientWith([self::readResponse([]), self::readResponse([])]);

        $result = ( new WiderRegionMembershipReconciler($client) )->syncNation('IT', ['Europe'], apply: false);

        self::assertCount(1, $result['writes']);
        self::assertCount(2, $history, 'Only the two reads');
    }
```

with helpers `clientWith(list<Response>): array{0: OpenFgaClient, 1: \ArrayObject}` (a `HandlerStack` with
`Middleware::history()`) and `readResponse(list<array{string,string,string}>): Response` returning
`{"tuples":[{"key":{"user":..,"relation":..,"object":..}}],"continuation_token":""}`.

In `WiderRegionMembershipSeederTest`, replace the `computeTuples()` assertions with `declaredRegions()`: a temp nations
dir with `SE/SE.json` `{"metadata":{"wider_regions":["Europe","Nordic"]}}`, `IT/IT.json` with the legacy
`{"metadata":{"wider_region":"Europe"}}`, and `XX/XX.json` with no region, must yield
`['IT' => ['Europe'], 'SE' => ['Europe', 'Nordic'], 'XX' => []]`.

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit phpunit_tests/Services/WiderRegionMembershipReconcilerTest.php phpunit_tests/Services/WiderRegionMembershipSeederTest.php`
Expected: errors, class/method not found.

- [ ] **Step 3: Implement the reconciler**

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services;

use LiturgicalCalendar\Api\Services\Exception\TupleAlreadyExistsException;

/**
 * Brings a nation's `member_nation` tuples in OpenFGA to what its source file declares (#1005).
 *
 * Unlike the `/data` write paths, which know the before and after lists and go through the outbox, this reads the
 * "before" from OpenFGA itself: it is used after a change request merges (MergePollRunner) and by the seeder, where
 * the stored tuples, not a file, are what may be stale. It also replaces unqualified tuples left by the seeder's
 * pre-#1005 versions (`national_calendar:IT`, `wider_region:Europe`) with rite-qualified ones.
 */
final class WiderRegionMembershipReconciler
{
    public function __construct(private readonly OpenFgaClient $client)
    {
    }

    /**
     * @return array{qualified: list<string>, legacy: list<array{user: string, relation: string, object: string}>}
     */
    public function currentRegions(string $nation): array
    {
        $qualified = [];
        foreach ($this->read(WiderRegionMembershipSync::user($nation)) as $tuple) {
            $parsed = RiteScopedObjectId::parse(substr($tuple['object'], strlen('wider_region:')));
            if (null !== $parsed) {
                $qualified[] = $parsed[1];
            }
        }

        return ['qualified' => $qualified, 'legacy' => $this->read("national_calendar:{$nation}")];
    }

    /**
     * @param list<string> $regions What the nation's file declares.
     * @return array{writes: list<string>, deletes: list<string>}
     */
    public function syncNation(string $nation, array $regions, bool $apply = true): array
    {
        $current = $this->currentRegions($nation);
        $writes  = [];
        $deletes = [];

        foreach (array_values(array_diff($regions, $current['qualified'])) as $region) {
            $writes[] = [WiderRegionMembershipSync::user($nation), WiderRegionMembershipSync::RELATION, WiderRegionMembershipSync::object($region)];
        }
        foreach (array_values(array_diff($current['qualified'], $regions)) as $region) {
            $deletes[] = [WiderRegionMembershipSync::user($nation), WiderRegionMembershipSync::RELATION, WiderRegionMembershipSync::object($region)];
        }
        foreach ($current['legacy'] as $tuple) {
            $deletes[] = [$tuple['user'], $tuple['relation'], $tuple['object']];
        }

        if ($apply) {
            foreach ($writes as [$user, $relation, $object]) {
                try {
                    $this->client->writeTuple($user, $relation, $object);
                } catch (TupleAlreadyExistsException) {
                    // benign: already there
                }
            }
            foreach ($deletes as [$user, $relation, $object]) {
                $this->client->deleteTuple($user, $relation, $object);
            }
        }

        $format = static fn (array $t): string => "{$t[2]}#{$t[1]}@{$t[0]}";

        return ['writes' => array_map($format, $writes), 'deletes' => array_map($format, $deletes)];
    }

    /**
     * Nations that currently hold any `member_nation` tuple, qualified or not, so the seeder can prune nations whose
     * file is gone.
     *
     * @return list<string>
     */
    public function nationsWithTuples(): array
    {
        $nations = [];
        $token   = null;
        do {
            $page = $this->client->readTuples('', '', null, null, $token);
            foreach ($page['tuples'] as $tuple) {
                if ($tuple['relation'] !== WiderRegionMembershipSync::RELATION || !str_starts_with($tuple['user'], 'national_calendar:')) {
                    continue;
                }
                $id        = substr($tuple['user'], strlen('national_calendar:'));
                $nations[] = RiteScopedObjectId::parse($id)[1] ?? $id;
            }
            $token = $page['next_continuation_token'] !== '' ? $page['next_continuation_token'] : null;
        } while ($token !== null);

        $nations = array_values(array_unique($nations));
        sort($nations);

        return $nations;
    }

    /**
     * @return list<array{user: string, relation: string, object: string}>
     */
    private function read(string $user): array
    {
        $tuples = [];
        $token  = null;
        do {
            $page   = $this->client->readTuples($user, 'wider_region:', WiderRegionMembershipSync::RELATION, null, $token);
            $tuples = array_merge($tuples, $page['tuples']);
            $token  = $page['next_continuation_token'] !== '' ? $page['next_continuation_token'] : null;
        } while ($token !== null);

        return $tuples;
    }
}
```

(Check `OpenFgaClient::deleteTuple()`'s behaviour for a tuple that does not exist; if it throws a specific exception,
catch it the way `writeTuple()`'s `TupleAlreadyExistsException` is caught.)

- [ ] **Step 4: Rewrite the seeder and its script**

Replace `WiderRegionMembershipSeeder`'s `computeTuples()` and `seed()` with:

```php
    /**
     * What every national calendar file declares, nation => regions, most general first.
     *
     * @return array<string, list<string>>
     */
    public function declaredRegions(string $nationsDir): array
    {
        $declared = [];
        $dirs     = glob($nationsDir . '/*', GLOB_ONLYDIR);
        if ($dirs === false) {
            return [];
        }
        foreach ($dirs as $dir) {
            $nation = basename($dir);
            $file   = "{$dir}/{$nation}.json";
            if (!is_file($file)) {
                continue;
            }
            $raw  = file_get_contents($file);
            $data = $raw === false ? null : json_decode($raw, true);
            if (!is_array($data)) {
                throw new \RuntimeException("Unreadable or invalid national calendar file: {$file}");
            }
            $meta   = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
            $list   = $meta['wider_regions'] ?? null;
            $legacy = $meta['wider_region'] ?? null;
            $declared[$nation] = is_array($list)
                ? array_values(array_filter($list, 'is_string'))
                : ( is_string($legacy) && $legacy !== '' ? [$legacy] : [] );
        }
        ksort($declared);

        return $declared;
    }

    /**
     * Reconcile every nation: those with a file to what it declares, those holding tuples but no file to none.
     *
     * @return array{writes: list<string>, deletes: list<string>}
     */
    public function reconcile(WiderRegionMembershipReconciler $reconciler, string $nationsDir, bool $apply): array
    {
        $declared = $this->declaredRegions($nationsDir);
        foreach ($reconciler->nationsWithTuples() as $nation) {
            $declared[$nation] ??= [];
        }

        $writes  = [];
        $deletes = [];
        foreach ($declared as $nation => $regions) {
            $result  = $reconciler->syncNation($nation, $regions, $apply);
            $writes  = array_merge($writes, $result['writes']);
            $deletes = array_merge($deletes, $result['deletes']);
        }

        return ['writes' => $writes, 'deletes' => $deletes];
    }
```

In `scripts/seed-wider-region-membership.php`, replace everything from `$seeder = new ...` to the end with:

```php
if (!OpenFgaClient::isConfigured()) {
    fwrite(STDERR, "Error: OpenFGA is not configured. Set OPENFGA_API_URL, OPENFGA_STORE_ID, and OPENFGA_MODEL_ID.\n");
    exit(1);
}

$result = ( new WiderRegionMembershipSeeder() )->reconcile(
    new WiderRegionMembershipReconciler(OpenFgaClient::fromEnv()),
    JsonData::NATIONAL_CALENDARS_FOLDER->path(),
    $apply
);
foreach ($result['writes'] as $t) {
    echo "+ {$t}" . PHP_EOL;
}
foreach ($result['deletes'] as $t) {
    echo "- {$t}" . PHP_EOL;
}
echo PHP_EOL . sprintf(
    "%s: %d writes, %d deletes%s\n",
    $apply ? 'Applied' : 'Planned',
    count($result['writes']),
    count($result['deletes']),
    $apply ? '' : ' (dry run - pass --apply to write)'
);
exit(0);
```

(The dry run now needs OpenFGA, because pruning is computed from the tuples that exist. Update the script's header
comment accordingly, and import `WiderRegionMembershipReconciler`.)

- [ ] **Step 5: Run and verify**

Run: `vendor/bin/phpunit phpunit_tests/Services/WiderRegionMembershipReconcilerTest.php phpunit_tests/Services/WiderRegionMembershipSeederTest.php`
Expected: PASS.
Run: `php -l scripts/seed-wider-region-membership.php && composer test:quick && composer analyse && composer lint`
Expected: all pass.

- [ ] **Step 6: Commit**

```bash
git add src/Services/WiderRegionMembershipReconciler.php src/Services/WiderRegionMembershipSeeder.php \
  scripts/seed-wider-region-membership.php phpunit_tests/Services/
git commit -m "feat(access): reconcile member_nation tuples from the source files, qualifying and pruning (#1005)"
```

---

### Task 9: `MergePollRunner` syncs membership after a merge

**Files:**

- Modify: `src/Services/SourceData/MergePollRunner.php`, `src/Services/SourceData/SourceDataPublisherFactory.php`
- Test: `phpunit_tests/Services/SourceData/MergePollRunnerTest.php`

**Interfaces:**

- Consumes: `WiderRegionMembershipReconciler::syncNation()` (Task 8).
- Produces: `interface WiderRegionMembershipSyncer { public function syncNation(string $nation, array $regions, bool $apply = true): array; }`;
  `MergePollRunner::__construct(..., ?LoggerInterface $logger = null, ?WiderRegionMembershipSyncer $membership = null)`.

- [ ] **Step 1: Write the failing tests**

The reconciler is `final` and talks to OpenFGA, so the runner depends on a one-method interface instead, which this
task creates (`src/Services/WiderRegionMembershipSyncer.php`, implemented by `WiderRegionMembershipReconciler`).
Create the test double beside `RecordingTuplePurgeService`:

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services\SourceData;

use LiturgicalCalendar\Api\Services\WiderRegionMembershipSyncer;

/** Records each nation's synced region list, so a test can assert on exactly what the runner asked for. */
final class RecordingMembershipSyncer implements WiderRegionMembershipSyncer
{
    /** @var array<string, list<string>> nation => regions */
    public array $synced = [];

    public function syncNation(string $nation, array $regions, bool $apply = true): array
    {
        $this->synced[$nation] = $regions;

        return ['writes' => [], 'deletes' => []];
    }
}
```

In `MergePollRunnerTest`, give `runnerFor()` a fourth parameter `?RecordingMembershipSyncer $membership = null` and
pass it as `membership: $membership` to the `MergePollRunner` constructor. Add a helper that publishes a one-row batch
with a given path, operation and content (it is `publishedBatch()` generalised; keep `publishedBatch()` as is):

```php
    private function publishedRow(ChangeResource $resource, string $path, ChangeOperation $operation, ?string $content, int $prNumber, string $commitSha): string
    {
        $batchId = $this->repo->submitBatch(
            $resource,
            [['path' => $path, 'operation' => $operation, 'content' => $content]],
            'editor-1',
            'Editor',
            'editor-1@example.test',
            true
        )['batch_id'];

        $this->repo->approveBatch($batchId, 'reviewer-1');
        self::assertNotNull($this->repo->claimNextPublishableBatch());
        $this->repo->recordPublication($batchId, 'litcal-data/' . $resource->type . '/' . $resource->id, $commitSha, $prNumber, 'base');

        return $batchId;
    }

    /** @return list<GuzzleResponse> A merged pull request whose merge contains the batch. */
    private static function mergedContaining(string $commitSha): array
    {
        return [
            self::prJson('closed', true, 'merge-sha', $commitSha),
            new GuzzleResponse(200, [], json_encode(['status' => 'identical'], JSON_THROW_ON_ERROR)),
        ];
    }
```

Then the tests:

```php
    public function testAMergedNationalUpdateSyncsThatNationsRegions(): void
    {
        $this->publishedRow(
            ChangeResource::nationalCalendar(Rite::ROMAN, 'SE'),
            'jsondata/sourcedata/rite/roman/calendars/nations/SE/SE.json',
            ChangeOperation::UPDATE,
            '{"metadata":{"wider_regions":["Europe","Nordic"]}}',
            21,
            'sha-se'
        );
        $membership = new RecordingMembershipSyncer();

        $this->runnerFor(self::mergedContaining('sha-se'), membership: $membership)->runOnce();

        self::assertSame(['SE' => ['Europe', 'Nordic']], $membership->synced);
    }

    public function testAMergedNationalDeletionSyncsToNoRegions(): void
    {
        $this->deletionBatch('editor-1', 'SE', 22, 'sha-del');
        $membership = new RecordingMembershipSyncer();

        $this->runnerFor(self::mergedContaining('sha-del'), membership: $membership)->runOnce();

        self::assertSame(['SE' => []], $membership->synced);
    }

    public function testAMergedBatchWithoutANationalFileSyncsNothing(): void
    {
        $this->publishedRow(
            ChangeResource::widerRegion('Europe'),
            'jsondata/sourcedata/rite/roman/calendars/wider_regions/Europe/Europe.json',
            ChangeOperation::UPDATE,
            '{"litcal":[]}',
            23,
            'sha-eu'
        );
        $membership = new RecordingMembershipSyncer();

        $this->runnerFor(self::mergedContaining('sha-eu'), membership: $membership)->runOnce();

        self::assertSame([], $membership->synced);
    }

    public function testANationalI18nFileIsNotMistakenForTheCalendarFile(): void
    {
        $this->publishedRow(
            ChangeResource::nationalCalendar(Rite::ROMAN, 'SE'),
            'jsondata/sourcedata/rite/roman/calendars/nations/SE/i18n/sv_SE.json',
            ChangeOperation::UPDATE,
            '{"StBridget":"Heliga Birgitta"}',
            24,
            'sha-i18n'
        );
        $membership = new RecordingMembershipSyncer();

        $this->runnerFor(self::mergedContaining('sha-i18n'), membership: $membership)->runOnce();

        self::assertSame([], $membership->synced);
    }
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit phpunit_tests/Services/SourceData/MergePollRunnerTest.php --filter Merged`
Expected: FAIL: `syncNation` is never called.

- [ ] **Step 3: Implement**

Create `src/Services/WiderRegionMembershipSyncer.php`:

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services;

/** Brings a nation's `member_nation` tuples to a given list of wider regions (#1005). */
interface WiderRegionMembershipSyncer
{
    /**
     * @param list<string> $regions
     * @return array{writes: list<string>, deletes: list<string>}
     */
    public function syncNation(string $nation, array $regions, bool $apply = true): array;
}
```

and declare `final class WiderRegionMembershipReconciler implements WiderRegionMembershipSyncer`.

Add the constructor parameter `private readonly ?WiderRegionMembershipSyncer $membership = null` after `$logger`
(a promoted property; `$logger` itself is not promoted, so keep its assignment in the body).
After `$this->purgeIfResourceDeletion($batch['batch_id']);`, call `$this->syncWiderRegionMembership($batch['batch_id']);`
and add:

```php
    /**
     * After a merge, bring the membership of every nation whose calendar file the batch touched to what the merged
     * file declares (#1005). The merged content is the batch row's own `content` (a deletion has none, so no
     * regions): that is what the merge put on `development`, so this does not wait for the server to pull it.
     * Best-effort like the purge: a failure leaves the tuples for the seeder's reconcile.
     */
    private function syncWiderRegionMembership(string $batchId): void
    {
        if (null === $this->membership) {
            return;
        }

        // NATIONAL_CALENDAR_FILE is `.../nations/{nation}/{nation}.json`: the second placeholder must equal the first.
        $pattern = '#^' . str_replace(
            preg_quote('{nation}/{nation}.json', '#'),
            '([A-Z]{2})/\1\.json',
            preg_quote(JsonDataConstants::NATIONAL_CALENDAR_FILE, '#')
        ) . '$#';
        try {
            foreach ($this->repository->getBatch($batchId) as $row) {
                $path = is_string($row['path'] ?? null) ? $row['path'] : '';
                if (1 !== preg_match($pattern, $path, $m)) {
                    continue;
                }
                $regions = [];
                if (( $row['operation'] ?? null ) !== ChangeOperation::DELETE->value && is_string($row['content'] ?? null)) {
                    $data   = json_decode($row['content'], true);
                    $list   = is_array($data) ? ( $data['metadata']['wider_regions'] ?? [] ) : [];
                    $regions = is_array($list) ? array_values(array_filter($list, 'is_string')) : [];
                }
                $this->membership->syncNation($m[1], $regions);
            }
        } catch (\Throwable $e) {
            $this->logger->error(
                'Syncing wider region membership after a merge failed; the merge stands and the seeder reconcile will repair it.',
                ['batch_id' => $batchId, 'exception' => $e::class, 'message' => $e->getMessage()]
            );
        }
    }
```

Import `JsonDataConstants` and `ChangeOperation`. The pattern is exercised by the four tests above, including the
i18n file that must not match.

In `SourceDataPublisherFactory` (line ~149), pass
`membership: OpenFgaClient::isConfigured() ? new WiderRegionMembershipReconciler(OpenFgaClient::fromEnv()) : null`.

- [ ] **Step 4: Run and verify**

Run: `vendor/bin/phpunit phpunit_tests/Services/SourceData/`
Expected: PASS (confirm the DB-backed tests ran).
Run: `composer test:quick && composer analyse && composer lint`
Expected: all pass.

- [ ] **Step 5: Commit**

```bash
git add src/Services/ phpunit_tests/Services/SourceData/MergePollRunnerTest.php
git commit -m "feat(publish): sync a nation's wider region membership once its change request merges (#1005)"
```

---

### Task 10: `/health` reports membership drift

**Files:**

- Modify: `src/Health.php` (new `buildWiderRegionMembershipStatus()`), `src/Handlers/Ops/HealthHandler.php`
- Test: `phpunit_tests/HealthWiderRegionMembershipTest.php` (new), `phpunit_tests/Handlers/Ops/HealthHandlerTest.php`

**Interfaces:**

- Produces: `Health::buildWiderRegionMembershipStatus(?string $root = null): array{status: 'ok'|'warning', message: string, drift: array<string, list<string>>}`
  (`drift`: nation => list of problems).

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests;

use LiturgicalCalendar\Api\Health;
use LiturgicalCalendar\Tests\Support\PinsRouterPathsTrait;
use LiturgicalCalendar\Tests\Support\ShadowProjectRootTrait;
use PHPUnit\Framework\TestCase;

final class HealthWiderRegionMembershipTest extends TestCase
{
    use PinsRouterPathsTrait;
    use ShadowProjectRootTrait;

    public static function setUpBeforeClass(): void
    {
        self::pinRouterPaths();
    }

    public static function tearDownAfterClass(): void
    {
        self::restoreRouterPaths();
    }

    public function testTheShippedDataHasNoDrift(): void
    {
        $block = Health::buildWiderRegionMembershipStatus();

        self::assertSame('ok', $block['status']);
        self::assertSame([], $block['drift']);
    }

    public function testANationDeclaringAnUnknownOrUnlistingRegionIsReported(): void
    {
        $root = self::createShadowProjectRoot(dirname(__DIR__) . DIRECTORY_SEPARATOR, 'litcal-health-membership');
        try {
            $file = $root . '/jsondata/sourcedata/rite/roman/calendars/nations/IT/IT.json';
            $it   = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($it);
            $it['metadata']['wider_regions'] = ['Europe', 'Americas', 'Atlantis'];
            file_put_contents($file, json_encode($it, JSON_THROW_ON_ERROR));

            $block = Health::buildWiderRegionMembershipStatus($root . DIRECTORY_SEPARATOR);

            self::assertSame('warning', $block['status']);
            self::assertCount(2, $block['drift']['IT']);
            self::assertStringContainsString('Americas', $block['drift']['IT'][0]);
            self::assertStringContainsString('Atlantis', $block['drift']['IT'][1]);
        } finally {
            self::removeTree($root);
        }
    }
}
```

In `HealthHandlerTest`, assert the response carries a `wider_region_membership` block with a `status`.

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit phpunit_tests/HealthWiderRegionMembershipTest.php`
Expected: error, undefined method.

- [ ] **Step 3: Implement**

In `Health.php`, next to `buildLocaleReadinessStatus()`:

```php
    /**
     * Wider region membership as the source files record it (#1005): every region a nation declares must exist and
     * must list the nation in its `national_calendars` roster. The reverse is not checked: a roster entry for a nation
     * that does not declare the region is a prospective member.
     *
     * Nested status, like `locale_readiness`: a `warning` is a content defect to fix and does not change /health's
     * top-level status or HTTP code.
     *
     * @param ?string $root A project root other than the running one (tests).
     * @return array{status: 'ok'|'warning', message: string, drift: array<string, list<string>>}
     */
    public static function buildWiderRegionMembershipStatus(?string $root = null): array
    {
        $root ??= Router::$apiFilePath;
        $nations = $root . JsonDataConstants::NATIONAL_CALENDARS_FOLDER;
        $regions = $root . JsonDataConstants::WIDER_REGIONS_FOLDER;

        try {
            $declared = ( new WiderRegionMembershipSeeder() )->declaredRegions($nations);
            $drift    = [];
            foreach ($declared as $nation => $list) {
                foreach ($list as $region) {
                    $file = "{$regions}/{$region}/{$region}.json";
                    if (!is_file($file)) {
                        $drift[$nation][] = "declares {$region}, which has no wider region file";
                        continue;
                    }
                    $data    = json_decode((string) file_get_contents($file), true);
                    $members = is_array($data) && is_array($data['national_calendars'] ?? null) ? $data['national_calendars'] : [];
                    if (!in_array($nation, $members, true)) {
                        $drift[$nation][] = "declares {$region}, whose national_calendars does not list it";
                    }
                }
            }
        } catch (\Throwable $e) {
            return ['status' => 'warning', 'message' => 'wider region membership could not be evaluated: ' . $e->getMessage(), 'drift' => []];
        }

        return $drift === []
            ? ['status' => 'ok', 'message' => 'every declared wider region exists and lists its nation', 'drift' => []]
            : ['status' => 'warning', 'message' => count($drift) . ' national calendar(s) declare a wider region that does not accept them', 'drift' => $drift];
    }
```

(`JsonDataConstants` values are root-relative with no leading separator, e.g. `jsondata/sourcedata/...`, and
`$root` always ends with one, as `Router::$apiFilePath` does, so plain concatenation is correct.) In `HealthHandler`, add
`'wider_region_membership' => Health::buildWiderRegionMembershipStatus(),` after `locale_readiness`.

- [ ] **Step 4: Run and verify**

Run: `vendor/bin/phpunit phpunit_tests/HealthWiderRegionMembershipTest.php phpunit_tests/Handlers/Ops/HealthHandlerTest.php`
Expected: PASS.
Run: `composer test:quick && composer analyse && composer lint`
Expected: all pass.

- [ ] **Step 5: Commit**

```bash
git add src/Health.php src/Handlers/Ops/HealthHandler.php phpunit_tests/
git commit -m "feat(health): report nations declaring a wider region that does not accept them (#1005)"
```

---

### Task 11: Documentation, final verification, PR

**Files:**

- Modify: `CLAUDE.md` (the "Data Sources" wider-region bullet), `README.md` if it describes `wider_region`
- Modify: `docs/superpowers/specs/2026-09-28-multiple-wider-regions-design.md` (Status: Implemented)

- [ ] **Step 1: Search for stale references**

```bash
grep -rn "wider_region\b" src scripts --include=*.php | grep -v "wider_regions\|deprecated\|legacy"
grep -rn "wider_region\b" CLAUDE.md README.md docs/ --include=*.md | grep -v superpowers
```

Expected: the only `wider_region` hits in `src` are the region's own id (`WiderRegionMetadata`, `{wider_region}` path
placeholders), the deprecated-form readers and writers, and comments that name it as deprecated. Fix any other.

- [ ] **Step 2: Document the upgrade step**

In `CLAUDE.md`'s Data Sources section, describe `metadata.wider_regions` (ordered, most general first; each region
must list the nation), and add under "Local Development Bootstrap" or the RBAC runbook reference: "After deploying the
change for issue 1005, run `php scripts/seed-wider-region-membership.php` (dry run), review, then `--apply`, to qualify and prune the
existing `member_nation` tuples." Run `composer lint:md`.

- [ ] **Step 3: Full verification**

```bash
rm -rf engineCache/ /tmp/phpstan
composer test:quick && composer analyse && composer lint && composer lint:md \
  && composer lint:openapi && composer lint:jsondata && composer parallel-lint
vendor/bin/phpunit --filter CalendarGoldenMaster phpunit_tests/Handlers/CalendarGoldenMasterTest.php
```

Expected: all pass, golden masters 9/9.

- [ ] **Step 4: Commit, push, open the PR**

```bash
git add CLAUDE.md README.md docs/
git commit -m "docs: wider_regions and the membership reconcile step (#1005)"
git push -u origin feat/1005-multiple-wider-regions
```

Open the PR against `development` with `Closes #1005`. The description must include: the approach, the list of event
keys whose readings changed in the golden-master commit, the one-off seeder step for deploy, the three client
follow-ups (Frontend, `liturgy-components-js`, `liturgy-components-php`), and the `Europe/lectionary/en_UK.json` note.

- [ ] **Step 5: After merge**

File the four follow-up issues of spec section 11, each linking #1005.
