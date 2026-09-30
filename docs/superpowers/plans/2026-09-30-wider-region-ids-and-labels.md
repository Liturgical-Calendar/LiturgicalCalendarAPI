# Wider region ids and labels — implementation plan (#1018)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or
> superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give every wider region a permanent lowercase kebab-case id and a set of labels (one per language). Serve
the label for the request's language from `/calendars`, and migrate the three existing regions (`Americas`, `Asia`,
`Europe`) together with every live reference to them.

**Architecture:** Two small value classes hold the rules:

- `WiderRegionId`: the id's shape, and mapping a legacy name to an id.
- `WiderRegionLabels`: which label keys are allowed, and how a label is resolved.

Models, schemas, the Router and the handlers call them. Nothing re-implements the rules. The repository data is
renamed in the same commit that makes ids mandatory, so the tree is never half-migrated. Live state is migrated
separately after each deploy:

- Postgres, by a Doctrine migration that runs automatically during the deploy;
- OpenFGA, by a script the operator runs.

**Tech Stack:** PHP 8.4, PHPUnit 12, PHPStan level 10, JSON Schema (draft 7), Doctrine Migrations, OpenFGA,
ICU (`intl`).

**Spec:** `docs/superpowers/specs/2026-09-30-wider-region-ids-and-labels-design.md`

## Global Constraints

- Id pattern (PHP): `/^[a-z]+(-[a-z]+)*$/`. JSON Schema: `^[a-z]+(-[a-z]+)*$`.
- Legacy name pattern (unchanged from #1005): `/^[A-Z][A-Za-z]*( [A-Z][A-Za-z]*)*$/`.
- Legacy → id mapping: `strtolower(str_replace(' ', '-', $name))`. Nothing else, and no lookup table.
- Label key pattern: `/^[a-z]{2,3}(_[A-Z][a-z]{3})?$/`. Allowed keys are `en`, plus, for every declared locale, its
  primary language and (when it has a script) `language_Script`.
- Label resolution order: `language_Script` → `language` → `en` → `WiderRegionId::humanize($id)`.
- `/calendars` wider region item: `id`, `label`, `name` (deprecated, equal to `id`), `locales`, `api_path`,
  `national_calendars`, `roster`.
- Existing regions: `americas` = CLDR `019`, `asia` = `142`, `europe` = `150`.
- Work only in the worktree `../LiturgicalCalendarAPI-wr-ids` (branch `feat/wider-region-ids-1018`). The main checkout
  is shared by concurrent agents: never `git checkout` or commit there.
- Every commit ends with the session's `Co-Authored-By` / `Claude-Session` lines. Never `--no-verify`.
- Markdown ≤ 180 columns and aligned tables. Run `npx --yes markdownlint-cli2 <file>` after editing a `.md`.
- `jsondata/` source files and `openapi.json` must pass `composer lint:jsondata` (canonical encoding,
  `ensure_ascii=False`). Rewrite them with `JsonFormatter::encode()` or the project's formatter, never a hand-rolled
  `json.dumps`.
- A new test goes in the `slow` group only if its runtime cost was measured. None here qualifies.

## Review Focus

1. **A legacy path from the old Frontend** (`PUT /data/widerregion/Europe`) must authorize against
   `wider_region:roman/europe` and write `europe/europe.json`, with no `Europe/` folder left behind (Task 5).
2. **An `Accept-Language` the labels don't cover.**
   - `zh-Hant-TW` on Asia, which has only `zh_Hans` and `en`, must give the English label, not the Simplified one.
   - A lowercase negotiated tag (`zh_hans_cn`) must still match `zh_Hans` (Task 2).
3. **A label keyed to a language the region does not declare** (`"sw"` on Europe) must be refused with 422, not
   stored (Task 3).
4. **`resource-tuple-sweep` running between the deploy and the tuple migration** must not purge grants on
   `wider_region:roman/Europe` (Task 6).
5. **Two clients with different `Accept-Language`** must get different ETags, and the response must carry
   `Vary: Accept-Language`. Otherwise a shared cache serves one language to everyone (Task 7).

---

### Task 1: `WiderRegionId` and the `WiderRegionId` schema definition

**Files:**

- Create: `src/Models/RegionalData/WiderRegionId.php`
- Create: `phpunit_tests/Models/RegionalData/WiderRegionIdTest.php`
- Modify: `jsondata/schemas/CommonDef.json` (definitions `WiderRegionName` ~line 2000)

**Interfaces:**

- Produces:
  - `WiderRegionId::PATTERN`, `WiderRegionId::LEGACY_PATTERN`
  - `WiderRegionId::isValid(string): bool`, `WiderRegionId::isLegacy(string): bool`
  - `WiderRegionId::normalize(string): ?array{0: string, 1: bool}`: `[id, wasLegacy]`, or null when the input is
    neither shape
  - `WiderRegionId::humanize(string): string`
  - Schema: `CommonDef.json#/definitions/WiderRegionId`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Models\RegionalData;

use LiturgicalCalendar\Api\Models\RegionalData\WiderRegionId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(WiderRegionId::class)]
final class WiderRegionIdTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function validIds(): array
    {
        return ['one word' => ['europe'], 'several words' => ['german-language-area'], 'two letters' => ['ab']];
    }

    #[DataProvider('validIds')]
    public function testValidIds(string $id): void
    {
        self::assertTrue(WiderRegionId::isValid($id));
        self::assertSame([$id, false], WiderRegionId::normalize($id));
    }

    /** @return array<string, array{string}> */
    public static function invalidInputs(): array
    {
        return [
            'empty'             => [''],
            'space'             => ['middle east'],
            'leading hyphen'    => ['-europe'],
            'double hyphen'     => ['middle--east'],
            'trailing hyphen'   => ['europe-'],
            'digit'             => ['region1'],
            'underscore'        => ['middle_east'],
            'mixed case kebab'  => ['Middle-East'],
            'legacy with space' => ['Europe '],
            'diacritic'         => ['européen'],
        ];
    }

    #[DataProvider('invalidInputs')]
    public function testInvalidInputsAreNeitherShape(string $input): void
    {
        self::assertFalse(WiderRegionId::isValid($input));
        self::assertNull(WiderRegionId::normalize($input));
    }

    /** @return array<string, array{string, string}> */
    public static function legacyNames(): array
    {
        return [
            'one word'      => ['Europe', 'europe'],
            'two words'     => ['Middle East', 'middle-east'],
            'camel in word' => ['EastIndies', 'eastindies'],
        ];
    }

    #[DataProvider('legacyNames')]
    public function testLegacyNamesMapToIds(string $legacy, string $id): void
    {
        self::assertTrue(WiderRegionId::isLegacy($legacy));
        self::assertFalse(WiderRegionId::isValid($legacy));
        self::assertSame([$id, true], WiderRegionId::normalize($legacy));
    }

    public function testHumanize(): void
    {
        self::assertSame('German Language Area', WiderRegionId::humanize('german-language-area'));
        self::assertSame('Europe', WiderRegionId::humanize('europe'));
    }
}
```

- [ ] **Step 2: Run it and verify it fails**

Run: `vendor/bin/phpunit phpunit_tests/Models/RegionalData/WiderRegionIdTest.php`
Expected: FAIL with `Class "LiturgicalCalendar\Api\Models\RegionalData\WiderRegionId" not found`.

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Models\RegionalData;

/**
 * A wider region's identifier (#1018): lowercase words joined by single hyphens (`europe`, `german-language-area`).
 *
 * It is the region's key everywhere: its folder and file names, `/data/widerregion/{id}`, a nation's `wider_regions`,
 * and OpenFGA object ids. It is URL-safe and OpenFGA-safe by construction, and it never changes once the region exists;
 * what users see is the region's label (WiderRegionLabels). The JSON schemas mirror PATTERN as `WiderRegionId`.
 *
 * Until #1018, the identifier was a capitalised display name (`Europe`, `Middle East`, LEGACY_PATTERN). Such a name
 * is still accepted on input for a transition period and mapped to its id by normalize(): lowercased, spaces to
 * hyphens. The mapping is deterministic, so no table of old names is needed.
 */
final class WiderRegionId
{
    public const PATTERN = '/^[a-z]+(-[a-z]+)*$/';

    public const LEGACY_PATTERN = '/^[A-Z][A-Za-z]*( [A-Z][A-Za-z]*)*$/';

    public static function isValid(string $id): bool
    {
        return 1 === preg_match(self::PATTERN, $id);
    }

    public static function isLegacy(string $name): bool
    {
        return 1 === preg_match(self::LEGACY_PATTERN, $name);
    }

    /**
     * The id an input denotes, and whether it arrived as a legacy name. Null when it is neither shape.
     *
     * @return array{0: string, 1: bool}|null
     */
    public static function normalize(string $input): ?array
    {
        if (self::isValid($input)) {
            return [$input, false];
        }
        if (self::isLegacy($input)) {
            return [strtolower(str_replace(' ', '-', $input)), true];
        }

        return null;
    }

    /** Words for an id with no usable label: `german-language-area` → `German Language Area`. */
    public static function humanize(string $id): string
    {
        return ucwords(str_replace('-', ' ', $id));
    }
}
```

- [ ] **Step 4: Run it and verify it passes**

Run: `vendor/bin/phpunit phpunit_tests/Models/RegionalData/WiderRegionIdTest.php`
Expected: PASS (all data sets).

- [ ] **Step 5: Add the schema definition.** In `jsondata/schemas/CommonDef.json`, add `WiderRegionId` next to
  `WiderRegionName`, and turn `WiderRegionName` into a deprecated alias. The Frontend still `$ref`s it until its
  follow-up PR. Edit with a small PHP script that goes through `JsonFormatter` so the file stays canonical:

```bash
php -r '
require "vendor/autoload.php";
$p = "jsondata/schemas/CommonDef.json";
$s = json_decode(file_get_contents($p));
$s->definitions->WiderRegionId = (object) [
    "type"        => "string",
    "pattern"     => "^[a-z]+(-[a-z]+)*$",
    "description" => "A wider region identifier: lowercase words joined by single hyphens, e.g. `europe`, `german-language-area`. Permanent once the region exists; what users see is the region label. Whether the region exists is checked at runtime against the wider region files.",
];
$s->definitions->WiderRegionName = (object) [
    "\$ref"       => "#/definitions/WiderRegionId",
    "deprecated"  => true,
    "description" => "Deprecated alias of `WiderRegionId` (#1018). Kept so external references keep resolving.",
];
file_put_contents($p, \LiturgicalCalendar\Api\JsonFormatter::encode($s) . PHP_EOL);
'
```

- [ ] **Step 6: Verify the schema and encoding**

Run: `composer lint:jsondata && git diff --stat jsondata/schemas/CommonDef.json`
Expected: lint passes, and the diff touches only the two definitions.

- [ ] **Step 7: Commit**

```bash
git add src/Models/RegionalData/WiderRegionId.php phpunit_tests/Models/RegionalData/WiderRegionIdTest.php jsondata/schemas/CommonDef.json
git commit -m "feat(wider-regions): WiderRegionId shape rule and legacy-name mapping (#1018)"
```

---

### Task 2: `WiderRegionLabels`

**Files:**

- Create: `src/Models/RegionalData/WiderRegionLabels.php`
- Create: `phpunit_tests/Models/RegionalData/WiderRegionLabelsTest.php`

**Interfaces:**

- Consumes: `WiderRegionId::humanize(string): string` (Task 1).
- Produces:
  - `WiderRegionLabels::KEY_PATTERN`
  - `WiderRegionLabels::allowedKeys(array $locales): list<string>`
  - `WiderRegionLabels::validate(mixed $labels, array $locales): array<string,string>`, which throws `\ValueError`
  - `WiderRegionLabels::resolve(array $labels, ?string $locale, string $id): string`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Models\RegionalData;

use LiturgicalCalendar\Api\Models\RegionalData\WiderRegionLabels;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(WiderRegionLabels::class)]
final class WiderRegionLabelsTest extends TestCase
{
    public function testAllowedKeysAreEnglishPlusEachDeclaredLanguageAndScript(): void
    {
        self::assertSame(
            ['en', 'it', 'de', 'zh', 'zh_Hans'],
            WiderRegionLabels::allowedKeys(['it_IT', 'it_CH', 'de_AT', 'zh_Hans_CN'])
        );
    }

    public function testValidateAcceptsObjectsAndArraysAndTrims(): void
    {
        $locales = ['it_IT', 'de_DE'];
        self::assertSame(
            ['en' => 'Europe', 'it' => 'Europa'],
            WiderRegionLabels::validate((object) ['en' => 'Europe', 'it' => ' Europa '], $locales)
        );
        self::assertSame(['de' => 'Europa'], WiderRegionLabels::validate(['de' => 'Europa'], $locales));
        self::assertSame([], WiderRegionLabels::validate(null, $locales));
    }

    /** @return array<string, array{mixed}> */
    public static function badLabels(): array
    {
        return [
            'not a map'                 => ['Europe'],
            'undeclared language'       => [['sw' => 'Ulaya']],
            'full locale as key'        => [['it_IT' => 'Europa']],
            'empty value'               => [['it' => '  ']],
            'non-string value'          => [['it' => 42]],
            'list instead of map'       => [['Europa']],
        ];
    }

    #[DataProvider('badLabels')]
    public function testValidateRefuses(mixed $labels): void
    {
        $this->expectException(\ValueError::class);
        WiderRegionLabels::validate($labels, ['it_IT', 'de_DE']);
    }

    /** @return array<string, array{?string, string}> */
    public static function resolutions(): array
    {
        return [
            'exact language'                 => ['it_IT', 'Europa (it)'],
            'lowercase negotiated tag'       => ['it_it', 'Europa (it)'],
            'script beats bare language'     => ['zh_hans_cn', '欧洲'],
            'other script falls to english'  => ['zh_Hant_TW', 'Europe'],
            'undeclared falls to english'    => ['sw_KE', 'Europe'],
            'no locale falls to english'     => [null, 'Europe'],
        ];
    }

    #[DataProvider('resolutions')]
    public function testResolve(?string $locale, string $expected): void
    {
        $labels = ['en' => 'Europe', 'it' => 'Europa (it)', 'zh_Hans' => '欧洲'];
        self::assertSame($expected, WiderRegionLabels::resolve($labels, $locale, 'europe'));
    }

    public function testResolveWithoutEnglishHumanizesTheId(): void
    {
        self::assertSame('German Language Area', WiderRegionLabels::resolve(['de' => 'Deutsches Sprachgebiet'], 'fr_FR', 'german-language-area'));
        self::assertSame('German Language Area', WiderRegionLabels::resolve([], 'de_DE', 'german-language-area'));
    }
}
```

- [ ] **Step 2: Run it and verify it fails**

Run: `vendor/bin/phpunit phpunit_tests/Models/RegionalData/WiderRegionLabelsTest.php`
Expected: FAIL, class not found.

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Models\RegionalData;

/**
 * A wider region's display labels (#1018): `metadata.labels` in its source file, a map from a label key to text.
 *
 * A key is a bare language (`it`) or a language plus script (`zh_Hans`), not a full locale: `de_AT`, `de_DE` and
 * `de_CH` would only repeat one label. Allowed keys are `en` plus those derivable from the region's own declared
 * locales, so a region is only ever labelled in its member countries' languages (and English). Labels are edited
 * through the region's PUT/PATCH like the rest of its metadata; they are not in the gettext catalogue.
 */
final class WiderRegionLabels
{
    public const KEY_PATTERN = '/^[a-z]{2,3}(_[A-Z][a-z]{3})?$/';

    /**
     * @param list<string> $locales The region's declared locales.
     * @return list<string> `en` first, then each key in order of first appearance.
     */
    public static function allowedKeys(array $locales): array
    {
        $keys = ['en'];
        foreach ($locales as $locale) {
            [$language, $script] = self::languageAndScript($locale);
            $keys[] = $language;
            if ($script !== '') {
                $keys[] = "{$language}_{$script}";
            }
        }

        return array_values(array_unique(array_filter($keys, static fn (string $k): bool => $k !== '')));
    }

    /**
     * @param list<string> $locales
     * @return array<string, string> Trimmed labels; empty when `$labels` is null.
     * @throws \ValueError When `$labels` is not a map of allowed keys to non-empty strings.
     */
    public static function validate(mixed $labels, array $locales): array
    {
        if ($labels === null) {
            return [];
        }
        if ($labels instanceof \stdClass) {
            $labels = get_object_vars($labels);
        }
        if (!is_array($labels) || ( $labels !== [] && array_is_list($labels) )) {
            throw new \ValueError('`metadata.labels` must be an object mapping a language (e.g. `it`, `zh_Hans`) to a label');
        }

        $allowed = self::allowedKeys($locales);
        $out     = [];
        foreach ($labels as $key => $label) {
            $key = (string) $key;
            if (1 !== preg_match(self::KEY_PATTERN, $key) || !in_array($key, $allowed, true)) {
                throw new \ValueError(sprintf(
                    '`metadata.labels` key `%s` is not allowed: use `en` or a language of the region\'s locales (%s)',
                    $key,
                    implode(', ', $allowed)
                ));
            }
            if (!is_string($label) || trim($label) === '') {
                throw new \ValueError("`metadata.labels.{$key}` must be a non-empty string");
            }
            $out[$key] = trim($label);
        }

        return $out;
    }

    /**
     * The label for `$locale`: its language plus script, then its language, then English, then words from the id.
     *
     * `$locale` may be the lowercase, underscored form Negotiator::pickLanguage() returns (`zh_hans_cn`).
     *
     * @param array<string, string> $labels
     */
    public static function resolve(array $labels, ?string $locale, string $id): string
    {
        $candidates = [];
        if ($locale !== null && $locale !== '') {
            [$language, $script] = self::languageAndScript($locale);
            if ($script !== '') {
                $candidates[] = "{$language}_{$script}";
            }
            $candidates[] = $language;
        }
        $candidates[] = 'en';

        foreach ($candidates as $key) {
            if (isset($labels[$key]) && $labels[$key] !== '') {
                return $labels[$key];
            }
        }

        return WiderRegionId::humanize($id);
    }

    /** @return array{0: string, 1: string} Lowercase language and title-case script ('' when absent). */
    private static function languageAndScript(string $locale): array
    {
        $language = strtolower((string) \Locale::getPrimaryLanguage($locale));
        $script   = (string) \Locale::getScript($locale);

        return [$language, $script === '' ? '' : ucfirst(strtolower($script))];
    }
}
```

- [ ] **Step 4: Run it and verify it passes**

Run: `vendor/bin/phpunit phpunit_tests/Models/RegionalData/WiderRegionLabelsTest.php`
Expected: PASS. If `lowercase negotiated tag` or `script beats bare language` fails, print
`var_dump(\Locale::getPrimaryLanguage('zh_hans_cn'), \Locale::getScript('zh_hans_cn'));`. If ICU does not parse
the lowercase script, fall back to splitting on `_` / `-`: segment 1 is the language; segment 2 is a script when it
is 4 letters.

- [ ] **Step 5: Commit**

```bash
git add src/Models/RegionalData/WiderRegionLabels.php phpunit_tests/Models/RegionalData/WiderRegionLabelsTest.php
git commit -m "feat(wider-regions): WiderRegionLabels allowed keys, validation and resolution (#1018)"
```

---

### Task 3: `WiderRegionMetadata` carries labels and a normalized id

**Files:**

- Modify: `src/Models/RegionalData/WiderRegionData/WiderRegionMetadata.php` (whole class)
- Modify: `src/Models/RegionalData/WiderRegionData/WiderRegionData.php:17-27, 144` (phpstan shapes: add
  `labels?: array<string,string>|\stdClass`)
- Modify: `jsondata/schemas/WiderRegionCalendar.json` (`definitions.CalendarMetadata`, ~line 540)
- Create: `phpunit_tests/Models/RegionalData/WiderRegionMetadataTest.php`

**Interfaces:**

- Consumes: `WiderRegionId::normalize()`, `WiderRegionLabels::validate()`.
- Produces:
  - `WiderRegionMetadata::$wider_region`: always an id.
  - `WiderRegionMetadata::$labels`: `array<string,string>`.
  - `WiderRegionMetadata::$usedLegacyId`: `bool`, excluded from JSON.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Models\RegionalData;

use LiturgicalCalendar\Api\Models\RegionalData\WiderRegionData\WiderRegionMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WiderRegionMetadata::class)]
final class WiderRegionMetadataTest extends TestCase
{
    public function testIdAndLabelsAreRead(): void
    {
        $m = WiderRegionMetadata::fromObject((object) [
            'locales'      => ['it_IT', 'de_DE'],
            'wider_region' => 'europe',
            'labels'       => (object) ['en' => 'Europe', 'it' => 'Europa'],
        ]);

        self::assertSame('europe', $m->wider_region);
        self::assertSame(['en' => 'Europe', 'it' => 'Europa'], $m->labels);
        self::assertFalse($m->usedLegacyId);
        self::assertSame(
            ['locales' => ['it_IT', 'de_DE'], 'wider_region' => 'europe', 'labels' => ['en' => 'Europe', 'it' => 'Europa']],
            $m->jsonSerialize()
        );
    }

    public function testALegacyNameIsMappedAndFlagged(): void
    {
        $m = WiderRegionMetadata::fromArray(['locales' => ['it_IT'], 'wider_region' => 'Middle East']);

        self::assertSame('middle-east', $m->wider_region);
        self::assertTrue($m->usedLegacyId);
        self::assertSame([], $m->labels);
        self::assertArrayNotHasKey('usedLegacyId', $m->jsonSerialize());
    }

    public function testAnInvalidIdIsRefused(): void
    {
        $this->expectException(\ValueError::class);
        WiderRegionMetadata::fromArray(['locales' => ['it_IT'], 'wider_region' => 'middle east']);
    }

    public function testALabelInAnUndeclaredLanguageIsRefused(): void
    {
        $this->expectException(\ValueError::class);
        WiderRegionMetadata::fromObject((object) [
            'locales'      => ['it_IT'],
            'wider_region' => 'europe',
            'labels'       => (object) ['sw' => 'Ulaya'],
        ]);
    }
}
```

- [ ] **Step 2: Run it and verify it fails**

Run: `vendor/bin/phpunit phpunit_tests/Models/RegionalData/WiderRegionMetadataTest.php`
Expected: FAIL (undefined property `labels` / `usedLegacyId`).

- [ ] **Step 3: Implement.** Replace the body of `WiderRegionMetadata` with:

```php
final class WiderRegionMetadata extends AbstractJsonRepresentation
{
    /** @var string[] */
    public readonly array $locales;

    /** The region's id (#1018), already mapped from a legacy name when one was sent. */
    public string $wider_region;

    /** @var array<string, string> Display labels by language (WiderRegionLabels). */
    public readonly array $labels;

    /** Whether `wider_region` arrived as a legacy capitalised name. Not serialized. */
    public readonly bool $usedLegacyId;

    /**
     * @param string[]              $locales
     * @param array<string, string> $labels
     */
    private function __construct(array $locales, string $wider_region, array $labels, bool $usedLegacyId)
    {
        $this->locales      = $locales;
        $this->wider_region = $wider_region;
        $this->labels       = $labels;
        $this->usedLegacyId = $usedLegacyId;
    }

    /** @return array{locales: string[], wider_region: string, labels?: array<string, string>} */
    public function jsonSerialize(): array
    {
        $out = ['locales' => $this->locales, 'wider_region' => $this->wider_region];
        if ($this->labels !== []) {
            $out['labels'] = $this->labels;
        }

        return $out;
    }

    /**
     * @param array{locales:string[],wider_region:string,labels?:array<string,string>} $data
     * @throws \ValueError When a key is missing, the id is neither an id nor a legacy name, or a label is not allowed.
     * @throws \TypeError When `locales` is not a non-empty array or `wider_region` is not a string.
     */
    protected static function fromArrayInternal(array $data): static
    {
        return self::build($data['locales'] ?? null, $data['wider_region'] ?? null, $data['labels'] ?? null);
    }

    /**
     * @param \stdClass&object{locales:string[],wider_region:string,labels?:\stdClass} $data
     * @throws \ValueError|\TypeError As fromArrayInternal().
     */
    protected static function fromObjectInternal(\stdClass $data): static
    {
        return self::build($data->locales ?? null, $data->wider_region ?? null, $data->labels ?? null);
    }

    private static function build(mixed $locales, mixed $widerRegion, mixed $labels): static
    {
        if ($locales === null || $widerRegion === null) {
            throw new \ValueError('locales and wider_region parameters are required');
        }
        if (!is_array($locales) || count($locales) === 0) {
            throw new \TypeError('locales parameter must be an array and must not be empty');
        }
        if (!is_string($widerRegion)) {
            throw new \TypeError('wider_region parameter must be a string');
        }
        $normalized = WiderRegionId::normalize($widerRegion);
        if ($normalized === null) {
            throw new \ValueError('`metadata.wider_region` must be a wider region id such as `europe` or `german-language-area`');
        }
        /** @var list<string> $localeList */
        $localeList = array_values(array_filter($locales, 'is_string'));

        return new static($locales, $normalized[0], WiderRegionLabels::validate($labels, $localeList), $normalized[1]);
    }
}
```

Add `use LiturgicalCalendar\Api\Models\RegionalData\WiderRegionId;` and
`use LiturgicalCalendar\Api\Models\RegionalData\WiderRegionLabels;`. Update the two phpstan array/object shapes in
`WiderRegionData.php` to add `labels?:array<string,string>` and `labels?:\stdClass`.

- [ ] **Step 4: Update the source schema.** In `WiderRegionCalendar.json` `definitions.CalendarMetadata.properties`,
  change `wider_region.$ref` to `./CommonDef.json#/definitions/WiderRegionId`, and add:

```json
"labels": {
    "type": "object",
    "description": "Display labels for the region, keyed by language (`it`) or language and script (`zh_Hans`). Allowed keys are `en` and the languages of `locales`; that constraint is enforced by the API. Missing languages fall back to `en`, then to words derived from `wider_region`.",
    "propertyNames": { "pattern": "^[a-z]{2,3}(_[A-Z][a-z]{3})?$" },
    "additionalProperties": { "type": "string", "minLength": 1 }
}
```

Make the edit through `JsonFormatter`, as in Task 1 Step 5.

- [ ] **Step 5: Run the tests and verify they pass**

Run: `vendor/bin/phpunit phpunit_tests/Models/RegionalData/ && composer lint:jsondata`
Expected: the new tests PASS. Existing tests that load the current capitalised region files (`Europe.json`) still
pass, because the legacy name is mapped.

- [ ] **Step 6: Commit**

```bash
git add -A src/Models/RegionalData jsondata/schemas/WiderRegionCalendar.json phpunit_tests/Models/RegionalData/WiderRegionMetadataTest.php
git commit -m "feat(wider-regions): region metadata carries labels and a normalized id (#1018)"
```

---

### Task 4: The switch — nations take ids, and the source data is renamed

This is the one commit that flips the tree. After it, ids are mandatory in stored data, every stored region is
renamed, and legacy names survive only as input.

**Files:**

- Modify: `src/Models/RegionalData/NationalData/NationalMetadata.php:36-125`
- Delete: `src/Models/RegionalData/WiderRegionName.php`. First replace every use: `grep -rn WiderRegionName src phpunit_tests`.
- Modify: `src/Handlers/RegionalDataHandler.php`:
  - payload parsing, ~2140-2170: write the ids back into the raw payload;
  - the two national `$warnings` blocks, ~495 and ~771.
- Modify: `src/Health.php:1753, 1807, 5028, 5113` (slug and path regexes)
- Modify: `jsondata/schemas/NationalCalendar.json:440,445`, `jsondata/schemas/LitCalMetadata.json:43,48,153`
  (`$ref` → `WiderRegionId`)
- Move: `jsondata/sourcedata/rite/roman/calendars/wider_regions/{Americas,Asia,Europe}` →
  `{americas,asia,europe}`, including the inner `{Name}.json` → `{id}.json`
- Modify: `nations/{CA,US,HR,IT,NL}/{X}.json` `metadata.wider_regions`
- Modify: every test and fixture that hard-codes a region name (list in Step 7)
- Test: `phpunit_tests/Models/RegionalData/NationalMetadataTest.php`

**Interfaces:**

- Consumes: `WiderRegionId::normalize()` (Task 1), `WiderRegionMetadata` (Task 3).
- Produces:
  - `NationalMetadata::$wider_regions`: always ids.
  - `NationalMetadata::$usedLegacyWiderRegionName`: `bool`.
  - Region folders `americas/`, `asia/`, `europe/`, whose files carry `metadata.labels`.

- [ ] **Step 1: Write the failing tests.** In `NationalMetadataTest.php`:
  - Replace `badRegionProvider()` and the multi-word test (lines ~80-100) with the block below.
  - Replace every other `'Europe'` / `'Americas'` in that file with `'europe'` / `'americas'`.

```php
    /** @return array<string, array{mixed}> */
    public static function badRegionProvider(): array
    {
        return [
            'space in id'    => ['middle east'],
            'trailing space' => ['Europe '],
            'digit'          => ['region1'],
            'not a string'   => [42],
        ];
    }

    #[DataProvider('badRegionProvider')]
    public function testAnItemFailingTheIdShapeIsRefused(mixed $region): void
    {
        $this->expectException(\ValueError::class);

        self::metadata(['wider_regions' => [$region]]);
    }

    public function testALegacyNameIsMappedToItsIdAndFlagged(): void
    {
        $m = self::metadata(['wider_regions' => ['Europe', 'Middle East']]);

        self::assertSame(['europe', 'middle-east'], $m->wider_regions);
        self::assertTrue($m->usedLegacyWiderRegionName);
    }

    public function testALegacyNameAndItsIdAreTheSameRegion(): void
    {
        $this->expectException(\ValueError::class);

        self::metadata(['wider_regions' => ['Europe', 'europe']]);
    }

    public function testIdsAreNotFlaggedAsLegacy(): void
    {
        self::assertFalse(self::metadata(['wider_regions' => ['europe']])->usedLegacyWiderRegionName);
    }
```

- [ ] **Step 2: Run them and verify they fail**

Run: `vendor/bin/phpunit phpunit_tests/Models/RegionalData/NationalMetadataTest.php`
Expected: FAIL. `europe` is rejected by the old shape, and `usedLegacyWiderRegionName` is undefined.

- [ ] **Step 3: Implement in `NationalMetadata`.**
  - Add `public readonly bool $usedLegacyWiderRegionName;` and set it in the constructor.
  - Replace the `foreach ($wider_regions …)` validation block, and the duplicate check after it, with:

```php
        $ids       = [];
        $anyLegacy = false;
        foreach ($wider_regions as $region) {
            $normalized = is_string($region) ? WiderRegionId::normalize($region) : null;
            if (null === $normalized) {
                throw new \ValueError('`metadata.wider_regions` must be a list of wider region ids such as `europe` or `german-language-area`');
            }
            $ids[]      = $normalized[0];
            $anyLegacy  = $anyLegacy || $normalized[1];
        }
        if (count(array_unique($ids)) !== count($ids)) {
            throw new \ValueError('`metadata.wider_regions` must not name a wider region more than once');
        }
```

  Then assign `$this->wider_regions = $ids;` and `$this->usedLegacyWiderRegionName = $anyLegacy;`. Swap the
  `use … WiderRegionName` import for `WiderRegionId`. If `jsonSerialize()` uses `get_object_vars`, exclude
  `usedLegacyWiderRegionName` the same way `usedLegacyWiderRegion` is excluded.

- [ ] **Step 4: Write the ids back into the stored payload, and warn.** In `RegionalDataHandler`, right after
  `$params['payload'] = NationalData::fromObject($payload);` in the PUT/PATCH parsing, add:

```php
                        // #1018: store ids, never a legacy name the model accepted and mapped.
                        if ($params['payload']->metadata->usedLegacyWiderRegionName) {
                            $payload->metadata->wider_regions = $params['payload']->metadata->wider_regions;
                            if (isset($payload->metadata->wider_region) && is_string($payload->metadata->wider_region) && $payload->metadata->wider_region !== '') {
                                $payload->metadata->wider_region = $params['payload']->metadata->wider_regions[0];
                            }
                        }
```

  Right after `$params['payload'] = WiderRegionData::fromObject($payload);` add:

```php
                        if ($params['payload']->metadata->usedLegacyId) {
                            $payload->metadata->wider_region = $params['payload']->metadata->wider_region;
                        }
```

  In both national `$warnings` blocks (~495, ~771) add:

```php
        if ($payload->metadata->usedLegacyWiderRegionName) {
            $warnings[] = 'Wider region names are deprecated: `metadata.wider_regions` now takes ids such as `europe`; the names sent were stored as ids.';
        }
```

- [ ] **Step 5: Rename the data and seed the labels.** Run from the worktree root:

```bash
W=jsondata/sourcedata/rite/roman/calendars/wider_regions
for pair in Americas:americas Asia:asia Europe:europe; do
  old=${pair%%:*}; new=${pair##*:}
  git mv "$W/$old" "$W/$new.tmp" && git mv "$W/$new.tmp" "$W/$new"   # two steps: case-only renames on case-insensitive FS
  git mv "$W/$new/$old.json" "$W/$new/$new.json"
done
php -r '
require "vendor/autoload.php";
use LiturgicalCalendar\Api\Models\RegionalData\WiderRegionLabels;
$W = "jsondata/sourcedata/rite/roman/calendars/wider_regions";
foreach (["americas" => "019", "asia" => "142", "europe" => "150"] as $id => $m49) {
    $p = "$W/$id/$id.json";
    $d = json_decode(file_get_contents($p));
    $d->metadata->wider_region = $id;
    $labels = [];
    foreach (WiderRegionLabels::allowedKeys($d->metadata->locales) as $key) {
        $name = \Locale::getDisplayRegion("und_{$m49}", $key);
        if ($name === "" || $name === $m49) { continue; }
        $labels[$key] = mb_strtoupper(mb_substr($name, 0, 1)) . mb_substr($name, 1);
    }
    $d->metadata->labels = (object) $labels;
    file_put_contents($p, \LiturgicalCalendar\Api\JsonFormatter::encode($d) . PHP_EOL);
}
foreach (["CA", "US", "HR", "IT", "NL"] as $n) {
    $p = "jsondata/sourcedata/rite/roman/calendars/nations/$n/$n.json";
    $d = json_decode(file_get_contents($p));
    $d->metadata->wider_regions = array_map(static fn ($r) => strtolower(str_replace(" ", "-", $r)), $d->metadata->wider_regions);
    file_put_contents($p, \LiturgicalCalendar\Api\JsonFormatter::encode($d) . PHP_EOL);
}
'
git diff --stat -- jsondata/sourcedata | tail -3
jq -c .metadata.labels $W/*/*.json
```

  Expected: each region has labels. For Europe, it's `en` plus about 25 languages ("Europa", "Ευρώπη", "Eurooppa", …);
  Asia has `en`, `zh`, `zh_Hans` and `ja`. The nation diffs change only `wider_regions`. If `git diff` shows any
  other churn (key order, escaping), the formatter is wrong: revert and use the formatter the source-data write path
  uses (`grep -rn "JsonFormatter::encode" src/Handlers/RegionalDataHandler.php`).

- [ ] **Step 6: Switch the schemas and Health.**
  - `$ref` `WiderRegionName` → `WiderRegionId` in `NationalCalendar.json` (2) and `LitCalMetadata.json` (3), through
    the formatter.
  - In `src/Health.php`:

| Line  | Old fragment                 | New fragment                        |
|-------|------------------------------|-------------------------------------|
| ~1753 | `\-([A-Za-z_]+)\-i18n`       | `\-([A-Za-z_-]+?)\-i18n`            |
| ~1807 | `\-([A-Za-z_]+)$`            | `\-([A-Za-z_-]+)$`                  |
| ~5028 | `widerregion)\/[A-Z][a-z]+`  | `widerregion)\/[a-z]+(?:-[a-z]+)*`  |
| ~5113 | `^wider-region-[A-Z][a-z]+$` | `^wider-region-[a-z]+(?:-[a-z]+)*$` |

  Read each regex in context before editing. If a slug alternation for nations or dioceses shares the group, keep the
  old character class for them and add the hyphen only on the wider-region arm.

- [ ] **Step 7: Sweep tests and fixtures.** List the hits:

```bash
grep -rlnE "wider_?regions?[^\n]*\b(Americas|Asia|Europe|Africa|Oceania|Middle East)\b|widerregion/(Americas|Asia|Europe|Africa)|wider_region:(roman/)?(Americas|Asia|Europe)|wider-region-(Americas|Asia|Europe)|WiderRegionName" phpunit_tests
```

  For each file, change region **identifiers** to lowercase: `/widerregion/Europe` → `/widerregion/europe`,
  `roman/Europe` → `roman/europe`, `['Europe']` in `wider_regions` → `['europe']`, `wider-region-Europe` →
  `wider-region-europe`, `WiderRegionName` → `WiderRegionId`. Do **not** change:

- timezone strings (`Europe/Rome`, `Europe/Vatican`);
- the country-name enum in `WiderRegionCalendar.json`;
- prose.

  Where a test asserts the old shape rule (`CalendarsTest.php:148, 245, 383`), assert `WiderRegionId::PATTERN`, and at
  :383 the regex `(europe|africa|asia|oceania|americas)`. The known files are in the Task 4 inventory:
  `RegionalDataHandlerTest`, `WiderRegionMembership*Test`, `OpenFgaAuthorizationMiddlewareTest`,
  `WiderRegionLayer*Test`, `RegionalDataWriteResponseSchemaTest`, `NotificationsHandlerTest`, `MergePollRunnerTest`,
  `RegionalDataQueueModeTest`, `CalendarMetadataProviderTest`, `SourceDataChangeRequestRepositoryTest`,
  `HealthSchemaCategoryTest`, `MetadataCalendarsTest`, `ResourceExistenceCheckerTest`, `HealthTypedCalendarTest`,
  `CatalogJobsTest`, `ChangeResourceTest`, `SourceDataSchemaResolverTest`, `AccessRequestRepositoryTest`, and the
  `fixtures/payloads/*` and `fixtures/api/calendars` files. Keep one deliberate legacy case: in
  `RegionalDataHandlerTest`, a national PUT with `wider_regions: ["Europe"]` must store `["europe"]` and return the
  deprecation warning. Add it as a new test next to the existing legacy `wider_region` test.

- [ ] **Step 8: Run the suite**

Run: `composer test:quick 2>&1 | tail -15`
Expected: no failures or errors. A leftover capitalised id usually shows up as a 404 or a missing-file error; grep
the failure's file for the old name.

- [ ] **Step 9: Static checks**

Run: `composer analyse && composer lint && composer lint:jsondata && composer lint:locales`
Expected: all pass.

- [ ] **Step 10: Commit**

```bash
git add -A
git commit -m "feat(wider-regions)!: ids are lowercase kebab-case; rename americas, asia, europe (#1018)

Stored data now uses ids everywhere. Legacy capitalised names are accepted on
input, mapped to their id, stored as the id, and answered with a warning on
national writes. The three existing regions gain labels seeded from CLDR."
```

---

### Task 5: Legacy path keys map to ids in the Router

**Files:**

- Modify: `src/Router.php`: new `public static function canonicaliseWiderRegionKey(array &$requestPathParts): bool`,
  called for route `data` right after `$requestPathParts` is final (after `extractRiteSegment`) and before
  `new RegionalDataHandler($requestPathParts, $rite)` (~line 672) and the `calendar_id` attribute (~line 983).
- Test: `phpunit_tests/RouterWiderRegionKeyTest.php`

**Interfaces:**

- Consumes: `WiderRegionId::normalize()`.
- Produces: `Router::canonicaliseWiderRegionKey(array &$parts): bool`. It rewrites `$parts[1]` in place when
  `$parts[0] === 'widerregion'`, and returns whether the key was legacy.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests;

use LiturgicalCalendar\Api\Router;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

#[CoversMethod(Router::class, 'canonicaliseWiderRegionKey')]
final class RouterWiderRegionKeyTest extends TestCase
{
    public function testALegacyRegionKeyIsRewrittenToItsId(): void
    {
        $parts = ['widerregion', 'Europe', 'it_IT'];
        self::assertTrue(Router::canonicaliseWiderRegionKey($parts));
        self::assertSame(['widerregion', 'europe', 'it_IT'], $parts);
    }

    public function testAnEncodedMultiWordLegacyKeyIsRewritten(): void
    {
        $parts = ['widerregion', 'Middle%20East'];
        self::assertTrue(Router::canonicaliseWiderRegionKey($parts));
        self::assertSame(['widerregion', 'middle-east'], $parts);
    }

    public function testAnIdIsLeftAlone(): void
    {
        $parts = ['widerregion', 'europe'];
        self::assertFalse(Router::canonicaliseWiderRegionKey($parts));
        self::assertSame(['widerregion', 'europe'], $parts);
    }

    public function testOtherCategoriesAndGarbageAreLeftAlone(): void
    {
        $nation = ['nation', 'IT'];
        self::assertFalse(Router::canonicaliseWiderRegionKey($nation));
        self::assertSame(['nation', 'IT'], $nation);

        $garbage = ['widerregion', 'eu rope'];
        self::assertFalse(Router::canonicaliseWiderRegionKey($garbage));
        self::assertSame(['widerregion', 'eu rope'], $garbage);
    }
}
```

- [ ] **Step 2: Run it and verify it fails**

Run: `vendor/bin/phpunit phpunit_tests/RouterWiderRegionKeyTest.php`
Expected: FAIL, undefined method.

- [ ] **Step 3: Implement** in `Router`:

```php
    /**
     * Maps a legacy wider region key in a `/data/widerregion/{key}` path to its id (#1018), in place, so the handler,
     * the `calendar_id` authorization attribute and every path built from the key all see the id. Only the key segment
     * is decoded, and only for this lookup: an id never needs decoding, and a legacy multi-word name only ever arrived
     * encoded. Anything that is neither shape is left for the handler to refuse.
     *
     * @param array<int, string> $requestPathParts
     * @return bool Whether a legacy key was rewritten.
     */
    public static function canonicaliseWiderRegionKey(array &$requestPathParts): bool
    {
        if (( $requestPathParts[0] ?? null ) !== PathCategory::WIDERREGION->value || !isset($requestPathParts[1])) {
            return false;
        }
        $normalized = WiderRegionId::normalize(rawurldecode($requestPathParts[1]));
        if ($normalized === null || $normalized[1] === false) {
            return false;
        }
        $requestPathParts[1] = $normalized[0];

        return true;
    }
```

  Call it in the `data` route branch before the handler is constructed, and keep its result there:
  `$legacyWiderRegionKey = self::canonicaliseWiderRegionKey($requestPathParts);`. The existing `calendar_id` code
  then reads the rewritten part automatically. Add `use LiturgicalCalendar\Api\Models\RegionalData\WiderRegionId;`.

- [ ] **Step 4: Add a handler-level regression test** in `phpunit_tests/Handlers/RegionalDataHandlerTest.php`. Follow
  that file's existing pattern for a wider-region GET in a shadow project root:
  - `GET` with path params `['widerregion', 'Europe']`, after running `Router::canonicaliseWiderRegionKey()` on them,
    returns 200 and the body of `europe/europe.json`;
  - no `Europe/` directory exists in the shadow root afterwards.

- [ ] **Step 5: Run the tests**

Run: `vendor/bin/phpunit phpunit_tests/RouterWiderRegionKeyTest.php phpunit_tests/Handlers/RegionalDataHandlerTest.php phpunit_tests/RouterRiteSegmentTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Router.php phpunit_tests/RouterWiderRegionKeyTest.php phpunit_tests/Handlers/RegionalDataHandlerTest.php
git commit -m "feat(wider-regions): legacy region keys in /data paths map to ids (#1018)"
```

---

### Task 6: Legacy OpenFGA ids count as existing until they are migrated

**Files:**

- Modify: `src/Services/ResourceExistenceChecker.php:73-76`
- Test: `phpunit_tests/Services/ResourceExistenceCheckerTest.php`

**Interfaces:**

- Consumes: `WiderRegionId::normalize()`.

- [ ] **Step 1: Write the failing test.** Add it to `ResourceExistenceCheckerTest`:

```php
    /**
     * #1018: between the deploy and `migrate-wider-region-ids.php --apply`, grants still sit on
     * `wider_region:roman/Europe`. The sweep must see that region as existing, or it purges grants nobody copied yet.
     */
    public function testALegacyWiderRegionIdExistsWhenItsIdDoes(): void
    {
        $checker = new ResourceExistenceChecker();
        self::assertTrue($checker->exists('wider_region', 'roman/Europe'));
        self::assertTrue($checker->exists('wider_region', 'roman/europe'));
        self::assertFalse($checker->exists('wider_region', 'roman/Atlantis'));
        self::assertFalse($checker->exists('wider_region', 'roman/eu rope'));
    }
```

- [ ] **Step 2: Run it and verify it fails**

Run: `vendor/bin/phpunit --filter testALegacyWiderRegionIdExistsWhenItsIdDoes phpunit_tests/Services/ResourceExistenceCheckerTest.php`
Expected: FAIL. `roman/Europe` answers false, because the folder is now `europe/`.

- [ ] **Step 3: Implement**

```php
            case 'wider_region':
                // A legacy name (`Europe`) exists when its id does (#1018): until the tuple migration has run, grants
                // still sit on the legacy object, and reporting it missing would let the sweep purge them.
                $normalized = WiderRegionId::normalize(RiteScopedObjectId::calendarId($objectId));
                return $normalized !== null
                    && is_dir(JsonData::WIDER_REGIONS_FOLDER->path() . '/' . $normalized[0]);
```

- [ ] **Step 4: Run it and verify it passes.** Then run `phpunit_tests/Services/Outbox/` and
  `phpunit_tests/Services/ResourceTuplePurge*` to confirm nothing depends on the old answer.

- [ ] **Step 5: Commit**

```bash
git add src/Services/ResourceExistenceChecker.php phpunit_tests/Services/ResourceExistenceCheckerTest.php
git commit -m "fix(wider-regions): a legacy region id is not purged before its tuples migrate (#1018)"
```

---

### Task 7: `/calendars` serves `id` and a localized `label`

**Files:**

- Modify: `src/Models/Metadata/MetadataWiderRegionItem.php` (add `id`, `label`; `name` stays)
- Modify: `src/Services/CalendarMetadataProvider.php:62` (`create(?string $locale = null)`), and
  `buildWiderRegionData` (~250-296)
- Modify: `src/Handlers/MetadataHandler.php:~57` (negotiate the locale; `Vary: Accept-Language`)
- Modify: `jsondata/schemas/LitCalMetadata.json` `definitions.WiderRegionDef` (~220-256)
- Test: `phpunit_tests/Handlers/MetadataHandlerTest.php`, `phpunit_tests/Services/CalendarMetadataProviderTest.php`

**Interfaces:**

- Consumes: `WiderRegionLabels::resolve()`, `WiderRegionMetadata::$labels`.
- Produces:
  - `CalendarMetadataProvider::create(?string $locale = null): MetadataCalendars`. `null` resolves labels as English.
  - `MetadataWiderRegionItem::$id`, `MetadataWiderRegionItem::$label`.

- [ ] **Step 1: Write the failing tests.** In `CalendarMetadataProviderTest`:

```php
    public function testWiderRegionsCarryIdAndLabelInTheRequestedLanguage(): void
    {
        $europe = self::region(CalendarMetadataProvider::create('it_it'), 'europe');
        self::assertSame('europe', $europe->id);
        self::assertSame('europe', $europe->name, 'name is a deprecated alias of id');
        self::assertSame('Europa', $europe->label);

        self::assertSame('Europe', self::region(CalendarMetadataProvider::create(), 'europe')->label);
        self::assertSame('Europe', self::region(CalendarMetadataProvider::create('sw_ke'), 'europe')->label);
    }

    private static function region(MetadataCalendars $m, string $id): MetadataWiderRegionItem
    {
        foreach ($m->wider_regions as $r) {
            if ($r->id === $id) {
                return $r;
            }
        }
        self::fail("no wider region {$id}");
    }
```

  In `MetadataHandlerTest`, following the file's existing request-building helper:

```php
    public function testTheLabelFollowsAcceptLanguageAndTheResponseVariesOnIt(): void
    {
        $it = $this->handle($this->request('GET')->withHeader('Accept-Language', 'it-IT'));
        $en = $this->handle($this->request('GET')->withHeader('Accept-Language', 'en-US'));

        self::assertStringContainsString('Accept-Language', $it->getHeaderLine('Vary'));
        self::assertNotSame($it->getHeaderLine('ETag'), $en->getHeaderLine('ETag'));

        $labels = static fn ($r): array => array_column(json_decode((string) $r->getBody(), true)['litcal_metadata']['wider_regions'], 'label', 'id');
        self::assertSame('Europa', $labels($it)['europe']);
        self::assertSame('Europe', $labels($en)['europe']);
    }
```

  Also extend the existing JSON round-trip test in `CalendarMetadataProviderTest`: `fromObject(jsonDecode(create()))`
  must equal `create()` with `id` and `label` present.

- [ ] **Step 2: Run them and verify they fail**

Run: `vendor/bin/phpunit phpunit_tests/Services/CalendarMetadataProviderTest.php phpunit_tests/Handlers/MetadataHandlerTest.php`
Expected: FAIL. `create()` takes no locale, and `id` and `label` are undefined.

- [ ] **Step 3: Implement `MetadataWiderRegionItem`.**
  - Add `public string $id;` and `public string $label;`.
  - Change the constructor to `(string $id, string $label, array $locales, string $api_path, array $national_calendars = [], array $roster = [])`.
  - Set `$this->name = $id;` and keep `public string $name` with a `@deprecated Use $id (#1018)` docblock.
  - `jsonSerialize()` emits, in order: `id`, `label`, `name`, `locales`, `api_path`, `national_calendars`, `roster`.
  - `fromArrayInternal` / `fromObjectInternal` read `id` (falling back to `name` for old input) and `label` (falling
    back to `WiderRegionId::humanize($id)`). Update the phpstan shapes.
- [ ] **Step 4: Implement the provider.**
  - Thread `?string $locale` from `create()` into `buildWiderRegionData($metadata, $locale)`.
  - In the build, after `$regionData = Utilities::jsonFileToObject($WiderRegionFile);`:

```php
                $labels = WiderRegionLabels::validate($regionData->metadata->labels ?? null, $regionData->metadata->locales ?? []);
```

- Build the item with `'id' => $widerRegionId, 'label' => WiderRegionLabels::resolve($labels, $locale, $widerRegionId)`.
    Drop `'name'`, which the item derives.
- Every other caller of `create()` stays argument-less.
- [ ] **Step 5: Implement the handler:**

```php
        $locale            = Negotiator::pickLanguage($request, [], null);
        $metadataCalendars = CalendarMetadataProvider::create(is_string($locale) && $locale !== '' ? $locale : null);
```

  Then add `$response = $response->withHeader('Vary', 'Accept-Language');` where the ETag is set. The ETag already
  hashes the body, so it becomes per-language automatically.

- [ ] **Step 6: Update `LitCalMetadata.json` `WiderRegionDef`:**
  - add `"id": {"$ref": "./CommonDef.json#/definitions/WiderRegionId"}`;
  - add `"label": {"type": "string", "minLength": 1, "description": "The region's label in the request's Accept-Language, falling back to English, then to words derived from the id."}`;
  - change `name` to `{"$ref": "./CommonDef.json#/definitions/WiderRegionId", "deprecated": true, "description": "Deprecated alias of`id`(#1018)."}`;
  - in the `api_path` pattern, replace `[A-Z][A-Za-z]*(?: [A-Z][A-Za-z]*)*` with `[a-z]+(?:-[a-z]+)*`;
  - set `required` to `["id", "label", "name", "locales", "api_path", "national_calendars", "roster"]`.

  Make the edit through the formatter.
- [ ] **Step 7: Run the tests.** Run the two files from Step 2, plus `phpunit_tests/Routes/Readonly/CalendarsTest.php`
  (that one needs the API on :8000 serving *this* worktree, so treat a pass there as advisory) and
  `phpunit_tests/Schemas/`.
  Expected: PASS.
- [ ] **Step 8: Commit**

```bash
git add -A src/Models/Metadata src/Services/CalendarMetadataProvider.php src/Handlers/MetadataHandler.php jsondata/schemas/LitCalMetadata.json phpunit_tests
git commit -m "feat(calendars): wider regions carry id and a label in the request's language (#1018)"
```

---

### Task 8: Doctrine migration for persisted region ids

**Files:**

- Create: `src/Migrations/Version20260930120000.php`
- Create: `phpunit_tests/Repositories/WiderRegionIdMigrationTest.php`

**Interfaces:**

- Produces: a migration that rewrites legacy `wider_region` ids in `access_requests.permissions` (element-wise,
  order-preserving) and in `sourcedata_change_requests.resource_id`. It aborts while live work still names a legacy
  region.

- [ ] **Step 1: Write the failing test.** Copy the private helpers `runMigration()`, `seedChangeRequest()`,
  `seedAccessRequest()`, `fetchResource()`, and the DBAL connection setup verbatim from
  `phpunit_tests/Repositories/RiteCalendarResourceTypeMigrationTest.php`. Point `runMigration()` at
  `Version20260930120000`. Adjust `seedChangeRequest()` so it can also set `review_status` and `publication_status`.

```php
    public function testLegacyIdsAreRewrittenAndOrderIsKept(): void
    {
        $this->seedChangeRequest('wider_region', 'roman/Europe', review: 'approved', publication: 'merged');
        $this->seedChangeRequest('national_calendar', 'roman/US', review: 'approved', publication: 'merged');
        $id = $this->seedAccessRequest([
            ['object_type' => 'national_calendar', 'object_id' => 'roman/IT', 'relation' => 'editor'],
            ['object_type' => 'wider_region', 'object_id' => 'roman/Middle East', 'relation' => 'editor'],
            ['object_type' => 'wider_region', 'object_id' => 'roman/europe', 'relation' => 'viewer'],
        ]);

        $this->runMigration('up');

        self::assertSame(['wider_region', 'roman/europe'], $this->fetchResource('roman/europe'));
        self::assertSame(['national_calendar', 'roman/US'], $this->fetchResource('roman/US'));
        self::assertSame(
            [
                ['object_type' => 'national_calendar', 'object_id' => 'roman/IT', 'relation' => 'editor'],
                ['object_type' => 'wider_region', 'object_id' => 'roman/middle-east', 'relation' => 'editor'],
                ['object_type' => 'wider_region', 'object_id' => 'roman/europe', 'relation' => 'viewer'],
            ],
            $this->fetchPermissions($id)
        );
    }

    public function testItIsIdempotent(): void
    {
        $this->seedChangeRequest('wider_region', 'roman/Europe', review: 'rejected', publication: 'none');
        $this->runMigration('up');
        $this->runMigration('up');
        self::assertSame(['wider_region', 'roman/europe'], $this->fetchResource('roman/europe'));
    }

    public function testItAbortsWhileAChangeRequestOnALegacyRegionIsLive(): void
    {
        $this->seedChangeRequest('wider_region', 'roman/Europe', review: 'submitted', publication: 'none');
        $this->expectException(AbortMigration::class);
        $this->runMigration('up');
    }

    public function testItAbortsWhileAnOutboxRowNamesALegacyRegion(): void
    {
        self::$pdo?->exec("INSERT INTO openfga_outbox (operation, fga_user, fga_relation, fga_object, metadata)
                           VALUES ('write_tuple', 'user:a', 'editor', 'wider_region:roman/Europe', '{\"idempotency_key\":\"k\"}')");
        $this->expectException(AbortMigration::class);
        $this->runMigration('up');
    }
```

  `fetchPermissions(string $id): list<array<string,string>>` is a two-line helper:
  `SELECT permissions FROM access_requests WHERE id = :id`, then `json_decode(..., true)`.

- [ ] **Step 2: Run it and verify it fails**

Run: `vendor/bin/phpunit phpunit_tests/Repositories/WiderRegionIdMigrationTest.php`
Expected: FAIL, class `Version20260930120000` not found. (If it SKIPs, there is no Postgres: run
`docker compose up -d` in the main checkout, then copy `.env.local` into the worktree.)

- [ ] **Step 3: Implement.** SQL mapping for a legacy id: `lower(replace(x, ' ', '-'))`, applied only when
  `x ~ '^roman/[A-Z]'`. Ids are already lowercase, so the guard alone makes it idempotent.

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Migrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Wider region ids become lowercase kebab-case (#1018): `roman/Europe` → `roman/europe`, `roman/Middle East` →
 * `roman/middle-east`. The mapping is the one WiderRegionId::normalize() applies, lowercase plus spaces to hyphens,
 * restricted to values that start with a capital, which is what makes it idempotent.
 *
 * Rewritten: `access_requests.permissions` (element-wise, order kept, as Version20260901130000 does and for the
 * same reasons: approved rows describe live tuples and pending rows become tuples) and
 * `sourcedata_change_requests.resource_id`, so a region's history is found under its id.
 *
 * Not rewritten: `audit_log` (a record of acts under the name then in force), and change requests' `path` and
 * `branch` (the files and branches that actually existed).
 *
 * Refused: running while a change request on a legacy region is still under review or unpublished, or while the
 * outbox still has a pending or retrying row naming one. Their files and tuples would land on the legacy name
 * after cutover. Drain them first; see docs/ops/wider-region-ids-runbook.md.
 */
final class Version20260930120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rewrite persisted wider region ids to lowercase kebab-case (#1018)';
    }

    public function preUp(Schema $schema): void
    {
        $this->abortIf(!( $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform ), 'This migration targets PostgreSQL only.');

        $liveChangeRequests = (int) $this->connection->fetchOne(<<<'SQL'
            SELECT COUNT(*) FROM sourcedata_change_requests
             WHERE resource_type = 'wider_region' AND resource_id ~ '^roman/[A-Z]'
               AND (review_status = 'submitted' OR (review_status = 'approved' AND publication_status IN ('none', 'queued', 'open')))
            SQL);
        $this->abortIf($liveChangeRequests > 0, "{$liveChangeRequests} change request(s) on a legacy-named wider region are still live; settle them first (docs/ops/wider-region-ids-runbook.md).");

        $liveOutbox = (int) $this->connection->fetchOne(<<<'SQL'
            SELECT COUNT(*) FROM openfga_outbox
             WHERE status IN ('pending', 'retrying')
               AND (fga_object ~ '^wider_region:roman/[A-Z]' OR fga_user ~ '^wider_region:roman/[A-Z]')
            SQL);
        $this->abortIf($liveOutbox > 0, "{$liveOutbox} outbox row(s) naming a legacy wider region are still pending; let them drain first.");
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE sourcedata_change_requests
               SET resource_id = lower(replace(resource_id, ' ', '-'))
             WHERE resource_type = 'wider_region' AND resource_id ~ '^roman/[A-Z]'
            SQL);

        // WITH ORDINALITY + ORDER BY: `permissions` is a list and its order is part of the value. See
        // Version20260901130000 for the full reasoning; the WHERE guard also keeps a `[]` row from becoming NULL.
        $this->addSql(<<<'SQL'
            UPDATE access_requests
               SET permissions = (
                     SELECT jsonb_agg(
                         CASE
                             WHEN elem->>'object_type' = 'wider_region' AND elem->>'object_id' ~ '^roman/[A-Z]'
                                 THEN jsonb_set(elem, '{object_id}', to_jsonb(lower(replace(elem->>'object_id', ' ', '-'))))
                             ELSE elem
                         END
                         ORDER BY ord
                     )
                     FROM jsonb_array_elements(permissions) WITH ORDINALITY AS t(elem, ord)
                   )
             WHERE EXISTS (
                     SELECT 1 FROM jsonb_array_elements(permissions) AS e(elem)
                      WHERE elem->>'object_type' = 'wider_region' AND elem->>'object_id' ~ '^roman/[A-Z]'
                   )
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Irreversible by design: lowercase loses the original capitalisation (`EastIndies` vs `Eastindies`).
        $this->throwIrreversibleMigrationException('Wider region ids cannot be mapped back to their capitalised names.');
    }
}
```

  Check the real `access_requests` id column and the outbox status column names against the migrations first
  (`grep -n "CREATE TABLE access_requests" -A20 src/Migrations/*.php`), and adjust the SQL if they differ.

- [ ] **Step 4: Run it and verify it passes**

Run: `vendor/bin/phpunit phpunit_tests/Repositories/WiderRegionIdMigrationTest.php`
Expected: PASS. Then run `composer db:migrate` locally, and check that `composer db:migrations:status` shows it
applied.

- [ ] **Step 5: Commit**

```bash
git add src/Migrations/Version20260930120000.php phpunit_tests/Repositories/WiderRegionIdMigrationTest.php
git commit -m "feat(wider-regions): migrate persisted region ids to kebab-case (#1018)"
```

---

### Task 9: OpenFGA tuple migration

**Files:**

- Create: `src/Services/WiderRegionIdTupleMigration.php` (logic, PHPStan-covered)
- Create: `scripts/migrate-wider-region-ids.php` (CLI wrapper)
- Create: `phpunit_tests/Services/WiderRegionIdTupleMigrationTest.php`

**Interfaces:**

- Consumes: `OpenFgaClient::readTuples()`, `writeTuple()`, `deleteTuple()`; `WiderRegionId::normalize()`;
  `RiteScopedObjectId::parse()` / `qualify()`.
- Produces:
  - `WiderRegionIdTupleMigration::__construct(OpenFgaClient $client)`
  - `WiderRegionIdTupleMigration::plan(): list<array{from: array{user:string,relation:string,object:string}, to: array{user:string,relation:string,object:string}}>`
  - `WiderRegionIdTupleMigration::apply(bool $prune): array{copied:int, pruned:int}`
  - `WiderRegionIdTupleMigration::mapReference(string $reference): string`: public static, and pure.

- [ ] **Step 1: Write the failing test.** Use the `MockHandler`-backed `OpenFgaClient` pattern from
  `phpunit_tests/Services/OpenFgaClientTest.php`: queue one `read` page, then `write` responses, and record the
  request bodies with a Guzzle history middleware.

```php
    public function testMapReference(): void
    {
        self::assertSame('wider_region:roman/europe', WiderRegionIdTupleMigration::mapReference('wider_region:roman/Europe'));
        self::assertSame('wider_region:roman/middle-east', WiderRegionIdTupleMigration::mapReference('wider_region:roman/Middle East'));
        self::assertSame('wider_region:roman/europe', WiderRegionIdTupleMigration::mapReference('wider_region:roman/europe'));
        self::assertSame('national_calendar:roman/IT', WiderRegionIdTupleMigration::mapReference('national_calendar:roman/IT'));
        self::assertSame('user:abc', WiderRegionIdTupleMigration::mapReference('user:abc'));
    }

    public function testPlanCopiesOnlyTuplesNamingALegacyRegion(): void
    {
        $migration = new WiderRegionIdTupleMigration($this->clientReturning([
            ['user' => 'user:1', 'relation' => 'editor', 'object' => 'wider_region:roman/Europe'],
            ['user' => 'national_calendar:roman/IT', 'relation' => 'member_nation', 'object' => 'wider_region:roman/Europe'],
            ['user' => 'user:2', 'relation' => 'editor', 'object' => 'wider_region:roman/asia'],
            ['user' => 'user:3', 'relation' => 'editor', 'object' => 'national_calendar:roman/US'],
        ]));

        self::assertSame(
            [
                ['from' => ['user' => 'user:1', 'relation' => 'editor', 'object' => 'wider_region:roman/Europe'],
                 'to'   => ['user' => 'user:1', 'relation' => 'editor', 'object' => 'wider_region:roman/europe']],
                ['from' => ['user' => 'national_calendar:roman/IT', 'relation' => 'member_nation', 'object' => 'wider_region:roman/Europe'],
                 'to'   => ['user' => 'national_calendar:roman/IT', 'relation' => 'member_nation', 'object' => 'wider_region:roman/europe']],
            ],
            $migration->plan()
        );
    }

    public function testApplyWritesBeforeItDeletesAndOnlyDeletesWithPrune(): void
    {
        // Queue: read page, write (201/200), delete (200). Assert the recorded request order is read, write, delete,
        // and that apply(false) sends no delete.
    }
```

  Write out the last test's body fully, with the history middleware: the recorded request paths must end in `/read`,
  `/write` for `apply(false)`, and in `/read`, `/write`, `/write` (a `deletes` body) for `apply(true)`. Check how
  `deleteTuple()` posts, and assert on the body keys `writes` / `deletes`.

- [ ] **Step 2: Run it and verify it fails**

Run: `vendor/bin/phpunit phpunit_tests/Services/WiderRegionIdTupleMigrationTest.php`
Expected: FAIL, class not found.

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services;

use LiturgicalCalendar\Api\Models\RegionalData\WiderRegionId;
use LiturgicalCalendar\Api\Services\Exception\TupleAlreadyExistsException;
use LiturgicalCalendar\Api\Services\Exception\TupleNotFoundException;

/**
 * Moves OpenFGA tuples from legacy wider region objects to their ids (#1018): `wider_region:roman/Europe` →
 * `wider_region:roman/europe`, on the object side (grants, `member_nation`) and the user side, should a model ever
 * put a region there. Copy first, then, only when asked, delete: a tuple is never removed before its replacement
 * exists. Writing an existing tuple and deleting a missing one are both benign, so a re-run is safe.
 */
final class WiderRegionIdTupleMigration
{
    public function __construct(private readonly OpenFgaClient $client)
    {
    }

    public static function mapReference(string $reference): string
    {
        if (!str_starts_with($reference, 'wider_region:')) {
            return $reference;
        }
        $parsed = RiteScopedObjectId::parse(substr($reference, strlen('wider_region:')));
        if ($parsed === null) {
            return $reference;
        }
        [$rite, $id] = $parsed;
        $normalized  = WiderRegionId::normalize($id);
        if ($normalized === null || $normalized[1] === false) {
            return $reference;
        }

        return 'wider_region:' . RiteScopedObjectId::qualify($rite, $normalized[0]);
    }

    /** @return list<array{from: array{user:string,relation:string,object:string}, to: array{user:string,relation:string,object:string}}> */
    public function plan(): array
    {
        $plan  = [];
        $token = null;
        do {
            $page = $this->client->readTuples('', '', null, null, $token);
            foreach ($page['tuples'] as $t) {
                $to = ['user' => self::mapReference($t['user']), 'relation' => $t['relation'], 'object' => self::mapReference($t['object'])];
                if ($to !== $t) {
                    $plan[] = ['from' => $t, 'to' => $to];
                }
            }
            $token = $page['next_continuation_token'] !== '' ? $page['next_continuation_token'] : null;
        } while ($token !== null);

        return $plan;
    }

    /** @return array{copied: int, pruned: int} */
    public function apply(bool $prune): array
    {
        $copied = 0;
        $pruned = 0;
        foreach ($this->plan() as $step) {
            try {
                $this->client->writeTuple($step['to']['user'], $step['to']['relation'], $step['to']['object']);
            } catch (TupleAlreadyExistsException) {
            }
            ++$copied;
            if ($prune) {
                try {
                    $this->client->deleteTuple($step['from']['user'], $step['from']['relation'], $step['from']['object']);
                } catch (TupleNotFoundException) {
                }
                ++$pruned;
            }
        }

        return ['copied' => $copied, 'pruned' => $pruned];
    }
}
```

  (`RiteScopedObjectId::parse()` returns `array{0: Rite, 1: string}|null`, and `qualify(Rite, string)` takes the
  same pair.)

- [ ] **Step 4: Write the CLI wrapper** `scripts/migrate-wider-region-ids.php`. Copy the header conventions of
  `scripts/migrate-rite-data-tuples.php`:
  - the `#!` line, the `PHP_SAPI !== 'cli'` guard and the Dotenv bootstrap;
  - `--apply` / `--prune` flags, dry run by default, and the `OpenFgaClient::isConfigured()` check.

  Its body:

```php
$migration = new WiderRegionIdTupleMigration(OpenFgaClient::fromEnv());
if (!$apply) {
    foreach ($migration->plan() as $step) {
        echo "- {$step['from']['user']} {$step['from']['relation']} {$step['from']['object']}" . PHP_EOL;
        echo "+ {$step['to']['user']} {$step['to']['relation']} {$step['to']['object']}" . PHP_EOL;
    }
    echo 'Dry run. Pass --apply to copy, and --apply --prune to also delete the originals.' . PHP_EOL;
    exit(0);
}
$result = $migration->apply($prune);
printf("copied=%d pruned=%d\n", $result['copied'], $result['pruned']);
```

- [ ] **Step 5: Run the tests, then smoke-test against local OpenFGA**

Run: `vendor/bin/phpunit phpunit_tests/Services/WiderRegionIdTupleMigrationTest.php && php -l scripts/migrate-wider-region-ids.php`
Expected: PASS and "No syntax errors". Then run `php scripts/migrate-wider-region-ids.php` against the local
OpenFGA (from `docker compose`). Expected: a dry run listing any local `Europe`/`Americas`/`Asia` tuples, or an
empty plan.

- [ ] **Step 6: Commit**

```bash
git add src/Services/WiderRegionIdTupleMigration.php scripts/migrate-wider-region-ids.php phpunit_tests/Services/WiderRegionIdTupleMigrationTest.php
git commit -m "feat(wider-regions): OpenFGA tuple migration to region ids (#1018)"
```

---

### Task 10: OpenAPI, docs and the operator runbook

**Files:**

- Modify: `jsondata/schemas/openapi.json` (the lines in the inventory: path params ~8043-9753, examples ~17296-17679,
  object-id descriptions ~14509/16840/16958, prose ~9422/9626)
- Create: `docs/ops/wider-region-ids-runbook.md`
- Modify: `CLAUDE.md` (the wider_regions paragraph ~349-353 and the #1005 deploy note ~155-160),
  `docs/ops/rbac-create-governance-runbook.md:135-137, 281, 288`
- Modify: `scripts/notitiae/merge.py:26-27` and `scripts/notitiae/tests/test_merge.py`

- [ ] **Step 1: OpenAPI.** Through the formatter:
  - path params `$ref` → `WiderRegionId`;
  - example region bodies: `"wider_region": "europe"` / `"asia"`, plus a `labels` object (for Europe, the `en`, `it`,
    `de`, `fr` entries from `europe/europe.json`);
  - `/calendars` examples: `wider_regions` items get `id` and `label`, and `wider_regions` lists in nation items use
    ids;
  - object-id descriptions: `roman/Americas` → `roman/americas`.

  Add an `Accept-Language` header parameter to `GET /calendars` and `POST /calendars`, described as "Selects each
  wider region's `label`; the response varies on it". Leave prose such as "the Americas" alone. Run:
  `composer lint:openapi && composer lint:jsondata`. Expected: no new warnings (development is at zero).
- [ ] **Step 2: Runbook** `docs/ops/wider-region-ids-runbook.md`, with numbered steps and code blocks indented three
  spaces:
  1. Before merging, check that there are no live change requests or outbox rows on legacy regions:

     ```sql
     SELECT id, review_status, publication_status FROM sourcedata_change_requests
      WHERE resource_type = 'wider_region'
        AND (review_status = 'submitted' OR publication_status IN ('queued', 'open'));
     SELECT id, status FROM openfga_outbox
      WHERE status IN ('pending', 'retrying') AND fga_object LIKE 'wider_region:%';
     ```

  2. The deploy runs the Doctrine migration (it aborts, failing the deploy, if step 1 was skipped) and restarts
     `litcal-jobs`.
  3. Right after the deploy: `php scripts/migrate-wider-region-ids.php` (review the `-`/`+` lines), then `--apply`.
     Until then, region editors are denied (fail closed).
  4. Verify: `GET /calendars` lists `americas`, `asia`, `europe` with labels; an editor's PUT to
     `/data/widerregion/europe/{locale}` succeeds; `/health` `wider_region_membership` is green.
  5. Once every deployment runs this code, run `php scripts/migrate-wider-region-ids.php --apply --prune`.
  6. Rollback: the Doctrine migration is irreversible, so roll back the code only and leave the data as ids. Old code
     cannot read ids, so treat rollback as a restore from the pre-deploy DB backup.

  Also record the date on which `audit_log` entries switched naming.
- [ ] **Step 3: CLAUDE.md and runbooks.**
  - The wider-region paragraph: ids are lowercase kebab-case (`["europe", "nordic"]`), labels live in the region's
    `metadata.labels`, and the deprecated capitalised names are accepted on input only.
  - Point the #1005 deploy note at the new runbook for #1018.
  - `roman/Europe` → `roman/europe` in the RBAC runbook.
- [ ] **Step 4: Notitiae merge.** The register keeps its descriptive names, and the comparison maps them. In
  `merge.py`, change the wider-region arm to:

```python
    if level == "wider_region":
        name = target.get("wider_region") or ""
        return name.lower().replace(" ", "-") in impl["wider_regions"]
```

  Update `tests/test_merge.py` so that the implemented set is `{"europe"}` while the register value stays `"Europe"`.
  Run: `cd scripts && python3 -m pytest notitiae/tests/test_merge.py -q`. Expected: PASS.

- [ ] **Step 5: Lint the markdown.**
  Run:

  ```bash
  npx --yes markdownlint-cli2 CLAUDE.md docs/ops/wider-region-ids-runbook.md docs/ops/rbac-create-governance-runbook.md docs/superpowers/specs/2026-09-30-wider-region-ids-and-labels-design.md docs/superpowers/plans/2026-09-30-wider-region-ids-and-labels.md
  ```

  Expected: 0 issues.
- [ ] **Step 6: Commit**

```bash
git add -A jsondata/schemas/openapi.json docs CLAUDE.md scripts/notitiae
git commit -m "docs(wider-regions): OpenAPI, runbook and docs for region ids and labels (#1018)"
```

---

### Task 11: Whole-branch verification and PR

- [ ] **Step 1: Search for leftover capitalised ids**

Run:

```bash
grep -rnE "widerregion/(Americas|Asia|Europe)|wider_region:(roman/)?(Americas|Asia|Europe)|\"wider_regions\": \[[^]]*\"[A-Z]" src jsondata/sourcedata jsondata/schemas phpunit_tests scripts docs/ops CLAUDE.md
```

Expected: nothing, except the deliberate legacy-input tests (Tasks 4, 5, 6, 8, 9) and the runbook's description of
the old names.

- [ ] **Step 2: Run the full gate**

Run: `rm -rf /tmp/phpstan && composer analyse && composer lint && composer lint:jsondata && composer lint:locales && composer lint:missals && composer lint:openapi && composer test:quick`
Expected: all pass, with 0 failures. Make sure no other worktree is running `test:quick` at the same time, since the
suites share one Postgres.

- [ ] **Step 3: Push and open the PR** against `development`:
  - title: `feat(wider-regions)!: kebab-case ids and localized labels (#1018)`;
  - the body summarizes the design;
  - list the operator steps from the runbook;
  - flag that the Frontend follow-up must merge close to this PR;
  - end with the attribution line.

## Follow-up (separate plan, LiturgicalCalendarFrontend)

- Switch the pickers, the extending page and the prospective list to `id` + `label`.
- Add a label editor to the region form (keys limited to the region's locale languages plus `en`).
- Send `labels` in PUT/PATCH.
- Move the prospective regions to kebab-case ids with Frontend-side labels.
- Stop `$ref`ing `WiderRegionName`.
