<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Models;

use LiturgicalCalendar\Api\Models\AbstractJsonRepresentation;
use LiturgicalCalendar\Api\Models\AbstractJsonSrcData;
use LiturgicalCalendar\Api\Models\AbstractJsonSrcDataArray;
use LiturgicalCalendar\Api\Models\MissalsPath\MissalYearLimits;
use LiturgicalCalendar\Api\Models\PropriumDeTemporeMap;
use LiturgicalCalendar\Api\Models\RelativeLiturgicalDate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the shared contract of AbstractJsonRepresentation,
 * AbstractJsonSrcData, and AbstractJsonSrcDataArray via concrete subclasses
 * that already exist in the codebase (RelativeLiturgicalDate for the
 * SrcData variant, MissalYearLimits for the Representation variant, and
 * PropriumDeTemporeMap for the SrcDataArray variant).
 */
#[CoversClass(AbstractJsonRepresentation::class)]
#[CoversClass(AbstractJsonSrcData::class)]
#[CoversClass(AbstractJsonSrcDataArray::class)]
final class AbstractJsonClassesTest extends TestCase
{
    public function testFromArrayRejectsStdClassFirstElementForSrcData(): void
    {
        // RelativeLiturgicalDate uses AbstractJsonSrcData. Pass an array whose
        // first element is a stdClass — that should error directing callers to fromObject.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Please use fromObject instead.');
        RelativeLiturgicalDate::fromArray([new \stdClass(), 'irrelevant']);
    }

    public function testFromArrayRejectsStdClassFirstElementForRepresentation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MissalYearLimits::fromArray([new \stdClass()]);
    }

    public function testFromArrayRejectsStdClassFirstElementForSrcDataArray(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PropriumDeTemporeMap::fromArray([new \stdClass()]);
    }

    public function testLockingPreventsFurtherMutationForSrcData(): void
    {
        $rel = RelativeLiturgicalDate::fromArray([
            'day_of_the_week' => 'Monday',
            'relative_time'   => 'after',
            'event_key'       => 'Pentecost',
        ]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("Cannot modify locked object property 'extra'");
        $rel->extra = 'nope';
    }

    public function testLockingPreventsFurtherMutationForRepresentation(): void
    {
        $limits = MissalYearLimits::fromArray(['since_year' => 1970]);

        $this->expectException(\LogicException::class);
        $limits->extra = 'nope';
    }

    public function testLockingPreventsFurtherMutationForSrcDataArray(): void
    {
        // PropriumDeTemporeMap is a concrete AbstractJsonSrcDataArray subclass.
        $map = PropriumDeTemporeMap::fromArray([
            ['event_key' => 'Easter', 'grade' => 7, 'type' => 'mobile', 'color' => ['white']],
        ]);

        $this->expectException(\LogicException::class);
        $map->extra = 'nope';
    }

    public function testRepresentationFromObjectHappyPath(): void
    {
        $limits = MissalYearLimits::fromObject((object) ['since_year' => 1970]);
        self::assertSame(1970, $limits->since_year);
        self::assertNull($limits->until_year);
    }

    public function testSrcDataArrayFromObjectRoundTrip(): void
    {
        $event = (object) ['event_key' => 'Christmas', 'grade' => 7, 'type' => 'fixed', 'color' => ['white']];
        $map   = PropriumDeTemporeMap::fromObject([$event]);
        self::assertInstanceOf(PropriumDeTemporeMap::class, $map);
    }
}
