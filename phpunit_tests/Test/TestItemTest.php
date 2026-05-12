<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Test;

use LiturgicalCalendar\Api\Test\TestItem;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * In-process unit tests for TestItem.
 *
 * Companion to the WS-driven integration coverage in
 * phpunit_tests/WebSocket/ExecuteUnitTestTest.php — the integration suite
 * exercises only the happy paths each committed test JSON actually exposes
 * (no file in jsondata/tests/ uses `year_until`, `excludes`, or the
 * array-form `national_calendars` / `diocesan_calendars`, and none is
 * deliberately malformed), so the validation throws here cover the gaps.
 */
#[CoversClass(TestItem::class)]
final class TestItemTest extends TestCase
{
    /**
     * @return \stdClass A minimal valid TestItem-compatible object.
     */
    private function baseObject(): \stdClass
    {
        return (object) [
            'name'        => 'SampleTest',
            'event_key'   => 'sample_event',
            'description' => 'sample',
            'test_type'   => 'exactCorrespondenceSince',
            'assertions'  => [
                (object) [
                    'year'           => 2020,
                    'expected_value' => null,
                    'assert'         => 'eventNotExists',
                    'assertion'      => 'a',
                ],
            ],
        ];
    }

    public function testConstructsFromMinimalValidObject(): void
    {
        $item = new TestItem($this->baseObject());

        $this->assertSame('SampleTest', $item->name);
        $this->assertSame('sample_event', $item->event_key);
        $this->assertSame('exactCorrespondenceSince', $item->test_type);
        $this->assertNull($item->year_since);
        $this->assertNull($item->year_until);
        $this->assertNull($item->applies_to);
        $this->assertNull($item->excludes);
    }

    public function testStoresOptionalYearBounds(): void
    {
        $obj             = $this->baseObject();
        $obj->year_since = 2011;
        $obj->year_until = 2030;

        $item = new TestItem($obj);

        $this->assertSame(2011, $item->year_since);
        $this->assertSame(2030, $item->year_until);
    }

    public function testStoresAppliesToAndExcludesObjects(): void
    {
        $obj             = $this->baseObject();
        $obj->applies_to = (object) ['national_calendar' => 'US'];
        $obj->excludes   = (object) ['diocesan_calendar' => 'rotter_nl'];

        $item = new TestItem($obj);

        $this->assertIsObject($item->applies_to);
        $this->assertSame('US', $item->applies_to->national_calendar);
        $this->assertIsObject($item->excludes);
        $this->assertSame('rotter_nl', $item->excludes->diocesan_calendar);
    }

    public function testAcceptsArrayFormNationalCalendars(): void
    {
        $obj             = $this->baseObject();
        $obj->applies_to = (object) ['national_calendars' => ['US', 'IT']];

        $item = new TestItem($obj);

        $this->assertIsObject($item->applies_to);
        $this->assertSame(['US', 'IT'], $item->applies_to->national_calendars);
    }

    public function testAcceptsArrayFormDiocesanCalendars(): void
    {
        $obj             = $this->baseObject();
        $obj->applies_to = (object) ['diocesan_calendars' => ['rotter_nl', 'romamo_it']];

        $item = new TestItem($obj);

        $this->assertIsObject($item->applies_to);
        $this->assertSame(['rotter_nl', 'romamo_it'], $item->applies_to->diocesan_calendars);
    }

    public function testThrowsOnMissingRequiredProperty(): void
    {
        $obj = $this->baseObject();
        unset($obj->event_key);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing required property: event_key');
        new TestItem($obj);
    }

    public function testThrowsOnNonStringRequiredProperty(): void
    {
        $obj            = $this->baseObject();
        $obj->test_type = 123;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Property `test_type` must be a string');
        new TestItem($obj);
    }

    public function testThrowsOnNonIntYearSince(): void
    {
        $obj             = $this->baseObject();
        $obj->year_since = '2011';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('`year_since` must be an integer');
        new TestItem($obj);
    }

    public function testThrowsOnNonIntYearUntil(): void
    {
        $obj             = $this->baseObject();
        $obj->year_until = '2030';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('`year_until` must be an integer');
        new TestItem($obj);
    }

    public function testThrowsOnNonObjectAppliesTo(): void
    {
        $obj             = $this->baseObject();
        $obj->applies_to = 'US';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('`applies_to` must be an object');
        new TestItem($obj);
    }

    public function testThrowsOnNonObjectExcludes(): void
    {
        $obj           = $this->baseObject();
        $obj->excludes = 'US';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('`excludes` must be an object');
        new TestItem($obj);
    }

    public function testThrowsWhenAppliesToHasNoRecognisedKey(): void
    {
        $obj             = $this->baseObject();
        $obj->applies_to = (object) ['unrelated_key' => 'whatever'];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('`applies_to` must have at least one of the properties');
        new TestItem($obj);
    }

    public function testThrowsWhenNationalCalendarIsNotString(): void
    {
        $obj             = $this->baseObject();
        $obj->applies_to = (object) ['national_calendar' => 123];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('`national_calendar` must have a string value');
        new TestItem($obj);
    }

    public function testThrowsWhenDiocesanCalendarIsNotString(): void
    {
        $obj             = $this->baseObject();
        $obj->applies_to = (object) ['diocesan_calendar' => 123];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('`diocesan_calendar` must have a string value');
        new TestItem($obj);
    }

    public function testThrowsWhenNationalCalendarsIsNotArray(): void
    {
        $obj             = $this->baseObject();
        $obj->applies_to = (object) ['national_calendars' => 'US'];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('`national_calendars` must have an array value');
        new TestItem($obj);
    }

    public function testThrowsWhenNationalCalendarsContainsNonString(): void
    {
        $obj             = $this->baseObject();
        $obj->applies_to = (object) ['national_calendars' => ['US', 123]];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('`national_calendars` must have an array of strings');
        new TestItem($obj);
    }

    public function testThrowsWhenDiocesanCalendarsIsNotArray(): void
    {
        $obj             = $this->baseObject();
        $obj->applies_to = (object) ['diocesan_calendars' => 'rotter_nl'];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('`diocesan_calendars` must have an array value');
        new TestItem($obj);
    }

    public function testThrowsWhenDiocesanCalendarsContainsNonString(): void
    {
        $obj             = $this->baseObject();
        $obj->applies_to = (object) ['diocesan_calendars' => ['rotter_nl', 0]];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('`diocesan_calendars` must have an array of strings');
        new TestItem($obj);
    }
}
