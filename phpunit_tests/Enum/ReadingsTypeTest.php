<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Enum;

use LiturgicalCalendar\Api\Enum\ReadingsType;
use LiturgicalCalendar\Api\Models\Lectionary\ReadingsMap;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReadingsType::class)]
final class ReadingsTypeTest extends TestCase
{
    public function testForEventKeySpecialEvents(): void
    {
        self::assertSame(ReadingsType::CHRISTMAS, ReadingsType::forEventKey('Christmas'));
        self::assertSame(ReadingsType::FESTIVE_WITH_VIGIL, ReadingsType::forEventKey('Pentecost'));
        self::assertSame(ReadingsType::EASTER_VIGIL, ReadingsType::forEventKey('EasterVigil'));
        self::assertSame(ReadingsType::PALM_SUNDAY, ReadingsType::forEventKey('PalmSun'));
        self::assertSame(ReadingsType::WITH_EVENING, ReadingsType::forEventKey('Easter'));
        self::assertSame(ReadingsType::MULTIPLE_SCHEMAS, ReadingsType::forEventKey('AllSouls'));
    }

    public function testForEventKeyFerial(): void
    {
        self::assertSame(ReadingsType::FERIAL, ReadingsType::forEventKey('AdventWeekday2Mon'));
        self::assertSame(ReadingsType::FERIAL, ReadingsType::forEventKey('AdventWeekdayDec18'));
        self::assertSame(ReadingsType::FERIAL, ReadingsType::forEventKey('ChristmasWeekdayDec29'));
        self::assertSame(ReadingsType::FERIAL, ReadingsType::forEventKey('DayAfterEpiphany'));
        self::assertSame(ReadingsType::FERIAL, ReadingsType::forEventKey('LentWeekday3Mon'));
        self::assertSame(ReadingsType::FERIAL, ReadingsType::forEventKey('FridayAfterAshWednesday'));
        self::assertSame(ReadingsType::FERIAL, ReadingsType::forEventKey('EasterWeekday3Tue'));
        self::assertSame(ReadingsType::FERIAL, ReadingsType::forEventKey('OrdWeekday20Fri'));
    }

    public function testForEventKeyDefaultsToFestive(): void
    {
        self::assertSame(ReadingsType::FESTIVE, ReadingsType::forEventKey('OrdSunday5'));
        self::assertSame(ReadingsType::FESTIVE, ReadingsType::forEventKey('Trinity'));
    }

    public function testExpectedKeys(): void
    {
        self::assertSame(ReadingsMap::FESTIVE_KEYS, ReadingsType::FESTIVE->expectedKeys());
        self::assertSame(ReadingsMap::FERIAL_KEYS, ReadingsType::FERIAL->expectedKeys());
        self::assertSame(ReadingsMap::READINGS_CHRISTMAS_KEYS, ReadingsType::CHRISTMAS->expectedKeys());
        self::assertSame(ReadingsMap::READINGS_WITH_VIGIL_KEYS, ReadingsType::FESTIVE_WITH_VIGIL->expectedKeys());
        self::assertSame(ReadingsMap::EASTER_VIGIL_KEYS, ReadingsType::EASTER_VIGIL->expectedKeys());
        self::assertSame(ReadingsMap::PALM_SUNDAY_KEYS, ReadingsType::PALM_SUNDAY->expectedKeys());
        self::assertSame(ReadingsMap::READINGS_WITH_EVENING_MASS_KEYS, ReadingsType::WITH_EVENING->expectedKeys());
        self::assertSame(ReadingsMap::READINGS_MULTIPLE_SCHEMAS_KEYS, ReadingsType::MULTIPLE_SCHEMAS->expectedKeys());
        self::assertSame(ReadingsMap::READINGS_SEASONAL_KEYS, ReadingsType::SEASONAL->expectedKeys());
    }

    public function testHasNestedStructure(): void
    {
        self::assertTrue(ReadingsType::CHRISTMAS->hasNestedStructure());
        self::assertTrue(ReadingsType::FESTIVE_WITH_VIGIL->hasNestedStructure());
        self::assertTrue(ReadingsType::WITH_EVENING->hasNestedStructure());
        self::assertTrue(ReadingsType::MULTIPLE_SCHEMAS->hasNestedStructure());
        self::assertTrue(ReadingsType::SEASONAL->hasNestedStructure());
        self::assertFalse(ReadingsType::FESTIVE->hasNestedStructure());
        self::assertFalse(ReadingsType::FERIAL->hasNestedStructure());
        self::assertFalse(ReadingsType::EASTER_VIGIL->hasNestedStructure());
        self::assertFalse(ReadingsType::PALM_SUNDAY->hasNestedStructure());
    }

    public function testNestedKeys(): void
    {
        self::assertNull(ReadingsType::FESTIVE->nestedKeys());
        self::assertSame(ReadingsMap::FESTIVE_KEYS, ReadingsType::CHRISTMAS->nestedKeys());
        // SEASONAL nests ferial readings.
        self::assertSame(ReadingsMap::FERIAL_KEYS, ReadingsType::SEASONAL->nestedKeys());
    }

    public function testValidateStructureFestiveHappyPath(): void
    {
        $readings = (object) array_fill_keys(ReadingsMap::FESTIVE_KEYS, 'Some reading text');
        self::assertTrue(ReadingsType::FESTIVE->validateStructure($readings));
    }

    public function testValidateStructureMissingKey(): void
    {
        $readings = (object) array_fill_keys(ReadingsMap::FERIAL_KEYS, 'text');
        // Festive expects more keys than ferial.
        self::assertFalse(ReadingsType::FESTIVE->validateStructure($readings));
        $err = ReadingsType::FESTIVE->getValidationError($readings);
        self::assertStringContainsString('missing keys', $err);
    }

    public function testValidateStructureExtraKey(): void
    {
        $readings        = (object) array_fill_keys(ReadingsMap::FESTIVE_KEYS, 'text');
        $readings->extra = 'oops';
        self::assertFalse(ReadingsType::FESTIVE->validateStructure($readings));
        $err = ReadingsType::FESTIVE->getValidationError($readings);
        self::assertStringContainsString('unexpected keys', $err);
    }

    public function testValidateStructureNonStringValueFlat(): void
    {
        $readings = (object) array_fill_keys(ReadingsMap::FESTIVE_KEYS, 'text');
        // Replace one with an int.
        $firstKey            = ReadingsMap::FESTIVE_KEYS[0];
        $readings->$firstKey = 123;
        self::assertFalse(ReadingsType::FESTIVE->validateStructure($readings));
    }

    public function testValidateStructureNestedHappyPath(): void
    {
        $inner    = array_fill_keys(ReadingsMap::FESTIVE_KEYS, 'text');
        $readings = new \stdClass();
        foreach (ReadingsMap::READINGS_CHRISTMAS_KEYS as $outer) {
            $readings->$outer = (object) $inner;
        }
        self::assertTrue(ReadingsType::CHRISTMAS->validateStructure($readings));
    }

    public function testValidateStructureNestedNonObject(): void
    {
        $readings = new \stdClass();
        foreach (ReadingsMap::READINGS_CHRISTMAS_KEYS as $outer) {
            // Should be an object, give it a string instead.
            $readings->$outer = 'not an object';
        }
        self::assertFalse(ReadingsType::CHRISTMAS->validateStructure($readings));
        $err = ReadingsType::CHRISTMAS->getValidationError($readings);
        self::assertStringContainsString('must be an object', $err);
    }

    public function testValidateStructureNestedMissingInnerKey(): void
    {
        $inner    = array_fill_keys(ReadingsMap::FERIAL_KEYS, 'text'); // ferial < festive
        $readings = new \stdClass();
        foreach (ReadingsMap::READINGS_CHRISTMAS_KEYS as $outer) {
            $readings->$outer = (object) $inner;
        }
        self::assertFalse(ReadingsType::CHRISTMAS->validateStructure($readings));
    }

    public function testSpecialEventKeysContainsKnownEvents(): void
    {
        $keys = ReadingsType::specialEventKeys();
        self::assertContains('Christmas', $keys);
        self::assertContains('Pentecost', $keys);
        self::assertContains('EasterVigil', $keys);
        self::assertContains('PalmSun', $keys);
        self::assertContains('Easter', $keys);
        self::assertContains('AllSouls', $keys);
    }
}
