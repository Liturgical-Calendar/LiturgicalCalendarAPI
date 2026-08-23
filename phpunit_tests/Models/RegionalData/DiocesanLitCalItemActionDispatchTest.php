<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Models\RegionalData;

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
            'metadata'         => ['since_year' => 2024, 'form_rownum' => 0],
        ]);

        self::assertInstanceOf(DiocesanLitCalItemCreateNewFixed::class, $item->liturgical_event);
    }

    public function testSetPropertyGradeBuildsAGradeItem(): void
    {
        $item = DiocesanLitCalItem::fromArray([
            'liturgical_event' => ['event_key' => 'StsProtaseGervase', 'grade' => 3],
            'metadata'         => [
                'action'      => 'setProperty',
                'property'    => 'grade',
                'since_year'  => 2024,
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
                'action'      => 'setProperty',
                'property'    => 'common',
                'since_year'  => 2024,
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
                'action'      => 'setProperty',
                'property'    => 'name',
                'since_year'  => 2024,
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
                'action'      => 'setProperty',
                'property'    => 'color',
                'since_year'  => 2024,
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
