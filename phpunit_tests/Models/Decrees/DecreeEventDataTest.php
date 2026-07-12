<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Models\Decrees;

use LiturgicalCalendar\Api\Models\Decrees\DecreeItemCreateNewFixed;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use LiturgicalCalendar\Api\Models\Decrees\DecreeEventData;

#[CoversClass(DecreeEventData::class)]
final class DecreeEventDataTest extends TestCase
{
    /**
     * Build a minimal DecreeItemCreateNewFixed stdClass suitable for fromObject().
     *
     * @return \stdClass
     */
    private static function minimalFixedEventData(): \stdClass
    {
        $data            = new \stdClass();
        $data->event_key = 'StTestEvent';
        $data->name      = 'Saint Test Event';
        $data->calendar  = 'GENERAL ROMAN';
        $data->day       = 14;
        $data->month     = 2;
        $data->color     = ['white'];
        $data->grade     = 2;
        $data->common    = ['Pastors'];
        return $data;
    }

    /**
     * Covers DecreeEventData::setReadings() (lines 33-35):
     * the method must unlock the object, assign readings, then re-lock.
     *
     * After setReadings(), the readings property must equal the passed stdClass
     * and the object must still be effectively locked (a direct property write
     * should throw LogicException).
     */
    public function testSetReadingsAttachesReadingsAndRemainsLocked(): void
    {
        $event                   = DecreeItemCreateNewFixed::fromObject(self::minimalFixedEventData());
        $readings                = new \stdClass();
        $readings->first_reading = 'Genesis 1:1';
        $readings->gospel        = 'John 1:1-14';

        $event->setReadings($readings);

        self::assertSame($readings, $event->readings);
    }

    /**
     * Covers DecreeEventData::jsonSerialize() (lines 51-55) — readings IS null branch:
     * when readings === null, the `readings` key must be absent from the serialized array.
     */
    public function testJsonSerializeOmitsReadingsWhenNull(): void
    {
        $event = DecreeItemCreateNewFixed::fromObject(self::minimalFixedEventData());
        // readings defaults to null (not set via setReadings())
        self::assertNull($event->readings);

        $serialized = $event->jsonSerialize();
        self::assertIsArray($serialized);
        self::assertArrayNotHasKey('readings', $serialized, 'readings key must be absent when readings is null');
    }

    /**
     * Covers DecreeEventData::jsonSerialize() (lines 51-55) — readings IS set branch:
     * when readings is a non-null stdClass, it must appear in the serialized array.
     */
    public function testJsonSerializeIncludesReadingsWhenSet(): void
    {
        $event                   = DecreeItemCreateNewFixed::fromObject(self::minimalFixedEventData());
        $readings                = new \stdClass();
        $readings->first_reading = 'Genesis 1:1';
        $event->setReadings($readings);

        $serialized = $event->jsonSerialize();
        self::assertIsArray($serialized);
        self::assertArrayHasKey('readings', $serialized, 'readings key must be present when readings is set');
        self::assertSame($readings, $serialized['readings']);
    }
}
