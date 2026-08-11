<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Models;

use LiturgicalCalendar\Api\Enum\LitColor;
use LiturgicalCalendar\Api\Enum\LitEventType;
use LiturgicalCalendar\Api\Enum\LitGrade;
use LiturgicalCalendar\Api\Models\PropriumDeSanctisEvent;
use LiturgicalCalendar\Api\Models\PropriumDeTemporeEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Covers the associative-array ingestion path of the two Proprium events.
 *
 * Production loads its source data as stdClass, so `fromObject()` is well exercised while
 * `fromArray()` had no coverage at all — including the `is_bvm` / `since_year` / `until_year`
 * fields these classes gained for the Pentecost-anchored celebrations, and the `type` value that
 * `LitEventType::from()` consumes. The PHPDoc array shapes describe exactly this path, so without
 * these tests the shapes were only ever correct by inspection.
 */
#[CoversClass(PropriumDeTemporeEvent::class)]
#[CoversClass(PropriumDeSanctisEvent::class)]
final class PropriumEventFromArrayTest extends TestCase
{
    public function testTemporeEventFromArrayReadsEveryOptionalField(): void
    {
        $event = PropriumDeTemporeEvent::fromArray([
            'event_key'      => 'PentecostAnchoredTestEvent',
            'grade'          => LitGrade::FEAST->value,
            'type'           => 'mobile',
            'color'          => ['white'],
            'is_dominical'   => false,
            'is_aliturgical' => true,
            'is_bvm'         => true,
            'since_year'     => 2024,
            'until_year'     => 2056
        ]);

        self::assertSame('PentecostAnchoredTestEvent', $event->event_key);
        self::assertSame(LitGrade::FEAST, $event->grade);
        self::assertSame(LitEventType::MOBILE, $event->type);
        self::assertSame([LitColor::WHITE], $event->color);
        self::assertFalse($event->is_dominical);
        self::assertTrue($event->is_aliturgical);
        self::assertTrue($event->is_bvm);
        self::assertSame(2024, $event->since_year);
        self::assertSame(2056, $event->until_year);
    }

    public function testTemporeEventFromArrayLeavesOmittedOptionalFieldsNull(): void
    {
        $event = PropriumDeTemporeEvent::fromArray([
            'event_key' => 'MinimalTemporeTestEvent',
            'grade'     => LitGrade::WEEKDAY->value,
            'type'      => 'fixed',
            'color'     => ['green']
        ]);

        self::assertSame(LitEventType::FIXED, $event->type);
        self::assertNull($event->is_aliturgical);
        self::assertNull($event->is_bvm);
        self::assertNull($event->since_year);
        self::assertNull($event->until_year);
    }

    public function testTemporeEventFromObjectReadsTheAliturgicalFlag(): void
    {
        // The object path is the one production uses, but no fixture set `is_aliturgical`, so the
        // branch that reads it was never taken.
        $event = PropriumDeTemporeEvent::fromObject((object) [
            'event_key'      => 'AliturgicalTemporeTestEvent',
            'grade'          => LitGrade::WEEKDAY->value,
            'type'           => 'mobile',
            'color'          => ['white'],
            'is_aliturgical' => true
        ]);

        self::assertTrue($event->is_aliturgical);
    }

    public function testSanctisEventFromArrayReadsTheBooleanFlags(): void
    {
        $event = PropriumDeSanctisEvent::fromArray([
            'event_key'    => 'SanctisFromArrayTestEvent',
            'day'          => 31,
            'month'        => 5,
            'color'        => ['white'],
            'common'       => [],
            'grade'        => LitGrade::FEAST->value,
            'is_dominical' => true,
            'is_bvm'       => true
        ]);

        self::assertSame('SanctisFromArrayTestEvent', $event->event_key);
        self::assertSame(31, $event->day);
        self::assertSame(5, $event->month);
        self::assertSame(LitGrade::FEAST, $event->grade);
        self::assertTrue($event->is_dominical);
        self::assertTrue($event->is_bvm);
    }

    public function testSanctisEventFromArrayDefaultsTypeToFixedWhenAbsent(): void
    {
        // `type` is absent from every sanctorale source file, which is why the array shape spells
        // it `type:?string` and the ingestion falls back to FIXED.
        $event = PropriumDeSanctisEvent::fromArray([
            'event_key' => 'SanctisNoTypeTestEvent',
            'day'       => 1,
            'month'     => 1,
            'color'     => ['white'],
            'common'    => [],
            'grade'     => LitGrade::MEMORIAL->value
        ]);

        self::assertSame(LitEventType::FIXED, $event->type);
        self::assertNull($event->is_bvm);
    }

    public function testSanctisEventFromArrayAcceptsAnExplicitStringType(): void
    {
        // Guards the `type:?string` shape: the value reaches the string-backed LitEventType::from().
        $event = PropriumDeSanctisEvent::fromArray([
            'event_key' => 'SanctisMobileTypeTestEvent',
            'day'       => 2,
            'month'     => 2,
            'color'     => ['white'],
            'common'    => [],
            'grade'     => LitGrade::MEMORIAL->value,
            'type'      => 'mobile'
        ]);

        self::assertSame(LitEventType::MOBILE, $event->type);
    }
}
