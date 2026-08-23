# Diocesan `setProperty` Action Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or
superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for
tracking.

**Goal:** Replace the Ambrosian diocesan "re-declare the comune `event_key` and remove/re-add it" override
mechanism with an in-place `setProperty` action mirroring the existing National calendar vocabulary.

**Architecture:** A diocesan litcal item gains an optional `metadata.action`. Absent means `createNew`
(today's behaviour, so all 16 existing diocesan files keep parsing unchanged). `setProperty` plus
`metadata.property` of `grade`, `name`, or `common` selects one of three new model classes, which
`CalendarHandler::applyAmbrosianDiocesanCalendar()` dispatches to `LiturgicalEventCollection::setProperty()`
instead of building and adding a new event. `EventsHandler` mirrors the dispatch so `/events` and `/calendar`
agree on the key.

**Tech Stack:** PHP 8.4, PHPUnit 12, PHPStan level 10, PHP_CodeSniffer (PSR-12 + project ruleset), JSON Schema draft-07.

**Spec:** `docs/superpowers/specs/2026-08-23-diocesan-set-property-design.md`

## Global Constraints

- PHP >= 8.4. Short array syntax `[]`. 4-space indent. Single quotes unless interpolating.
- PHPStan level 10 must pass: `composer analyse` (scans `src` only).
- phpcs must pass: `composer lint`. Auto-fix with `composer lint:fix`.
- Markdown must pass `composer lint:md` (max 180 cols, tables vertically aligned).
- **Roman rite output must stay byte-identical.** Golden master 9/9: `vendor/bin/phpunit phpunit_tests/Handlers/CalendarGoldenMasterTest.php`.
- **Never pass `--exclude-group` on the CLI** — it un-fences `CalendarGoldenMasterGenerateTest`, which
  silently rewrites the fixtures it is checked against. Use `composer test:quick`.
- Do NOT add any new test to the `slow` group.
- Never commit with `--no-verify`. Fix hook failures and re-commit.
- Work happens in the worktree `.claude/worktrees/issue-740` on branch `feature/740-diocesan-set-property`. It has its own real `vendor/`. Do not `cd` to the main checkout.
- Do not use Serena's edit tools in this worktree — they resolve paths against the main checkout. Use ordinary file edits.

---

### Task 1: Widen `setProperty()` to accept `array` and `LitCommons`

`LiturgicalEvent::$common` is typed `LitCommons|array`, but `setProperty()`'s `$newValue` parameter is
`string|int|bool|LitGrade`, so passing a common raises a `TypeError` before the method body runs. The
reflection logic inside already handles union-typed properties correctly and must not change behaviour.

**Files:**

- Modify: `src/Models/Calendar/LiturgicalEventCollection.php:827` (signature), `:845-848` (union check readability)
- Test: `phpunit_tests/Models/Calendar/LiturgicalEventCollectionSetPropertyCommonTest.php`

**Interfaces:**

- Consumes: nothing from earlier tasks.
- Produces: `LiturgicalEventCollection::setProperty(string $key, string $property, string|int|bool|array|LitGrade|LitCommons $newValue): bool` — used by Task 5.

- [ ] **Step 1: Write the failing test**

Create `phpunit_tests/Models/Calendar/LiturgicalEventCollectionSetPropertyCommonTest.php`:

```php
<?php

namespace LiturgicalCalendar\Api\Tests\Models\Calendar;

use LiturgicalCalendar\Api\DateTime;
use LiturgicalCalendar\Api\Enum\LitGrade;
use LiturgicalCalendar\Api\Models\Calendar\LitCommons;
use LiturgicalCalendar\Api\Models\Calendar\LiturgicalEvent;
use LiturgicalCalendar\Api\Models\Calendar\LiturgicalEventCollection;
use LiturgicalCalendar\Api\Params\CalendarParams;
use PHPUnit\Framework\TestCase;

/**
 * `LiturgicalEvent::$common` is typed `LitCommons|array`. Before this change `setProperty()`
 * declared `$newValue` as `string|int|bool|LitGrade`, so a `LitCommons` could not even reach the
 * method body. These tests pin the widened signature.
 */
final class LiturgicalEventCollectionSetPropertyCommonTest extends TestCase
{
    private function makeCollection(): LiturgicalEventCollection
    {
        $params = new CalendarParams();
        $params->setParams(['year' => 2025, 'locale' => 'en']);

        return new LiturgicalEventCollection($params);
    }

    public function testSetPropertyAcceptsLitCommonsAndReplacesTheCommon(): void
    {
        $cal = $this->makeCollection();

        $event = new LiturgicalEvent('Test Event', new DateTime('2025-06-19'));
        $event->grade  = LitGrade::FEAST;
        $event->common = LitCommons::create(['Martyrs:For Several Martyrs']);
        $cal->addLiturgicalEvent('TestEvent', $event);

        $newCommon = LitCommons::create(['Proper']);
        self::assertNotNull($newCommon);

        self::assertTrue(
            $cal->setProperty('TestEvent', 'common', $newCommon),
            'setProperty() must accept a LitCommons value for the union-typed `common` property.'
        );
        self::assertSame($newCommon, $cal->getLiturgicalEvent('TestEvent')->common);
    }

    public function testSetPropertyReturnsFalseForAnAbsentKey(): void
    {
        $cal = $this->makeCollection();

        $newCommon = LitCommons::create(['Proper']);
        self::assertNotNull($newCommon);

        self::assertFalse(
            $cal->setProperty('NoSuchEvent', 'common', $newCommon),
            'setProperty() must report false (not throw) when the key is not in the collection.'
        );
    }

    public function testSetPropertyStillSetsGrade(): void
    {
        $cal = $this->makeCollection();

        $event = new LiturgicalEvent('Test Event', new DateTime('2025-06-19'));
        $event->grade = LitGrade::FEAST;
        $cal->addLiturgicalEvent('TestEvent', $event);

        self::assertTrue($cal->setProperty('TestEvent', 'grade', LitGrade::MEMORIAL));
        self::assertSame(LitGrade::MEMORIAL, $cal->getLiturgicalEvent('TestEvent')->grade);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit phpunit_tests/Models/Calendar/LiturgicalEventCollectionSetPropertyCommonTest.php`

Expected: FAIL. `testSetPropertyAcceptsLitCommonsAndReplacesTheCommon` and
`testSetPropertyReturnsFalseForAnAbsentKey` raise `TypeError: ...setProperty(): Argument #3 ($newValue) must
be of type string|int|bool|LitGrade, LiturgicalCalendar\Api\Models\Calendar\LitCommons given`.
`testSetPropertyStillSetsGrade` already passes.

- [ ] **Step 3: Widen the signature**

No import is needed: `LitCommons` lives in `LiturgicalCalendar\Api\Models\Calendar`, the same namespace as `LiturgicalEventCollection`.

In `src/Models/Calendar/LiturgicalEventCollection.php`, change the signature and its docblock `@param`:

```php
     * @param string|int|bool|array|LitGrade|LitCommons $newValue The new value for the property.
     * @return bool True if the property was successfully set, otherwise false.
     */
    public function setProperty(string $key, string $property, string|int|bool|array|LitGrade|LitCommons $newValue): bool
```

- [ ] **Step 4: Make the union check explicit**

Still in `setProperty()`, replace the union branch so it compares strings to strings rather than relying on
`ReflectionNamedType::__toString()` coercion. Behaviour is identical; this is readability only.

```php
                $unionTypeCondition = false;
                if ($reflectPropertyType instanceof \ReflectionUnionType) {
                    $memberTypeNames = array_map(
                        static fn (\ReflectionNamedType $memberType): string => $memberType->getName(),
                        array_filter(
                            $reflectPropertyType->getTypes(),
                            static fn (\ReflectionType $memberType): bool => $memberType instanceof \ReflectionNamedType
                        )
                    );
                    $unionTypeCondition = in_array(get_debug_type($newValue), $memberTypeNames, true);
                }
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `vendor/bin/phpunit phpunit_tests/Models/Calendar/LiturgicalEventCollectionSetPropertyCommonTest.php`

Expected: PASS, 3 tests.

- [ ] **Step 6: Verify nothing else regressed**

Run: `vendor/bin/phpunit phpunit_tests/Models/ phpunit_tests/Handlers/CalendarGoldenMasterTest.php`

Expected: PASS, golden master 9/9.

Run: `composer analyse && composer lint`

Expected: no errors.

- [ ] **Step 7: Commit**

```bash
git add src/Models/Calendar/LiturgicalEventCollection.php phpunit_tests/Models/Calendar/LiturgicalEventCollectionSetPropertyCommonTest.php
git commit -m "feat(calendar): let setProperty() set array and LitCommons valued properties"
```

---

### Task 2: `AmbrosianReadings::forGrade()`

The Ambrosian diocesan overlay currently stamps `emptyFestive()` on every diocesan row regardless of grade. Derive the placeholder from the grade instead.

**Files:**

- Modify: `src/Models/Lectionary/AmbrosianReadings.php`
- Test: `phpunit_tests/Models/Lectionary/AmbrosianReadingsForGradeTest.php`

**Interfaces:**

- Consumes: nothing from earlier tasks.
- Produces: `AmbrosianReadings::forGrade(LitGrade $grade): ReadingsFerial|ReadingsFestive` — used by Task 5.

- [ ] **Step 1: Write the failing test**

Create `phpunit_tests/Models/Lectionary/AmbrosianReadingsForGradeTest.php`:

```php
<?php

namespace LiturgicalCalendar\Api\Tests\Models\Lectionary;

use LiturgicalCalendar\Api\Enum\LitGrade;
use LiturgicalCalendar\Api\Models\Lectionary\AmbrosianReadings;
use LiturgicalCalendar\Api\Models\Lectionary\ReadingsFerial;
use LiturgicalCalendar\Api\Models\Lectionary\ReadingsFestive;
use PHPUnit\Framework\TestCase;

final class AmbrosianReadingsForGradeTest extends TestCase
{
    public function testFeastAndAboveGetTheFestivePlaceholder(): void
    {
        self::assertInstanceOf(ReadingsFestive::class, AmbrosianReadings::forGrade(LitGrade::FEAST));
        self::assertInstanceOf(ReadingsFestive::class, AmbrosianReadings::forGrade(LitGrade::FEAST_LORD));
        self::assertInstanceOf(ReadingsFestive::class, AmbrosianReadings::forGrade(LitGrade::SOLEMNITY));
        self::assertInstanceOf(ReadingsFestive::class, AmbrosianReadings::forGrade(LitGrade::HIGHER_SOLEMNITY));
    }

    public function testBelowFeastGetsTheFerialPlaceholder(): void
    {
        self::assertInstanceOf(ReadingsFerial::class, AmbrosianReadings::forGrade(LitGrade::MEMORIAL));
        self::assertInstanceOf(ReadingsFerial::class, AmbrosianReadings::forGrade(LitGrade::MEMORIAL_OPT));
        self::assertInstanceOf(ReadingsFerial::class, AmbrosianReadings::forGrade(LitGrade::COMMEMORATION));
        self::assertInstanceOf(ReadingsFerial::class, AmbrosianReadings::forGrade(LitGrade::WEEKDAY));
    }

    public function testPlaceholderFieldsAreEmptyStrings(): void
    {
        $festive = AmbrosianReadings::forGrade(LitGrade::SOLEMNITY);
        self::assertSame('', $festive->first_reading);
        self::assertSame('', $festive->second_reading);

        $ferial = AmbrosianReadings::forGrade(LitGrade::MEMORIAL);
        self::assertSame('', $ferial->first_reading);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit phpunit_tests/Models/Lectionary/AmbrosianReadingsForGradeTest.php`

Expected: FAIL with `Call to undefined method ...AmbrosianReadings::forGrade()`.

- [ ] **Step 3: Implement `forGrade()`**

In `src/Models/Lectionary/AmbrosianReadings.php`, add the import at the top:

```php
use LiturgicalCalendar\Api\Enum\LitGrade;
```

Then add this method after `emptyFestive()`:

```php
    /**
     * Selects the empty-readings placeholder that matches a liturgical grade.
     *
     * Festive (5-field) celebrations from FEAST upward carry a second reading; anything below
     * FEAST uses the ferial (4-field) shape. This keeps the Ambrosian diocesan overlay from
     * stamping a festive shape onto a memorial, which is what the blanket `emptyFestive()` call
     * it replaces used to do.
     *
     * @param LitGrade $grade The liturgical grade of the event being stamped.
     * @return ReadingsFerial|ReadingsFestive The placeholder matching the grade.
     */
    public static function forGrade(LitGrade $grade): ReadingsFerial|ReadingsFestive
    {
        return $grade->value >= LitGrade::FEAST->value
            ? self::emptyFestive()
            : self::empty();
    }
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit phpunit_tests/Models/Lectionary/AmbrosianReadingsForGradeTest.php`

Expected: PASS, 3 tests.

- [ ] **Step 5: Static analysis and style**

Run: `composer analyse && composer lint`

Expected: no errors.

- [ ] **Step 6: Commit**

```bash
git add src/Models/Lectionary/AmbrosianReadings.php phpunit_tests/Models/Lectionary/AmbrosianReadingsForGradeTest.php
git commit -m "feat(lectionary): derive the Ambrosian readings placeholder from the grade"
```

---

### Task 3: Diocesan `setProperty` model classes and item dispatch

Three item classes plus three metadata classes, mirroring
`src/Models/RegionalData/NationalData/LitCalItemSetPropertyGrade.php` and its metadata companion.
`DiocesanLitCalItem` gains an action dispatch; an absent `action` stays `createNew`.

**Files:**

- Create: `src/Models/RegionalData/DiocesanData/DiocesanLitCalItemSetPropertyGrade.php`
- Create: `src/Models/RegionalData/DiocesanData/DiocesanLitCalItemSetPropertyGradeMetadata.php`
- Create: `src/Models/RegionalData/DiocesanData/DiocesanLitCalItemSetPropertyName.php`
- Create: `src/Models/RegionalData/DiocesanData/DiocesanLitCalItemSetPropertyNameMetadata.php`
- Create: `src/Models/RegionalData/DiocesanData/DiocesanLitCalItemSetPropertyCommon.php`
- Create: `src/Models/RegionalData/DiocesanData/DiocesanLitCalItemSetPropertyCommonMetadata.php`
- Modify: `src/Models/RegionalData/DiocesanData/DiocesanLitCalItem.php`
- Test: `phpunit_tests/Models/RegionalData/DiocesanLitCalItemActionDispatchTest.php`

**Interfaces:**

- Consumes: nothing from earlier tasks.
- Produces, all in namespace `LiturgicalCalendar\Api\Models\RegionalData\DiocesanData`:
  - `DiocesanLitCalItemSetPropertyGrade` with `public readonly LitGrade $grade` and inherited `public protected(set) string $event_key`, `public string $name`
  - `DiocesanLitCalItemSetPropertyName` with only the inherited members
  - `DiocesanLitCalItemSetPropertyCommon` with `public readonly LitCommons $common` and the inherited members
  - each `*Metadata` with `public readonly CalEventAction $action`, `public readonly string $property`
  - `DiocesanLitCalItem::$liturgical_event` widened to the 5-way union
  Used by Tasks 5 and 6.

- [ ] **Step 1: Write the failing test**

Create `phpunit_tests/Models/RegionalData/DiocesanLitCalItemActionDispatchTest.php`:

```php
<?php

namespace LiturgicalCalendar\Api\Tests\Models\RegionalData;

use LiturgicalCalendar\Api\Enum\CalEventAction;
use LiturgicalCalendar\Api\Enum\LitGrade;
use LiturgicalCalendar\Api\Models\RegionalData\DiocesanData\DiocesanLitCalItem;
use LiturgicalCalendar\Api\Models\RegionalData\DiocesanData\DiocesanLitCalItemCreateNewFixed;
use LiturgicalCalendar\Api\Models\RegionalData\DiocesanData\DiocesanLitCalItemSetPropertyCommon;
use LiturgicalCalendar\Api\Models\RegionalData\DiocesanData\DiocesanLitCalItemSetPropertyGrade;
use LiturgicalCalendar\Api\Models\RegionalData\DiocesanData\DiocesanLitCalItemSetPropertyName;
use PHPUnit\Framework\TestCase;

final class DiocesanLitCalItemActionDispatchTest extends TestCase
{
    public function testAbsentActionStillBuildsACreateNewItem(): void
    {
        $item = DiocesanLitCalItem::fromArray([
            'liturgical_event' => [
                'event_key' => 'BeatoManfredoSettala',
                'color'     => ['white'],
                'grade'     => 3,
                'common'    => ['Proper'],
                'day'       => 27,
                'month'     => 1,
            ],
            'metadata' => ['since_year' => 2024, 'form_rownum' => 0],
        ]);

        self::assertInstanceOf(DiocesanLitCalItemCreateNewFixed::class, $item->liturgical_event);
    }

    public function testSetPropertyGradeBuildsAGradeItem(): void
    {
        $item = DiocesanLitCalItem::fromArray([
            'liturgical_event' => ['event_key' => 'StsProtaseGervase', 'grade' => 3],
            'metadata'         => [
                'action'     => 'setProperty',
                'property'   => 'grade',
                'since_year' => 2024,
                'form_rownum' => 1,
            ],
        ]);

        self::assertInstanceOf(DiocesanLitCalItemSetPropertyGrade::class, $item->liturgical_event);
        self::assertSame(LitGrade::MEMORIAL, $item->liturgical_event->grade);
        self::assertSame(CalEventAction::SetProperty, $item->metadata->action);
        self::assertSame('grade', $item->metadata->property);
    }

    public function testSetPropertyCommonBuildsACommonItem(): void
    {
        $item = DiocesanLitCalItem::fromArray([
            'liturgical_event' => ['event_key' => 'StsProtaseGervase', 'common' => ['Proper']],
            'metadata'         => [
                'action'     => 'setProperty',
                'property'   => 'common',
                'since_year' => 2024,
                'form_rownum' => 2,
            ],
        ]);

        self::assertInstanceOf(DiocesanLitCalItemSetPropertyCommon::class, $item->liturgical_event);
        self::assertSame('Proper', $item->liturgical_event->common->jsonSerialize()[0]);
    }

    public function testSetPropertyNameBuildsANameItemWithNoOtherRequiredFields(): void
    {
        $item = DiocesanLitCalItem::fromArray([
            'liturgical_event' => ['event_key' => 'StFrancisOfAssisi'],
            'metadata'         => [
                'action'     => 'setProperty',
                'property'   => 'name',
                'since_year' => 2024,
                'form_rownum' => 3,
            ],
        ]);

        self::assertInstanceOf(DiocesanLitCalItemSetPropertyName::class, $item->liturgical_event);
        self::assertSame('StFrancisOfAssisi', $item->liturgical_event->event_key);
    }

    public function testUnknownPropertyThrows(): void
    {
        $this->expectException(\ValueError::class);

        DiocesanLitCalItem::fromArray([
            'liturgical_event' => ['event_key' => 'StFrancisOfAssisi', 'color' => ['white']],
            'metadata'         => [
                'action'     => 'setProperty',
                'property'   => 'color',
                'since_year' => 2024,
                'form_rownum' => 1,
            ],
        ]);
    }

    public function testUnknownActionThrows(): void
    {
        $this->expectException(\ValueError::class);

        DiocesanLitCalItem::fromArray([
            'liturgical_event' => ['event_key' => 'StFrancisOfAssisi'],
            'metadata'         => ['action' => 'makePatron', 'since_year' => 2024, 'form_rownum' => 1],
        ]);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit phpunit_tests/Models/RegionalData/DiocesanLitCalItemActionDispatchTest.php`

Expected: FAIL — `Class "...DiocesanLitCalItemSetPropertyGrade" not found`. Only `testAbsentActionStillBuildsACreateNewItem` passes.

- [ ] **Step 3: Create the grade item class**

Create `src/Models/RegionalData/DiocesanData/DiocesanLitCalItemSetPropertyGrade.php`:

```php
<?php

namespace LiturgicalCalendar\Api\Models\RegionalData\DiocesanData;

use LiturgicalCalendar\Api\Enum\LitGrade;
use LiturgicalCalendar\Api\Models\LiturgicalEventData;

/**
 * A diocesan `setProperty:grade` item: changes the grade of an event that already exists in the
 * calendar, rather than declaring a new one.
 */
final class DiocesanLitCalItemSetPropertyGrade extends LiturgicalEventData
{
    public readonly LitGrade $grade;

    private function __construct(string $event_key, LitGrade $grade)
    {
        if ($grade === LitGrade::HIGHER_SOLEMNITY || $grade === LitGrade::FEAST_LORD) {
            throw new \ValueError('Diocesan events cannot have grade HIGHER_SOLEMNITY or FEAST_LORD');
        }
        parent::__construct($event_key);
        $this->grade = $grade;
    }

    /**
     * @param \stdClass&object{event_key:string,grade:int} $data
     * @return static
     * @throws \ValueError if `event_key` or `grade` is missing.
     */
    protected static function fromObjectInternal(\stdClass $data): static
    {
        if (false === property_exists($data, 'event_key') || false === property_exists($data, 'grade')) {
            throw new \ValueError('`liturgical_event->event_key` and `liturgical_event->grade` are required for a `metadata->action` of `setProperty` when the property is `grade`');
        }
        return new static($data->event_key, LitGrade::from($data->grade));
    }

    /**
     * @param array{event_key:string,grade:int} $data
     * @return static
     * @throws \ValueError if `event_key` or `grade` is missing.
     */
    protected static function fromArrayInternal(array $data): static
    {
        if (false === array_key_exists('event_key', $data) || false === array_key_exists('grade', $data)) {
            throw new \ValueError('`liturgical_event->event_key` and `liturgical_event->grade` are required for a `metadata->action` of `setProperty` when the property is `grade`');
        }
        return new static($data['event_key'], LitGrade::from($data['grade']));
    }
}
```

- [ ] **Step 4: Create the name item class**

Create `src/Models/RegionalData/DiocesanData/DiocesanLitCalItemSetPropertyName.php`:

```php
<?php

namespace LiturgicalCalendar\Api\Models\RegionalData\DiocesanData;

use LiturgicalCalendar\Api\Models\LiturgicalEventData;

/**
 * A diocesan `setProperty:name` item.
 *
 * Diocesan calendars keep their names in the per-locale i18n tree, not in the calendar row, so
 * this item carries only an `event_key`. `DiocesanData::setNames()` stamps the inherited `$name`
 * property from the i18n file before the overlay runs.
 */
final class DiocesanLitCalItemSetPropertyName extends LiturgicalEventData
{
    private function __construct(string $event_key)
    {
        parent::__construct($event_key);
    }

    /**
     * @param \stdClass&object{event_key:string} $data
     * @return static
     * @throws \ValueError if `event_key` is missing.
     */
    protected static function fromObjectInternal(\stdClass $data): static
    {
        if (false === property_exists($data, 'event_key')) {
            throw new \ValueError('`liturgical_event->event_key` is required for a `metadata->action` of `setProperty` when the property is `name`');
        }
        return new static($data->event_key);
    }

    /**
     * @param array{event_key:string} $data
     * @return static
     * @throws \ValueError if `event_key` is missing.
     */
    protected static function fromArrayInternal(array $data): static
    {
        if (false === array_key_exists('event_key', $data)) {
            throw new \ValueError('`liturgical_event->event_key` is required for a `metadata->action` of `setProperty` when the property is `name`');
        }
        return new static($data['event_key']);
    }
}
```

- [ ] **Step 5: Create the common item class**

Create `src/Models/RegionalData/DiocesanData/DiocesanLitCalItemSetPropertyCommon.php`:

```php
<?php

namespace LiturgicalCalendar\Api\Models\RegionalData\DiocesanData;

use LiturgicalCalendar\Api\Models\Calendar\LitCommons;
use LiturgicalCalendar\Api\Models\LiturgicalEventData;

/**
 * A diocesan `setProperty:common` item: replaces the Common of an event that already exists in
 * the calendar. Used where a suffragan diocese celebrates a comune feast as `Proper`.
 */
final class DiocesanLitCalItemSetPropertyCommon extends LiturgicalEventData
{
    public readonly LitCommons $common;

    private function __construct(string $event_key, LitCommons $common)
    {
        parent::__construct($event_key);
        $this->common = $common;
    }

    /**
     * @param \stdClass&object{event_key:string,common:string[]} $data
     * @return static
     * @throws \ValueError if `event_key` or `common` is missing or invalid.
     */
    protected static function fromObjectInternal(\stdClass $data): static
    {
        if (false === property_exists($data, 'event_key') || false === property_exists($data, 'common')) {
            throw new \ValueError('`liturgical_event->event_key` and `liturgical_event->common` are required for a `metadata->action` of `setProperty` when the property is `common`');
        }
        $commons = LitCommons::create($data->common);
        if (null === $commons) {
            throw new \ValueError('invalid common: expected an array of LitCommon enum cases, LitCommon enum values, or LitMassVariousNeeds instances');
        }
        return new static($data->event_key, $commons);
    }

    /**
     * @param array{event_key:string,common:string[]} $data
     * @return static
     * @throws \ValueError if `event_key` or `common` is missing or invalid.
     */
    protected static function fromArrayInternal(array $data): static
    {
        if (false === array_key_exists('event_key', $data) || false === array_key_exists('common', $data)) {
            throw new \ValueError('`liturgical_event->event_key` and `liturgical_event->common` are required for a `metadata->action` of `setProperty` when the property is `common`');
        }
        $commons = LitCommons::create($data['common']);
        if (null === $commons) {
            throw new \ValueError('invalid common: expected an array of LitCommon enum cases, LitCommon enum values, or LitMassVariousNeeds instances');
        }
        return new static($data['event_key'], $commons);
    }
}
```

- [ ] **Step 6: Create the three metadata classes**

Create `src/Models/RegionalData/DiocesanData/DiocesanLitCalItemSetPropertyGradeMetadata.php`:

```php
<?php

namespace LiturgicalCalendar\Api\Models\RegionalData\DiocesanData;

use LiturgicalCalendar\Api\Enum\CalEventAction;
use LiturgicalCalendar\Api\Models\LiturgicalEventMetadata;

final class DiocesanLitCalItemSetPropertyGradeMetadata extends LiturgicalEventMetadata
{
    public readonly CalEventAction $action;

    public readonly string $property;

    private function __construct(int $since_year, ?int $until_year = null)
    {
        parent::__construct($since_year, $until_year);
        $this->action   = CalEventAction::SetProperty;
        $this->property = 'grade';
    }

    /**
     * @param \stdClass&object{since_year:int,until_year?:int,property:string} $data
     * @return static
     */
    protected static function fromObjectInternal(\stdClass $data): static
    {
        if (false === property_exists($data, 'since_year') || false === property_exists($data, 'property') || $data->property !== 'grade') {
            throw new \ValueError('`since_year` and `property` are required for an `action` of `setProperty`, and `property` must have a value of `grade`');
        }
        return new static($data->since_year, $data->until_year ?? null);
    }

    /**
     * @param array{since_year:int,until_year?:int,property:string} $data
     * @return static
     */
    protected static function fromArrayInternal(array $data): static
    {
        if (false === isset($data['since_year']) || false === isset($data['property']) || $data['property'] !== 'grade') {
            throw new \ValueError('`since_year` and `property` are required for an `action` of `setProperty`, and `property` must have a value of `grade`');
        }
        return new static($data['since_year'], $data['until_year'] ?? null);
    }
}
```

Create `src/Models/RegionalData/DiocesanData/DiocesanLitCalItemSetPropertyNameMetadata.php` — identical, with
`'grade'` replaced by `'name'` in both the `$this->property` assignment and the two guards and their messages.

Create `src/Models/RegionalData/DiocesanData/DiocesanLitCalItemSetPropertyCommonMetadata.php` — identical, with `'grade'` replaced by `'common'` in the same three places.

- [ ] **Step 7: Add the dispatch to `DiocesanLitCalItem`**

In `src/Models/RegionalData/DiocesanData/DiocesanLitCalItem.php`, replace the property declarations and
constructor. Keep the `@phpstan-type` blocks above the class but change the `metadata` shapes to include
`property?:string`.

```php
    public readonly DiocesanLitCalItemCreateNewFixed|DiocesanLitCalItemCreateNewMobile|DiocesanLitCalItemSetPropertyGrade|DiocesanLitCalItemSetPropertyName|DiocesanLitCalItemSetPropertyCommon $liturgical_event;

    public readonly DiocesanLitCalItemMetadata|DiocesanLitCalItemSetPropertyGradeMetadata|DiocesanLitCalItemSetPropertyNameMetadata|DiocesanLitCalItemSetPropertyCommonMetadata $metadata;

    private function __construct(\stdClass $liturgical_event, \stdClass $metadata)
    {
        if (false === property_exists($liturgical_event, 'event_key')) {
            throw new \ValueError('litcalItem.liturgical_event must have an `event_key` property');
        }

        // An absent `action` means `createNew`: every diocesan file shipped before the
        // `setProperty` action existed omits it, and must keep parsing unchanged.
        $action = property_exists($metadata, 'action') ? $metadata->action : CalEventAction::CreateNew->value;

        if ($action === CalEventAction::SetProperty->value) {
            if (false === property_exists($metadata, 'property')) {
                throw new \ValueError('`metadata->property` is required for a `metadata->action` of `setProperty`');
            }

            switch ($metadata->property) {
                case 'grade':
                    $this->liturgical_event = DiocesanLitCalItemSetPropertyGrade::fromObject($liturgical_event);
                    $this->metadata         = DiocesanLitCalItemSetPropertyGradeMetadata::fromObject($metadata);
                    return;
                case 'name':
                    $this->liturgical_event = DiocesanLitCalItemSetPropertyName::fromObject($liturgical_event);
                    $this->metadata         = DiocesanLitCalItemSetPropertyNameMetadata::fromObject($metadata);
                    return;
                case 'common':
                    $this->liturgical_event = DiocesanLitCalItemSetPropertyCommon::fromObject($liturgical_event);
                    $this->metadata         = DiocesanLitCalItemSetPropertyCommonMetadata::fromObject($metadata);
                    return;
                default:
                    throw new \ValueError('unsupported `metadata->property` for a diocesan `setProperty` action: ' . $metadata->property . '. Supported properties are `grade`, `name`, `common`.');
            }
        }

        if ($action !== CalEventAction::CreateNew->value) {
            throw new \ValueError('unsupported `metadata->action` for a diocesan calendar item: ' . $action . '. Supported actions are `createNew`, `setProperty`.');
        }

        if (property_exists($liturgical_event, 'strtotime')) {
            $this->liturgical_event = DiocesanLitCalItemCreateNewMobile::fromObject($liturgical_event);
        } else {
            $this->liturgical_event = DiocesanLitCalItemCreateNewFixed::fromObject($liturgical_event);
        }
        $this->metadata = DiocesanLitCalItemMetadata::fromObject($metadata);
    }
```

Add the import at the top of the file:

```php
use LiturgicalCalendar\Api\Enum\CalEventAction;
```

`setKey()` calls `$this->liturgical_event->setKey($key)`, which only `DiocesanLitCalItemCreateNewFixed` and
`DiocesanLitCalItemCreateNewMobile` define. Guard it so a `setProperty` item cannot reach it:

```php
    public function setKey(string $key): void
    {
        if (
            false === $this->liturgical_event instanceof DiocesanLitCalItemCreateNewFixed
            && false === $this->liturgical_event instanceof DiocesanLitCalItemCreateNewMobile
        ) {
            throw new \LogicException('setKey() is only meaningful for a createNew diocesan item; a setProperty item targets an existing event key.');
        }
        $this->unlock();
        $this->liturgical_event->setKey($key);
        $this->lock();
    }
```

- [ ] **Step 8: Run the test to verify it passes**

Run: `vendor/bin/phpunit phpunit_tests/Models/RegionalData/DiocesanLitCalItemActionDispatchTest.php`

Expected: PASS, 6 tests.

- [ ] **Step 9: Verify existing diocesan data still parses**

Run: `vendor/bin/phpunit phpunit_tests/Handlers/CalendarHandlerAmbrosianDiocesanTest.php phpunit_tests/Handlers/CalendarGoldenMasterTest.php`

Expected: PASS. All 16 existing diocesan files omit `action`, so nothing should change yet.

Run: `composer analyse && composer lint`

Expected: no errors.

- [ ] **Step 10: Commit**

```bash
git add src/Models/RegionalData/DiocesanData/ phpunit_tests/Models/RegionalData/DiocesanLitCalItemActionDispatchTest.php
git commit -m "feat(diocesan): add setProperty grade/name/common item types and action dispatch"
```

---

### Task 4: `DiocesanCalendar.json` schema branches

Split the inline litcal item object into a `oneOf` over named definitions, mirroring `NationalCalendar.json`.

**Files:**

- Modify: `jsondata/schemas/DiocesanCalendar.json`
- Test: `phpunit_tests/Schemas/DiocesanCalendarSetPropertySchemaTest.php`

**Interfaces:**

- Consumes: nothing.
- Produces: schema definitions `DiocesanCreateNewFixed`, `DiocesanCreateNewMobile`,
  `DiocesanSetPropertyGrade`, `DiocesanSetPropertyName`, `DiocesanSetPropertyCommon`. Task 7's data must
  validate against them.

- [ ] **Step 1: Write the failing test**

Create `phpunit_tests/Schemas/DiocesanCalendarSetPropertySchemaTest.php`:

This project validates with `swaggest/json-schema`, not Opis: `Schema::import($path)` then
`$schema->in($data)`, which throws on failure. `Router::getApiPaths()` must run before `LitSchema::path()`
works.

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Schemas;

use LiturgicalCalendar\Api\Enum\LitSchema;
use LiturgicalCalendar\Api\Router;
use PHPUnit\Framework\TestCase;
use Swaggest\JsonSchema\Schema;

/**
 * Pins the `setProperty` branches added to DiocesanCalendar.json.
 */
final class DiocesanCalendarSetPropertySchemaTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        Router::getApiPaths();
    }

    /**
     * Wraps litcal rows in the surrounding diocesan-calendar document.
     *
     * @param \stdClass[] $litcalItems
     */
    private function wrap(array $litcalItems): \stdClass
    {
        return (object) [
            'litcal'   => $litcalItems,
            'metadata' => (object) [
                'diocese_id'   => 'lugano_ch',
                'diocese_name' => 'Lugano',
                'nation'       => 'CH',
                'locales'      => ['it_IT', 'la_VA'],
                'timezone'     => 'Europe/Zurich',
                'rite'         => 'ambrosian',
            ],
        ];
    }

    public function testSetPropertyGradeRowValidates(): void
    {
        $schema = Schema::import(LitSchema::DIOCESAN->path());

        $schema->in($this->wrap([
            (object) [
                'liturgical_event' => (object) ['event_key' => 'StsProtaseGervase', 'grade' => 3],
                'metadata'         => (object) [
                    'action'      => 'setProperty',
                    'property'    => 'grade',
                    'since_year'  => 2024,
                    'form_rownum' => 1,
                ],
            ],
        ]));

        self::assertTrue(true, 'A setProperty:grade row must validate.');
    }

    public function testSetPropertyCommonRowValidates(): void
    {
        $schema = Schema::import(LitSchema::DIOCESAN->path());

        $schema->in($this->wrap([
            (object) [
                'liturgical_event' => (object) ['event_key' => 'StsProtaseGervase', 'common' => ['Proper']],
                'metadata'         => (object) [
                    'action'      => 'setProperty',
                    'property'    => 'common',
                    'since_year'  => 2024,
                    'form_rownum' => 2,
                ],
            ],
        ]));

        self::assertTrue(true, 'A setProperty:common row must validate.');
    }

    public function testSetPropertyNameRowValidatesWithOnlyAnEventKey(): void
    {
        $schema = Schema::import(LitSchema::DIOCESAN->path());

        $schema->in($this->wrap([
            (object) [
                'liturgical_event' => (object) ['event_key' => 'StFrancisOfAssisi'],
                'metadata'         => (object) [
                    'action'      => 'setProperty',
                    'property'    => 'name',
                    'since_year'  => 2024,
                    'form_rownum' => 3,
                ],
            ],
        ]));

        self::assertTrue(true, 'A setProperty:name row needs only an event_key.');
    }

    public function testCreateNewRowWithoutAnActionStillValidates(): void
    {
        $schema = Schema::import(LitSchema::DIOCESAN->path());

        $schema->in($this->wrap([
            (object) [
                'liturgical_event' => (object) [
                    'event_key' => 'BeatoManfredoSettala',
                    'color'     => ['white'],
                    'grade'     => 3,
                    'common'    => ['Proper'],
                    'day'       => 27,
                    'month'     => 1,
                ],
                'metadata' => (object) ['since_year' => 2024, 'form_rownum' => 0],
            ],
        ]));

        self::assertTrue(true, 'An action-less createNew row must keep validating.');
    }

    public function testSetPropertyGradeRowMissingGradeIsRejected(): void
    {
        $schema = Schema::import(LitSchema::DIOCESAN->path());

        $this->expectException(\Throwable::class);

        $schema->in($this->wrap([
            (object) [
                'liturgical_event' => (object) ['event_key' => 'StsProtaseGervase'],
                'metadata'         => (object) [
                    'action'      => 'setProperty',
                    'property'    => 'grade',
                    'since_year'  => 2024,
                    'form_rownum' => 1,
                ],
            ],
        ]));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit phpunit_tests/Schemas/DiocesanCalendarSetPropertySchemaTest.php`

Expected: FAIL — the three setProperty tests fail because `metadata.additionalProperties` is `false` and `action`/`property` are not declared. The createNew test passes.

- [ ] **Step 3: Restructure the schema**

In `jsondata/schemas/DiocesanCalendar.json`, replace the `definitions.LitCal` value with:

```json
"LitCal": {
    "type": "array",
    "minItems": 1,
    "items": {
        "oneOf": [
            { "$ref": "#/definitions/DiocesanSetPropertyGrade" },
            { "$ref": "#/definitions/DiocesanSetPropertyName" },
            { "$ref": "#/definitions/DiocesanSetPropertyCommon" },
            { "$ref": "#/definitions/DiocesanCreateNewFixed" },
            { "$ref": "#/definitions/DiocesanCreateNewMobile" }
        ]
    },
    "title": "LitCal"
}
```

Then add five new definitions alongside it. `DiocesanCreateNewFixed` is today's inline object with `strtotime`
removed and `action` added as an optional `"enum": ["createNew"]`; `DiocesanCreateNewMobile` is the same with
`day`/`month` removed, `strtotime` kept and required.

`oneOf` requires exactly one branch to match, so the create-new branches must not also accept a `setProperty`
row: keeping `color`, `grade` and `common` required on them plus `additionalProperties: false` on both objects
is what makes the branches disjoint. Splitting fixed from mobile the same way (fixed requires `day` + `month`
and forbids `strtotime`; mobile requires `strtotime` and forbids `day`/`month`) keeps those two disjoint from
each other.

The three setProperty definitions:

```json
"DiocesanSetPropertyGrade": {
    "type": "object",
    "additionalProperties": false,
    "properties": {
        "liturgical_event": {
            "type": "object",
            "additionalProperties": false,
            "properties": {
                "event_key": { "type": "string", "pattern": "^[A-Z](?:[A-Z]+_)*[a-zA-Z1-9]+[0-9]{0,2}(?:_vigil|_[0-9])?$" },
                "grade": { "$ref": "./CommonDef.json#/definitions/LitGrade" }
            },
            "required": ["event_key", "grade"]
        },
        "metadata": {
            "type": "object",
            "additionalProperties": false,
            "properties": {
                "form_rownum": { "type": "integer" },
                "since_year": { "type": "integer", "minimum": 1970 },
                "until_year": { "type": "integer", "maximum": 9999 },
                "action": { "type": "string", "const": "setProperty" },
                "property": { "type": "string", "const": "grade" },
                "url": { "type": "string", "format": "uri", "pattern": "^https://" }
            },
            "required": ["action", "property", "since_year", "form_rownum"]
        }
    },
    "required": ["liturgical_event", "metadata"],
    "title": "DiocesanSetPropertyGrade"
},
"DiocesanSetPropertyName": {
    "type": "object",
    "additionalProperties": false,
    "properties": {
        "liturgical_event": {
            "type": "object",
            "additionalProperties": false,
            "properties": {
                "event_key": { "type": "string", "pattern": "^[A-Z](?:[A-Z]+_)*[a-zA-Z1-9]+[0-9]{0,2}(?:_vigil|_[0-9])?$" }
            },
            "required": ["event_key"]
        },
        "metadata": {
            "type": "object",
            "additionalProperties": false,
            "properties": {
                "form_rownum": { "type": "integer" },
                "since_year": { "type": "integer", "minimum": 1970 },
                "until_year": { "type": "integer", "maximum": 9999 },
                "action": { "type": "string", "const": "setProperty" },
                "property": { "type": "string", "const": "name" },
                "url": { "type": "string", "format": "uri", "pattern": "^https://" }
            },
            "required": ["action", "property", "since_year", "form_rownum"]
        }
    },
    "required": ["liturgical_event", "metadata"],
    "title": "DiocesanSetPropertyName"
},
"DiocesanSetPropertyCommon": {
    "type": "object",
    "additionalProperties": false,
    "properties": {
        "liturgical_event": {
            "type": "object",
            "additionalProperties": false,
            "properties": {
                "event_key": { "type": "string", "pattern": "^[A-Z](?:[A-Z]+_)*[a-zA-Z1-9]+[0-9]{0,2}(?:_vigil|_[0-9])?$" },
                "common": { "$ref": "./CommonDef.json#/definitions/LitCommon" }
            },
            "required": ["event_key", "common"]
        },
        "metadata": {
            "type": "object",
            "additionalProperties": false,
            "properties": {
                "form_rownum": { "type": "integer" },
                "since_year": { "type": "integer", "minimum": 1970 },
                "until_year": { "type": "integer", "maximum": 9999 },
                "action": { "type": "string", "const": "setProperty" },
                "property": { "type": "string", "const": "common" },
                "url": { "type": "string", "format": "uri", "pattern": "^https://" }
            },
            "required": ["action", "property", "since_year", "form_rownum"]
        }
    },
    "required": ["liturgical_event", "metadata"],
    "title": "DiocesanSetPropertyCommon"
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit phpunit_tests/Schemas/DiocesanCalendarSetPropertySchemaTest.php`

Expected: PASS, 5 tests.

- [ ] **Step 5: Verify all existing source data still validates**

Run: `vendor/bin/phpunit phpunit_tests/Schemas/SchemaValidationTest.php`

Expected: PASS. All 16 existing diocesan files must still validate against the create-new branches. If a file
now fails with a `oneOf` error, the create-new branches are not disjoint — narrow them until exactly one
matches.

- [ ] **Step 6: Commit**

```bash
git add jsondata/schemas/DiocesanCalendar.json phpunit_tests/Schemas/DiocesanCalendarSetPropertySchemaTest.php
git commit -m "feat(schema): add diocesan setProperty branches to DiocesanCalendar.json"
```

---

### Task 5: Dispatch in `CalendarHandler`

Teach the Ambrosian overlay to apply setProperty rows in place, switch createNew rows to the grade-derived
readings placeholder, and make the Roman diocesan path reject the new action.

**Files:**

- Modify: `src/Handlers/CalendarHandler.php` — `applyAmbrosianDiocesanCalendar()` (from line 1164, the `foreach` body) and `applyDiocesanCalendar()` (from line 4669)
- Test: `phpunit_tests/Handlers/CalendarHandlerAmbrosianDiocesanSetPropertyTest.php`
- Modify: `phpunit_tests/Handlers/CalendarHandlerAmbrosianDiocesanTest.php`

**Interfaces:**

- Consumes: `setProperty()` from Task 1, `AmbrosianReadings::forGrade()` from Task 2, the item classes from Task 3.
- Produces: `CalendarHandler::applyAmbrosianDiocesanSetProperty(LiturgicalEventData $liturgicalEvent): void` — private, no later task consumes it.

- [ ] **Step 1: Write the failing test**

Create `phpunit_tests/Handlers/CalendarHandlerAmbrosianDiocesanSetPropertyTest.php`. It builds diocesan data in memory (no on-disk change yet), so it is independent of Task 7.

```php
<?php

namespace LiturgicalCalendar\Api\Tests\Handlers;

use LiturgicalCalendar\Api\Enum\LitGrade;
use LiturgicalCalendar\Api\Enum\LitLocale;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Handlers\CalendarHandler;
use LiturgicalCalendar\Api\Models\Calendar\LiturgicalEventCollection;
use LiturgicalCalendar\Api\Models\Lectionary\ReadingsFerial;
use LiturgicalCalendar\Api\Models\RegionalData\DiocesanData\DiocesanData;
use LiturgicalCalendar\Api\Params\CalendarParams;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the `setProperty` branch of `applyAmbrosianDiocesanCalendar()` with hand-built
 * diocesan data, so it does not depend on the on-disk re-authoring.
 */
final class CalendarHandlerAmbrosianDiocesanSetPropertyTest extends TestCase
{
    /**
     * Builds a handler whose comune sanctorale is already loaded, then swaps in synthetic
     * diocesan data and invokes only the diocesan overlay.
     *
     * @param array<int,array<string,mixed>> $litcal
     * @return array{0:LiturgicalEventCollection,1:CalendarHandler}
     */
    private function runOverlayWith(array $litcal, int $year = 2025): array
    {
        LitLocale::$PRIMARY_LANGUAGE = 'it';
        LitLocale::$RUNTIME_LOCALE   = 'it_IT';

        $params = new CalendarParams();
        $params->setRite(Rite::AMBROSIAN);
        $params->setParams(['year' => $year, 'locale' => 'it']);
        $params->DiocesanCalendar = 'lugano_ch';

        $handler    = new CalendarHandler([], Rite::AMBROSIAN);
        $handlerRef = new \ReflectionClass($handler);

        $paramsProp = $handlerRef->getProperty('CalendarParams');
        $paramsProp->setAccessible(true);
        $paramsProp->setValue($handler, $params);

        $calProp = $handlerRef->getProperty('Cal');
        $calProp->setAccessible(true);
        $calProp->setValue($handler, new LiturgicalEventCollection($params));

        $formatterProp = $handlerRef->getProperty('localeDateFormatter');
        $formatterProp->setAccessible(true);
        $formatterProp->setValue($handler, new \LiturgicalCalendar\Api\LocaleDateFormatter(LitLocale::$RUNTIME_LOCALE));

        // Load the comune sanctorale so the keys the overlay targets already exist.
        $sanctorale = $handlerRef->getMethod('addAmbrosianSanctoraleToCalendar');
        $sanctorale->setAccessible(true);
        $sanctorale->invoke($handler);

        $diocesanProp = $handlerRef->getProperty('DiocesanData');
        $diocesanProp->setAccessible(true);
        $diocesanProp->setValue($handler, DiocesanData::fromArray([
            'litcal'   => $litcal,
            'metadata' => [
                'diocese_id'   => 'lugano_ch',
                'diocese_name' => 'Lugano',
                'nation'       => 'CH',
                'locales'      => ['it_IT', 'la_VA'],
                'timezone'     => 'Europe/Zurich',
                'rite'         => 'ambrosian',
            ],
        ]));

        $overlay = $handlerRef->getMethod('applyAmbrosianDiocesanCalendar');
        $overlay->setAccessible(true);
        $overlay->invoke($handler);

        /** @var LiturgicalEventCollection $cal */
        $cal = $calProp->getValue($handler);

        return [$cal, $handler];
    }

    public function testSetPropertyGradeChangesTheComuneEventInPlace(): void
    {
        [$cal, ] = $this->runOverlayWith([
            [
                'liturgical_event' => ['event_key' => 'StsProtaseGervase', 'grade' => 3],
                'metadata'         => ['action' => 'setProperty', 'property' => 'grade', 'since_year' => 2024, 'form_rownum' => 1],
            ],
        ]);

        $event = $cal->getLiturgicalEvent('StsProtaseGervase');
        self::assertNotNull($event);
        self::assertSame(LitGrade::MEMORIAL, $event->grade, 'The diocesan downgrade must apply in place.');
        self::assertFalse(
            $cal->isSuppressed('StsProtaseGervase'),
            'An in-place property change must never record the event as suppressed.'
        );
        self::assertInstanceOf(
            ReadingsFerial::class,
            $event->readings,
            'A downgrade to MEMORIAL must carry the ferial placeholder.'
        );
    }

    public function testSetPropertyCommonChangesTheComuneEventInPlace(): void
    {
        [$cal, ] = $this->runOverlayWith([
            [
                'liturgical_event' => ['event_key' => 'StsProtaseGervase', 'common' => ['Proper']],
                'metadata'         => ['action' => 'setProperty', 'property' => 'common', 'since_year' => 2024, 'form_rownum' => 1],
            ],
        ]);

        $event = $cal->getLiturgicalEvent('StsProtaseGervase');
        self::assertNotNull($event);
        self::assertSame(['Proper'], $event->common->jsonSerialize());
    }

    public function testKeyIsNotDuplicatedByAnOverride(): void
    {
        [$cal, ] = $this->runOverlayWith([
            [
                'liturgical_event' => ['event_key' => 'StsProtaseGervase', 'grade' => 3],
                'metadata'         => ['action' => 'setProperty', 'property' => 'grade', 'since_year' => 2024, 'form_rownum' => 1],
            ],
        ]);

        $matching = array_filter(
            $cal->getKeys(),
            static fn (string $key): bool => str_contains($key, 'StsProtaseGervase')
        );

        self::assertSame(['StsProtaseGervase'], array_values($matching), 'Exactly one key must carry this celebration.');
    }

    public function testRowOutsideItsYearWindowIsSkipped(): void
    {
        [$cal, ] = $this->runOverlayWith([
            [
                'liturgical_event' => ['event_key' => 'StsProtaseGervase', 'grade' => 3],
                'metadata'         => ['action' => 'setProperty', 'property' => 'grade', 'since_year' => 2030, 'form_rownum' => 1],
            ],
        ], 2025);

        $event = $cal->getLiturgicalEvent('StsProtaseGervase');
        self::assertNotNull($event);
        self::assertSame(LitGrade::FEAST, $event->grade, 'A row whose since_year has not been reached must not apply.');
    }

    public function testSetPropertyOnAnAbsentKeyIsANoOpAndRecordsAMessage(): void
    {
        [$cal, $handler] = $this->runOverlayWith([
            [
                'liturgical_event' => ['event_key' => 'NoSuchAmbrosianEvent', 'grade' => 3],
                'metadata'         => ['action' => 'setProperty', 'property' => 'grade', 'since_year' => 2024, 'form_rownum' => 1],
            ],
        ]);

        self::assertNull(
            $cal->getLiturgicalEvent('NoSuchAmbrosianEvent'),
            'A setProperty row must never create the event it fails to find.'
        );

        $messagesProp = (new \ReflectionClass($handler))->getProperty('Messages');
        $messagesProp->setAccessible(true);
        /** @var string[] $messages */
        $messages = $messagesProp->getValue($handler);

        $matching = array_filter(
            $messages,
            static fn (string $message): bool => str_contains($message, 'NoSuchAmbrosianEvent')
        );

        self::assertNotEmpty($matching, 'A skipped setProperty row must record an explanatory message.');
    }
}
```

`runOverlayWith()` returns `[$cal, $handler]` rather than just the collection, because the absent-key test
needs to read the handler's private `Messages` array. Tests that do not need the handler destructure as
`[$cal, ] = ...`.

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit phpunit_tests/Handlers/CalendarHandlerAmbrosianDiocesanSetPropertyTest.php`

Expected: FAIL. The overlay currently calls `$liturgicalEvent->isMobile()` then reads `->day`/`->month`, so a setProperty item raises an undefined-property error.

- [ ] **Step 3: Rewrite the overlay loop**

In `src/Handlers/CalendarHandler.php`, replace the body of the `foreach` in `applyAmbrosianDiocesanCalendar()` with:

```php
        foreach ($this->DiocesanData->litcal as $litCalItem) {
            $meta = $litCalItem->metadata;
            if ($year < $meta->since_year || ( null !== $meta->until_year && $year > $meta->until_year )) {
                continue;
            }

            $liturgicalEvent = $litCalItem->liturgical_event;

            if (
                $liturgicalEvent instanceof DiocesanLitCalItemSetPropertyGrade
                || $liturgicalEvent instanceof DiocesanLitCalItemSetPropertyName
                || $liturgicalEvent instanceof DiocesanLitCalItemSetPropertyCommon
            ) {
                $this->applyAmbrosianDiocesanSetProperty($liturgicalEvent);
                continue;
            }

            if ($liturgicalEvent->isMobile()) {
                /** @var DiocesanLitCalItemCreateNewMobile $liturgicalEvent */
                $currentLitEventDate = $this->interpretStrtotime($liturgicalEvent);
            } else {
                /** @var DiocesanLitCalItemCreateNewFixed $liturgicalEvent */
                $currentLitEventDate = DateTime::fromFormat($liturgicalEvent->day . '-' . $liturgicalEvent->month . '-' . $year);
            }

            if (false === $currentLitEventDate) {
                // interpretStrtotime() already pushed an explanatory message onto $this->Messages.
                continue;
            }

            $liturgicalEvent->setDate($currentLitEventDate);

            $litEvent = LiturgicalEvent::fromObject($liturgicalEvent);
            $litEvent->setReadings(AmbrosianReadings::forGrade($litEvent->grade));
            $this->Cal->addLiturgicalEvent($liturgicalEvent->event_key, $litEvent);
        }
```

- [ ] **Step 4: Add the setProperty helper**

Immediately after `applyAmbrosianDiocesanCalendar()`, add:

```php
    /**
     * Applies a single Ambrosian diocesan `setProperty` row to `$this->Cal`.
     *
     * Unlike a createNew row, this modifies an event that a previous stage already placed in the
     * calendar — normally a comune sanctorale definition added by
     * {@see self::addAmbrosianSanctoraleToCalendar()}. The grade change flows through
     * `LiturgicalEventCollection::setProperty()`, which keeps the per-grade sub-collections
     * consistent, so nothing has to be removed and re-added and the comune event never surfaces
     * as a suppressed celebration.
     *
     * A grade change also re-stamps the readings placeholder, since the festive/ferial shape is
     * derived from the grade ({@see AmbrosianReadings::forGrade()}).
     *
     * When the target key is not in this year's calendar the row is a no-op and an explanatory
     * message is recorded, rather than failing the whole request.
     */
    private function applyAmbrosianDiocesanSetProperty(
        DiocesanLitCalItemSetPropertyGrade|DiocesanLitCalItemSetPropertyName|DiocesanLitCalItemSetPropertyCommon $liturgicalEvent
    ): void {
        $key = $liturgicalEvent->event_key;

        if (null === $this->Cal->getLiturgicalEvent($key)) {
            /**translators: 1. Diocese name, 2. Event key, 3. Requested calendar year */
            $this->Messages[] = sprintf(
                _('The diocesan calendar of %1$s tried to modify the liturgical event `%2$s`, but no such event exists in the calendar for the year %3$d. The modification was skipped.'),
                $this->DiocesanData->metadata->diocese_name,
                $key,
                $this->CalendarParams->Year
            );
            return;
        }

        if ($liturgicalEvent instanceof DiocesanLitCalItemSetPropertyGrade) {
            $this->Cal->setProperty($key, 'grade', $liturgicalEvent->grade);

            $litEvent = $this->Cal->getLiturgicalEvent($key);
            if (null !== $litEvent) {
                $litEvent->setReadings(AmbrosianReadings::forGrade($liturgicalEvent->grade));
            }
            return;
        }

        if ($liturgicalEvent instanceof DiocesanLitCalItemSetPropertyCommon) {
            $this->Cal->setProperty($key, 'common', $liturgicalEvent->common);
            return;
        }

        $this->Cal->setProperty($key, 'name', $liturgicalEvent->name);
    }
```

Add the three imports near the other `DiocesanData` imports at the top of `CalendarHandler.php`:

```php
use LiturgicalCalendar\Api\Models\RegionalData\DiocesanData\DiocesanLitCalItemSetPropertyCommon;
use LiturgicalCalendar\Api\Models\RegionalData\DiocesanData\DiocesanLitCalItemSetPropertyGrade;
use LiturgicalCalendar\Api\Models\RegionalData\DiocesanData\DiocesanLitCalItemSetPropertyName;
```

- [ ] **Step 5: Reject the action on the Roman path**

In `applyDiocesanCalendar()`, immediately inside the `foreach ($this->DiocesanData->litcal as $litCalItem) {` line, add:

```php
            if (
                $litCalItem->liturgical_event instanceof DiocesanLitCalItemSetPropertyGrade
                || $litCalItem->liturgical_event instanceof DiocesanLitCalItemSetPropertyName
                || $litCalItem->liturgical_event instanceof DiocesanLitCalItemSetPropertyCommon
            ) {
                throw new \RuntimeException(
                    'The `setProperty` action is not supported for Roman rite diocesan calendars; it is currently scoped to the Ambrosian overlay. Offending event key: '
                    . $litCalItem->liturgical_event->event_key
                );
            }
```

- [ ] **Step 6: Run the new test to verify it passes**

Run: `vendor/bin/phpunit phpunit_tests/Handlers/CalendarHandlerAmbrosianDiocesanSetPropertyTest.php`

Expected: PASS.

- [ ] **Step 7: Update the existing Ambrosian diocesan test for grade-derived readings**

In `phpunit_tests/Handlers/CalendarHandlerAmbrosianDiocesanTest.php`:

`testMilanProperEventIsAddedWithGradeNameAndFestiveReadings` asserts `SanLuigiGuanella` (grade SOLEMNITY) carries `ReadingsFestive`. That stays correct — leave it.

`testLuganoOverrideDowngradesStFrancisGradeToMemorial` asserts `ReadingsFestive` for a MEMORIAL. Change that one assertion to:

```php
        self::assertInstanceOf(
            ReadingsFerial::class,
            $event->readings,
            'The readings placeholder is derived from the grade; a MEMORIAL takes the ferial (4-field) shape.'
        );
```

and add the import:

```php
use LiturgicalCalendar\Api\Models\Lectionary\ReadingsFerial;
```

Also search the file for any other `ReadingsFestive` assertion on a sub-FEAST event and update it the same way:

```bash
grep -n "ReadingsFestive" phpunit_tests/Handlers/CalendarHandlerAmbrosianDiocesanTest.php
```

For each hit, check the event's grade; grade >= 4 keeps `ReadingsFestive`, below 4 becomes `ReadingsFerial`.

- [ ] **Step 8: Run the full handler suite and the golden master**

Run: `vendor/bin/phpunit phpunit_tests/Handlers/`

Expected: PASS, golden master 9/9 byte-identical.

Run: `composer analyse && composer lint`

Expected: no errors.

- [ ] **Step 9: Commit**

```bash
git add src/Handlers/CalendarHandler.php phpunit_tests/Handlers/CalendarHandlerAmbrosianDiocesanSetPropertyTest.php phpunit_tests/Handlers/CalendarHandlerAmbrosianDiocesanTest.php
git commit -m "feat(calendar): apply Ambrosian diocesan setProperty rows in place"
```

---

### Task 6: `/events` catalog parity

`/events` must stop emitting a prefixed duplicate for an overridden key and instead modify the comune catalog entry under its plain key.

`LiturgicalEventAbstract` derives `$grade_lcl`, `$grade_abbr` and `$common_lcl` in its constructor, and all
three are serialized by `jsonSerialize()`. Assigning `$grade` or `$common` directly would leave them stale, so
this task adds mutators that re-derive them.

**Files:**

- Modify: `src/Models/EventsPath/LiturgicalEventAbstract.php`
- Modify: `src/Handlers/EventsHandler.php` — `processAmbrosianDiocesanCalendarData()` (from line 800, the `foreach`)
- Test: `phpunit_tests/Models/EventsPath/LiturgicalEventAbstractMutatorTest.php`
- Modify: `phpunit_tests/Handlers/EventsHandlerRiteRoutingTest.php`

**Interfaces:**

- Consumes: the item classes from Task 3.
- Produces on `LiturgicalEventAbstract`:
  - `applyGrade(LitGrade $grade): void`
  - `applyCommon(LitCommons $common): void`
  - `applyName(string $name): void`

- [ ] **Step 1: Write the failing test**

Create `phpunit_tests/Models/EventsPath/LiturgicalEventAbstractMutatorTest.php`:

```php
<?php

namespace LiturgicalCalendar\Api\Tests\Models\EventsPath;

use LiturgicalCalendar\Api\Enum\LitGrade;
use LiturgicalCalendar\Api\Models\Calendar\LitCommons;
use LiturgicalCalendar\Api\Models\EventsPath\LiturgicalEventFixed;
use PHPUnit\Framework\TestCase;

final class LiturgicalEventAbstractMutatorTest extends TestCase
{
    private function makeEvent(): LiturgicalEventFixed
    {
        return LiturgicalEventFixed::fromArray([
            'event_key' => 'StsProtaseGervase',
            'name'      => 'Ss. Protaso e Gervaso, martiri',
            'month'     => 6,
            'day'       => 19,
            'grade'     => 4,
            'color'     => ['red'],
            'common'    => ['Martyrs:For Several Martyrs'],
        ]);
    }

    public function testApplyGradeAlsoRefreshesTheLocalizedGrade(): void
    {
        $event  = $this->makeEvent();
        $before = $event->jsonSerialize()['grade_lcl'];

        $event->applyGrade(LitGrade::MEMORIAL);

        $after = $event->jsonSerialize();
        self::assertSame(LitGrade::MEMORIAL->value, $after['grade']);
        self::assertNotSame($before, $after['grade_lcl'], 'grade_lcl must be re-derived, not left stale.');
    }

    public function testApplyCommonAlsoRefreshesTheLocalizedCommon(): void
    {
        $event  = $this->makeEvent();
        $before = $event->jsonSerialize()['common_lcl'];

        $newCommon = LitCommons::create(['Proper']);
        self::assertNotNull($newCommon);
        $event->applyCommon($newCommon);

        $after = $event->jsonSerialize();
        self::assertSame(['Proper'], $after['common']);
        self::assertNotSame($before, $after['common_lcl'], 'common_lcl must be re-derived, not left stale.');
    }

    public function testApplyNameSetsTheName(): void
    {
        $event = $this->makeEvent();
        $event->applyName('Ss. Protaso e Gervaso');

        self::assertSame('Ss. Protaso e Gervaso', $event->jsonSerialize()['name']);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit phpunit_tests/Models/EventsPath/LiturgicalEventAbstractMutatorTest.php`

Expected: FAIL with `Call to undefined method ...LiturgicalEventFixed::applyGrade()`.

- [ ] **Step 3: Add the mutators**

In `src/Models/EventsPath/LiturgicalEventAbstract.php`, add after `setGradeLocalization()`:

```php
    /**
     * Changes the grade of this catalog entry, re-deriving the localized grade strings.
     *
     * `$grade_lcl` and `$grade_abbr` are computed in the constructor and are both serialized, so
     * assigning `$grade` on its own would emit a numeric grade that disagrees with its own label.
     *
     * @param LitGrade $grade The new grade.
     */
    public function applyGrade(LitGrade $grade): void
    {
        $this->grade      = $grade;
        $this->grade_lcl  = $grade->i18n(self::$locale, false, false);
        $this->grade_abbr = $grade->i18n(self::$locale, false, true);
    }

    /**
     * Changes the Common of this catalog entry, re-deriving the localized Common string.
     *
     * @param LitCommons $common The new Common.
     */
    public function applyCommon(LitCommons $common): void
    {
        $this->common     = $common;
        $this->common_lcl = $common->fullTranslate(self::$locale);
    }

    /**
     * Changes the display name of this catalog entry. No derived field depends on the name.
     *
     * @param string $name The new name.
     */
    public function applyName(string $name): void
    {
        $this->name = $name;
    }
```

Confirm `LitCommons` and `LitGrade` are already imported in that file; add them if not.

- [ ] **Step 4: Run the mutator test to verify it passes**

Run: `vendor/bin/phpunit phpunit_tests/Models/EventsPath/LiturgicalEventAbstractMutatorTest.php`

Expected: PASS, 3 tests.

- [ ] **Step 5: Dispatch in `EventsHandler`**

In `src/Handlers/EventsHandler.php`, replace the `foreach` body of `processAmbrosianDiocesanCalendarData()` with:

```php
        foreach (self::$DiocesanData->litcal as $diocesanLitCalItem) {
            $key  = $diocesanLitCalItem->liturgical_event->event_key;
            $name = $DiocesanCalendarI18nData[$key];

            // A setProperty row modifies the comune catalog entry in place, under its plain key.
            // Prefixing it would emit a phantom duplicate that `/calendar` never produces.
            if ($diocesanLitCalItem->liturgical_event instanceof DiocesanLitCalItemSetPropertyGrade) {
                self::$liturgicalEvents->getEvent($key)?->applyGrade($diocesanLitCalItem->liturgical_event->grade);
                continue;
            }

            if ($diocesanLitCalItem->liturgical_event instanceof DiocesanLitCalItemSetPropertyCommon) {
                self::$liturgicalEvents->getEvent($key)?->applyCommon($diocesanLitCalItem->liturgical_event->common);
                continue;
            }

            if ($diocesanLitCalItem->liturgical_event instanceof DiocesanLitCalItemSetPropertyName) {
                // Unprefixed: `/calendar` emits the plain diocesan name for an overridden key.
                self::$liturgicalEvents->getEvent($key)?->applyName($name);
                continue;
            }

            $diocesanLitCalItem->setName('[ ' . self::$DiocesanData->metadata->diocese_name . ' ] ' . $name);
            $diocesanLitCalItem->liturgical_event->setKey($this->EventsParams->DiocesanCalendar . '_' . $key);
            if ($diocesanLitCalItem->liturgical_event instanceof DiocesanLitCalItemCreateNewFixed) {
                self::$liturgicalEvents->addEvent(LiturgicalEventFixed::fromObject($diocesanLitCalItem->liturgical_event));
            } elseif ($diocesanLitCalItem->liturgical_event instanceof DiocesanLitCalItemCreateNewMobile) {
                self::$liturgicalEvents->addEvent(LiturgicalEventMobile::fromObject($diocesanLitCalItem->liturgical_event));
            } else {
                throw new \ValueError('Unknown DiocesanLitCalItem->liturgical_event type: ' . get_class($diocesanLitCalItem->liturgical_event));
            }
        }
```

Add the three imports alongside the existing `DiocesanLitCalItemCreateNewFixed` import.

- [ ] **Step 6: Add the catalog-parity assertion**

In `phpunit_tests/Handlers/EventsHandlerRiteRoutingTest.php`, add the test below. It reuses the file's
existing `handle(array $pathParts, Rite $rite, string $uri): array` helper (which returns `['status' => int,
'body' => array]`) and its `self::byKey()` static, exactly as
`testAmbrosianDiocesanCatalogContainsDiocesanEventsAndComune()` does.

```php
    /**
     * A diocesan `setProperty` override modifies the comune catalog entry under its plain key.
     * Emitting a `{diocese}_{key}` duplicate alongside the untouched comune entry — which is what
     * the old re-declare mechanism did — would leave `/events` disagreeing with `/calendar`.
     */
    public function testAmbrosianDiocesanOverrideHasNoPrefixedDuplicate(): void
    {
        $result = $this->handle(['diocese', 'lugano_ch'], Rite::AMBROSIAN, '/events/ambrosian/diocese/lugano_ch');
        self::assertSame(200, $result['status']);

        $byKey = self::byKey($result['body']['litcal_events']);

        // The overridden comune keys stay, under their plain keys.
        self::assertArrayHasKey('StsProtaseGervase', $byKey);
        self::assertArrayHasKey('StFrancisOfAssisi', $byKey);

        // No phantom prefixed duplicates for the overridden keys.
        self::assertArrayNotHasKey('lugano_ch_StsProtaseGervase', $byKey);
        self::assertArrayNotHasKey('lugano_ch_StFrancisOfAssisi', $byKey);

        // A genuine createNew diocesan row is still prefixed, as before.
        self::assertArrayHasKey('lugano_ch_BeatoManfredoSettala', $byKey);

        // The override applied to the comune entry.
        self::assertSame(LitGrade::MEMORIAL->value, $byKey['StsProtaseGervase']['grade']);
        self::assertSame(['Proper'], $byKey['StsProtaseGervase']['common']);
    }
```

Add `use LiturgicalCalendar\Api\Enum\LitGrade;` to the file's imports if it is not already there.

**This test cannot pass until Task 7 lands the setProperty data** — until then Lugano still holds create-new
override rows and the prefixed keys are still emitted. Do not run it in this task; Task 7 Step 4 runs it.

- [ ] **Step 7: Run what can pass now**

Run: `vendor/bin/phpunit phpunit_tests/Models/EventsPath/ phpunit_tests/Handlers/CalendarGoldenMasterTest.php`

Expected: PASS, golden master 9/9.

Run: `composer analyse && composer lint`

Expected: no errors.

- [ ] **Step 8: Commit**

```bash
git add src/Models/EventsPath/LiturgicalEventAbstract.php src/Handlers/EventsHandler.php phpunit_tests/Models/EventsPath/LiturgicalEventAbstractMutatorTest.php phpunit_tests/Handlers/EventsHandlerRiteRoutingTest.php
git commit -m "feat(events): apply diocesan setProperty rows to the comune catalog entry"
```

---

### Task 7: Re-author the four overrides as nine setProperty rows

**Files:**

- Modify: `jsondata/sourcedata/rite/ambrosian/calendars/dioceses/CH/lugano_ch/Lugano.json`
- Modify: `jsondata/sourcedata/rite/ambrosian/calendars/dioceses/IT/bergam_it/Diocesi di Bergamo.json`
- Modify: `jsondata/sourcedata/rite/ambrosian/calendars/dioceses/IT/novara_it/Diocesi di Novara.json`

**Interfaces:**

- Consumes: the schema from Task 4 and the models from Task 3.
- Produces: on-disk data that Tasks 6 and 10's assertions depend on.

- [ ] **Step 1: Rewrite the Lugano overrides**

In `Lugano.json`, the `StsProtaseGervase` and `StFrancisOfAssisi` entries currently sit at `form_rownum` 1 and
2 as create-new rows. Replace those two objects with five, and renumber every row in the file so `form_rownum`
runs 0..6 in array order:

```json
{
  "liturgical_event": { "event_key": "StsProtaseGervase", "grade": 3 },
  "metadata": { "action": "setProperty", "property": "grade", "since_year": 2024, "form_rownum": 1 }
},
{
  "liturgical_event": { "event_key": "StsProtaseGervase", "common": ["Proper"] },
  "metadata": { "action": "setProperty", "property": "common", "since_year": 2024, "form_rownum": 2 }
},
{
  "liturgical_event": { "event_key": "StFrancisOfAssisi", "grade": 3 },
  "metadata": { "action": "setProperty", "property": "grade", "since_year": 2024, "form_rownum": 3 }
},
{
  "liturgical_event": { "event_key": "StFrancisOfAssisi", "common": ["Proper"] },
  "metadata": { "action": "setProperty", "property": "common", "since_year": 2024, "form_rownum": 4 }
},
{
  "liturgical_event": { "event_key": "StFrancisOfAssisi" },
  "metadata": { "action": "setProperty", "property": "name", "since_year": 2024, "form_rownum": 5 }
}
```

`BeatoManfredoSettala` keeps `form_rownum` 0; `SanLuigiGuanella` becomes 6.

- [ ] **Step 2: Rewrite the Bergamo and Novara overrides**

In both `Diocesi di Bergamo.json` and `Diocesi di Novara.json`, replace the single `StsProtaseGervase` create-new object with:

```json
{
  "liturgical_event": { "event_key": "StsProtaseGervase", "grade": 3 },
  "metadata": { "action": "setProperty", "property": "grade", "since_year": 2024, "form_rownum": 0 }
},
{
  "liturgical_event": { "event_key": "StsProtaseGervase", "common": ["Proper"] },
  "metadata": { "action": "setProperty", "property": "common", "since_year": 2024, "form_rownum": 1 }
}
```

Renumber the remaining rows in each file so `form_rownum` is sequential from 0 in array order.

- [ ] **Step 3: Verify the data parses and validates**

Run: `vendor/bin/phpunit phpunit_tests/Schemas/SchemaValidationTest.php phpunit_tests/Models/RegionalData/`

Expected: PASS.

- [ ] **Step 4: Verify end-to-end behaviour**

Run: `vendor/bin/phpunit phpunit_tests/Handlers/`

Expected: PASS, including the `testAmbrosianDiocesanOverrideHasNoPrefixedDuplicate` test added in Task 6 Step 6, which only becomes satisfiable now.

If `CalendarHandlerAmbrosianDiocesanTest::testLuganoOverrideDowngradesStFrancisGradeToMemorial` fails, check
that all three `StFrancisOfAssisi` rows applied — the grade row alone is not enough for the `common`
assertion.

- [ ] **Step 5: Confirm the i18n entries are still required**

`DiocesanData::setNames()` throws when a litcal item's `event_key` has no translation. All three
`StFrancisOfAssisi` rows and both `StsProtaseGervase` rows share one key each, and both keys already have i18n
entries in every affected diocese — so no i18n change is needed here.

Verify: `vendor/bin/phpunit phpunit_tests/Handlers/CalendarHandlerAmbrosianDiocesanTest.php`

Expected: PASS with no `translation not found for event key` error.

- [ ] **Step 6: Commit**

```bash
git add "jsondata/sourcedata/rite/ambrosian/calendars/dioceses/CH/lugano_ch/Lugano.json" "jsondata/sourcedata/rite/ambrosian/calendars/dioceses/IT/bergam_it/Diocesi di Bergamo.json" "jsondata/sourcedata/rite/ambrosian/calendars/dioceses/IT/novara_it/Diocesi di Novara.json"
git commit -m "data(ambrosian): re-author suffragan overrides as setProperty rows"
```

---

### Task 8: Normalize the diocesan Latin names to the genitive

Independent data change, its own commit. The comune sanctorale renders every Latin entry in the genitive; all 40 diocesan entries are nominative.

**Files:**

- Modify: `jsondata/sourcedata/rite/ambrosian/calendars/dioceses/CH/lugano_ch/i18n/la_VA.json`
- Modify: `jsondata/sourcedata/rite/ambrosian/calendars/dioceses/IT/bergam_it/i18n/la_VA.json`
- Modify: `jsondata/sourcedata/rite/ambrosian/calendars/dioceses/IT/milano_it/i18n/la_VA.json`
- Modify: `jsondata/sourcedata/rite/ambrosian/calendars/dioceses/IT/novara_it/i18n/la_VA.json`

**Interfaces:** none — data only.

- [ ] **Step 1: Present the conversion table for review**

Before editing, put this table in front of the repository owner and wait for approval. Italian surnames stay
indeclinable, as is standard in Latin liturgical texts. Duplicated keys across dioceses take the same
replacement.

| `event_key`                     | nominative (current)                             | genitive (new)                                    |
| ------------------------------- | ------------------------------------------------ | ------------------------------------------------- |
| `BeatoManfredoSettala`          | Beatus Manfredus Settala, presbyter              | Beati Manfredi Settala, presbyteri                |
| `StsProtaseGervase`             | Sancti Protasius et Gervasius, martyres          | Sanctorum Protasii et Gervasii, martyrum          |
| `StFrancisOfAssisi`             | Sanctus Franciscus Assisiensis                   | Sancti Francisci Assisiensis                      |
| `SanLuigiGuanella`              | Sanctus Aloisius Guanella, presbyter             | Sancti Aloisii Guanella, presbyteri               |
| `SanGiovanniXXIII`              | Sanctus Ioannes XXIII, papa                      | Sancti Ioannis XXIII, papae                       |
| `SanGaudenzioDiBrescia`         | Sanctus Gaudentius Brixiensis, episcopus         | Sancti Gaudentii Brixiensis, episcopi             |
| `SanPietroDaVerona`             | Sanctus Petrus de Verona, presbyter et martyr    | Sancti Petri de Verona, presbyteri et martyris    |
| `SanBenedettoMenni`             | Sanctus Benedictus Menni, presbyter              | Sancti Benedicti Menni, presbyteri                |
| `BeatoSerafinoMorazzone`        | Beatus Seraphinus Morazzone, presbyter           | Beati Seraphini Morazzone, presbyteri             |
| `SanLodovicoPavoni`             | Sanctus Ludovicus Pavoni, presbyter              | Sancti Ludovici Pavoni, presbyteri                |
| `BeatoLuigiBiraghi`             | Beatus Aloisius Biraghi, presbyter               | Beati Aloisii Biraghi, presbyteri                 |
| `SanGerardoDaMonza`             | Sanctus Gerardus Modoetiensis                    | Sancti Gerardi Modoetiensis                       |
| `BeatoMarioCiceri`              | Beatus Marius Ciceri, presbyter                  | Beati Marii Ciceri, presbyteri                    |
| `BeatoClementeVismara`          | Beatus Clemens Vismara, presbyter                | Beati Clementis Vismara, presbyteri               |
| `SanJosemariaEscrivaDeBalaguer` | Sanctus Iosemaria Escrivá de Balaguer, presbyter | Sancti Iosemariae Escrivá de Balaguer, presbyteri |
| `SanArialdo`                    | Sanctus Arialdus, diaconus et martyr             | Sancti Arialdi, diaconi et martyris               |
| `SanPantaleone`                 | Sanctus Pantaleon, martyr                        | Sancti Pantaleonis, martyris                      |
| `SantaTeresaDiCalcutta`         | Sancta Teresia a Calcutta, virgo                 | Sanctae Teresiae a Calcutta, virginis             |
| `BeataEugeniaPicco`             | Beata Eugenia Picco, virgo                       | Beatae Eugeniae Picco, virginis                   |
| `BeatoGiovanniMazzucconi`       | Beatus Ioannes Mazzucconi, presbyter et martyr   | Beati Ioannis Mazzucconi, presbyteri et martyris  |
| `BeatoLuigiMariaMonti`          | Beatus Aloisius Maria Monti, religiosus          | Beati Aloisii Mariae Monti, religiosi             |
| `SantaTecla`                    | Sancta Thecla, virgo et martyr                   | Sanctae Theclae, virginis et martyris             |
| `BeatoLuigiMonza`               | Beatus Aloisius Monza, presbyter                 | Beati Aloisii Monza, presbyteri                   |
| `BeatoLuigiTalamoni`            | Beatus Aloisius Talamoni, presbyter              | Beati Aloisii Talamoni, presbyteri                |
| `SanDanieleComboni`             | Sanctus Daniel Comboni, episcopus                | Sancti Danielis Comboni, episcopi                 |
| `BeatoCarloAcutis`              | Beatus Carolus Acutis                            | Beati Caroli Acutis                               |
| `BeatoCarloGnocchi`             | Beatus Carolus Gnocchi, presbyter                | Beati Caroli Gnocchi, presbyteri                  |
| `BeataArmidaBarelli`            | Beata Armida Barelli                             | Beatae Armidae Barelli                            |
| `BeatoSamueleMarzorati`         | Beatus Samuel Marzorati, presbyter et martyr     | Beati Samuelis Marzorati, presbyteri et martyris  |
| `BeataMariaAnnaSala`            | Beata Maria Anna Sala, virgo                     | Beatae Mariae Annae Sala, virginis                |
| `BeataEnrichettaAlfieri`        | Beata Henrica Alfieri, virgo                     | Beatae Henricae Alfieri, virginis                 |
| `SanSiro`                       | Sanctus Syrus, episcopus                         | Sancti Syri, episcopi                             |
| `BeatoArsenioDaTrigolo`         | Beatus Arsenius Trigulensis, presbyter           | Beati Arsenii Trigulensis, presbyteri             |

- [ ] **Step 2: Apply the approved replacements**

Edit the four `la_VA.json` files, replacing each value per the approved table. Preserve each file's existing key order and 4-space indentation. Do not touch the `it_IT.json` files.

- [ ] **Step 3: Verify the JSON is well-formed and complete**

Run, from the worktree root:

```bash
php -r '
$files = glob("jsondata/sourcedata/rite/ambrosian/calendars/dioceses/*/*/i18n/la_VA.json");
$bad = 0;
foreach ($files as $f) {
    $d = json_decode(file_get_contents($f), true, 512, JSON_THROW_ON_ERROR);
    foreach ($d as $k => $v) {
        if (preg_match("/^(Sanctus|Sancta|Beatus|Beata|Sancti [A-Z][a-z]+ et)/u", $v)) {
            echo "STILL NOMINATIVE: $f  $k = $v\n";
            $bad++;
        }
    }
}
echo $bad === 0 ? "all genitive\n" : "$bad remaining\n";
'
```

Expected: `all genitive`.

- [ ] **Step 4: Verify the calendar still builds in Latin**

Run: `vendor/bin/phpunit phpunit_tests/Handlers/ phpunit_tests/Schemas/`

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add jsondata/sourcedata/rite/ambrosian/calendars/dioceses/CH/lugano_ch/i18n/la_VA.json jsondata/sourcedata/rite/ambrosian/calendars/dioceses/IT/bergam_it/i18n/la_VA.json jsondata/sourcedata/rite/ambrosian/calendars/dioceses/IT/milano_it/i18n/la_VA.json jsondata/sourcedata/rite/ambrosian/calendars/dioceses/IT/novara_it/i18n/la_VA.json
git commit -m "data(ambrosian): render diocesan Latin names in the genitive"
```

---

### Task 9: Remove `removeLiturgicalEventWithoutSuppression()`

**Files:**

- Modify: `src/Models/Calendar/LiturgicalEventCollection.php:918`
- Delete: `phpunit_tests/Models/Calendar/LiturgicalEventCollectionRemoveWithoutSuppressionTest.php`

**Interfaces:** removes `LiturgicalEventCollection::removeLiturgicalEventWithoutSuppression()`. No task consumes it.

- [ ] **Step 1: Confirm there are no remaining callers**

Run: `grep -rn "removeLiturgicalEventWithoutSuppression" src/ phpunit_tests/`

Expected: hits only in `src/Models/Calendar/LiturgicalEventCollection.php` (the definition) and
`phpunit_tests/Models/Calendar/LiturgicalEventCollectionRemoveWithoutSuppressionTest.php`. If
`src/Handlers/CalendarHandler.php` still appears, Task 5 was not completed — stop and finish it.

- [ ] **Step 2: Delete the method and its test**

Remove the whole `removeLiturgicalEventWithoutSuppression()` method, including its docblock, from `LiturgicalEventCollection.php`.

```bash
git rm phpunit_tests/Models/Calendar/LiturgicalEventCollectionRemoveWithoutSuppressionTest.php
```

- [ ] **Step 3: Verify**

Run: `composer analyse && composer lint`

Expected: no errors, no "undefined method" reports.

Run: `vendor/bin/phpunit phpunit_tests/Models/ phpunit_tests/Handlers/`

Expected: PASS, golden master 9/9.

- [ ] **Step 4: Commit**

```bash
git add src/Models/Calendar/LiturgicalEventCollection.php phpunit_tests/Models/Calendar/LiturgicalEventCollectionRemoveWithoutSuppressionTest.php
git commit -m "refactor(calendar): drop removeLiturgicalEventWithoutSuppression()"
```

---

### Task 10: Full verification

**Files:** none modified unless a check fails.

- [ ] **Step 1: Full test suite**

Run: `composer test`

Expected: PASS. Do not pass `--exclude-group` on the CLI.

- [ ] **Step 2: Quick suite**

Run: `composer test:quick`

Expected: PASS.

- [ ] **Step 3: Golden master**

Run: `vendor/bin/phpunit phpunit_tests/Handlers/CalendarGoldenMasterTest.php`

Expected: PASS 9/9, byte-identical. Any failure means Roman behaviour changed — that is a blocker, not something to re-baseline.

- [ ] **Step 4: Static analysis and style**

Run: `composer analyse`

Expected: `[OK] No errors`.

Run: `composer lint`

Expected: no violations. If there are, `composer lint:fix` then re-run.

- [ ] **Step 5: Confirm the acceptance criteria**

Check each against the spec's list:

- Suffragan downgrades render at the diocesan grade and common; `StFrancisOfAssisi` at the diocesan name.
- `/events/ambrosian/diocese/lugano_ch` has no `lugano_ch_StsProtaseGervase` and no `lugano_ch_StFrancisOfAssisi`.
- No overridden comune event appears in `suppressed_events`.
- Golden master 9/9; suite, PHPStan, phpcs green.

- [ ] **Step 6: Update the spec's status**

Add a line at the top of `docs/superpowers/specs/2026-08-23-diocesan-set-property-design.md` recording that it is implemented, then run `composer lint:md`.

- [ ] **Step 7: Commit and push**

```bash
git add docs/superpowers/specs/2026-08-23-diocesan-set-property-design.md
git commit -m "docs(spec): mark the diocesan setProperty design implemented"
git push -u origin feature/740-diocesan-set-property
```

Open the PR against `development` (never `stable`), referencing issue #740 and calling out the three
deliberate output deviations from the issue text: readings placeholders for 33 diocesan events,
`StsProtaseGervase` taking the comune genitive name, and the 40-entry Latin normalization.
