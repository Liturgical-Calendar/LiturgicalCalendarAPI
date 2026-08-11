<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Models;

use LiturgicalCalendar\Api\Enum\LitGrade;
use LiturgicalCalendar\Api\Models\PropriumDeSanctisMap;
use LiturgicalCalendar\Api\Models\PropriumDeTemporeMap;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the immutability contract of the two Proprium maps.
 *
 * Both implement ArrayAccess purely so the maps read naturally, and both reject writes — but
 * nothing exercised the guards, so the contract rested entirely on the docblocks. MissalsMap
 * carries the same ArrayAccess surface and is deliberately mutable, which is exactly why the
 * difference is worth asserting rather than assumed.
 *
 * Building the maps through fromArray() also exercises their associative-array ingestion, which
 * production never uses (it loads stdClass) and no test previously reached.
 */
#[CoversClass(PropriumDeTemporeMap::class)]
#[CoversClass(PropriumDeSanctisMap::class)]
final class PropriumMapImmutabilityTest extends TestCase
{
    private static function temporeMap(): PropriumDeTemporeMap
    {
        return PropriumDeTemporeMap::fromArray([
            [
                'event_key' => 'ImmutableTemporeTestEvent',
                'grade'     => LitGrade::FEAST->value,
                'type'      => 'mobile',
                'color'     => ['white']
            ]
        ]);
    }

    private static function sanctisMap(): PropriumDeSanctisMap
    {
        return PropriumDeSanctisMap::fromArray([
            [
                'event_key' => 'ImmutableSanctisTestEvent',
                'day'       => 15,
                'month'     => 8,
                'color'     => ['white'],
                'common'    => [],
                'grade'     => LitGrade::SOLEMNITY->value
            ]
        ]);
    }

    public function testTheTemporeMapIsBuiltFromAnArrayAndIsReadable(): void
    {
        $map = self::temporeMap();

        self::assertTrue($map->offsetExists('ImmutableTemporeTestEvent'));
        self::assertSame('ImmutableTemporeTestEvent', $map['ImmutableTemporeTestEvent']->event_key);

        // Reading the map by iteration is the other half of its ArrayAccess/IteratorAggregate
        // surface, and was likewise unexercised.
        $iterated = iterator_to_array($map);
        self::assertSame(['ImmutableTemporeTestEvent'], array_keys($iterated));
        self::assertSame('ImmutableTemporeTestEvent', $iterated['ImmutableTemporeTestEvent']->event_key);
    }

    public function testTheTemporeMapRejectsWrites(): void
    {
        $map = self::temporeMap();

        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('PropriumDeTemporeMap is immutable');

        $map['AnyKey'] = $map['ImmutableTemporeTestEvent'];
    }

    public function testTheTemporeMapRejectsUnset(): void
    {
        $map = self::temporeMap();

        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('PropriumDeTemporeMap is immutable');

        unset($map['ImmutableTemporeTestEvent']);
    }

    public function testTheSanctisMapIsBuiltFromAnArrayAndIsReadable(): void
    {
        $map = self::sanctisMap();

        self::assertTrue($map->offsetExists('ImmutableSanctisTestEvent'));
        self::assertSame(8, $map['ImmutableSanctisTestEvent']->month);
    }

    public function testTheSanctisMapRejectsWrites(): void
    {
        $map = self::sanctisMap();

        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('PropriumDeSanctisMap is immutable');

        $map['AnyKey'] = $map['ImmutableSanctisTestEvent'];
    }

    public function testTheSanctisMapRejectsUnset(): void
    {
        $map = self::sanctisMap();

        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('PropriumDeSanctisMap is immutable');

        unset($map['ImmutableSanctisTestEvent']);
    }
}
