<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Models;

use LiturgicalCalendar\Api\Models\MissalsMap;
use LiturgicalCalendar\Api\Models\PropriumDeSanctisMap;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MissalsMap::class)]
final class MissalsMapTest extends TestCase
{
    public function testInitWithMissalsAndArrayAccess(): void
    {
        $m1  = PropriumDeSanctisMap::fromObject([]);
        $m2  = PropriumDeSanctisMap::fromObject([]);
        $map = MissalsMap::initWithMissals(['a' => $m1, 'b' => $m2]);
        self::assertTrue($map->offsetExists('a'));
        self::assertSame($m1, $map->offsetGet('a'));
        self::assertSame($m2, $map['b']);
    }

    public function testIteratorYieldsAllEntries(): void
    {
        $m1     = PropriumDeSanctisMap::fromObject([]);
        $map    = MissalsMap::initWithMissals(['x' => $m1]);
        $values = iterator_to_array($map);
        self::assertSame(['x' => $m1], $values);
    }

    public function testOffsetSetAndUnset(): void
    {
        $m1  = PropriumDeSanctisMap::fromObject([]);
        $m2  = PropriumDeSanctisMap::fromObject([]);
        $map = MissalsMap::initWithMissals(['a' => $m1]);
        $map->offsetSet('b', $m2);
        self::assertTrue($map->offsetExists('b'));
        $map->offsetUnset('a');
        self::assertFalse($map->offsetExists('a'));
    }
}
