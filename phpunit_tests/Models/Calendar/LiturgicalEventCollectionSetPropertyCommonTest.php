<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Models\Calendar;

use LiturgicalCalendar\Api\DateTime;
use LiturgicalCalendar\Api\Enum\LitGrade;
use LiturgicalCalendar\Api\Models\Calendar\LitCommons;
use LiturgicalCalendar\Api\Models\Calendar\LiturgicalEvent;
use LiturgicalCalendar\Api\Models\Calendar\LiturgicalEventCollection;
use LiturgicalCalendar\Api\Params\CalendarParams;
use LiturgicalCalendar\Api\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * `LiturgicalEvent::$common` is typed `LitCommons|array`. Before this change `setProperty()`
 * declared `$newValue` as `string|int|bool|LitGrade`, so a `LitCommons` could not even reach the
 * method body. These tests pin the widened signature.
 *
 * Building a `LiturgicalEventCollection` requires a real `CalendarParams` instance, which in
 * turn needs `Router::$apiFilePath` pinned to read local source data. This is a pure-logic
 * `Models/Calendar` test, so it pins the Router paths itself via `Router::getApiPaths()` in
 * `setUpBeforeClass()` (mirroring `SchemaValidationTest`) rather than pulling in the `Handlers`
 * layer's `AbstractHandlerTestCase`, which would additionally require JWT/database
 * preconditions this test never needs.
 */
#[CoversClass(LiturgicalEventCollection::class)]
final class LiturgicalEventCollectionSetPropertyCommonTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        Router::getApiPaths();
    }

    private function makeCollection(): LiturgicalEventCollection
    {
        $params = new CalendarParams();
        $params->setParams(['year' => 2025, 'locale' => 'en']);

        return new LiturgicalEventCollection($params);
    }

    public function testSetPropertyAcceptsLitCommonsAndReplacesTheCommon(): void
    {
        $cal = $this->makeCollection();

        $event         = new LiturgicalEvent('Test Event', new DateTime('2025-06-19'));
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

        $event        = new LiturgicalEvent('Test Event', new DateTime('2025-06-19'));
        $event->grade = LitGrade::FEAST;
        $cal->addLiturgicalEvent('TestEvent', $event);

        self::assertTrue($cal->setProperty('TestEvent', 'grade', LitGrade::MEMORIAL));
        self::assertSame(LitGrade::MEMORIAL, $cal->getLiturgicalEvent('TestEvent')->grade);
    }
}
