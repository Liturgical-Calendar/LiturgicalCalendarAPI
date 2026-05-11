<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Models;

use LiturgicalCalendar\Api\Enum\CalEventAction;
use LiturgicalCalendar\Api\Models\LitCalItem;
use LiturgicalCalendar\Api\Models\RegionalData\NationalData\LitCalItemCreateNewFixed;
use LiturgicalCalendar\Api\Models\RegionalData\NationalData\LitCalItemCreateNewMetadata;
use LiturgicalCalendar\Api\Models\RegionalData\NationalData\LitCalItemMoveEvent;
use LiturgicalCalendar\Api\Models\RegionalData\NationalData\LitCalItemMoveEventMetadata;
use LiturgicalCalendar\Api\Models\RegionalData\NationalData\LitCalItemSetPropertyName;
use LiturgicalCalendar\Api\Models\RegionalData\NationalData\LitCalItemSetPropertyNameMetadata;
use LiturgicalCalendar\Api\Models\RegionalData\NationalData\LitCalItemSetPropertyGrade;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the polymorphic dispatch in LitCalItem (it picks a concrete
 * LiturgicalEventData / LiturgicalEventMetadata pair based on metadata.action
 * and, for setProperty, metadata.property).
 */
#[CoversClass(LitCalItem::class)]
final class LitCalItemTest extends TestCase
{
    public function testFromObjectMoveEvent(): void
    {
        $item = LitCalItem::fromObject((object) [
            'liturgical_event' => (object) [
                'event_key' => 'CorpusChristi',
                'day'       => 7,
                'month'     => 6,
            ],
            'metadata'         => (object) [
                'action'     => CalEventAction::MoveEvent->value,
                'since_year' => 2020,
                'until_year' => null,
                'missal'     => 'IT_2020',
                'reason'     => 'CEI moved the feast',
            ],
        ]);
        self::assertInstanceOf(LitCalItemMoveEvent::class, $item->liturgical_event);
        self::assertInstanceOf(LitCalItemMoveEventMetadata::class, $item->metadata);
        self::assertSame('CorpusChristi', $item->getEventKey());
    }

    public function testFromObjectCreateNewFixed(): void
    {
        $item = LitCalItem::fromObject((object) [
            'liturgical_event' => (object) [
                'event_key' => 'OurLadyOfFatima',
                'day'       => 13,
                'month'     => 5,
                'color'     => ['white'],
                'grade'     => 3,
                'common'    => ['Blessed Virgin Mary'],
            ],
            'metadata'         => (object) [
                'action'     => CalEventAction::CreateNew->value,
                'since_year' => 2002,
            ],
        ]);
        self::assertInstanceOf(LitCalItemCreateNewFixed::class, $item->liturgical_event);
        self::assertInstanceOf(LitCalItemCreateNewMetadata::class, $item->metadata);
    }

    public function testFromObjectCreateNewRequiresDayMonthOrStrtotime(): void
    {
        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage('day');
        LitCalItem::fromObject((object) [
            'liturgical_event' => (object) [
                'event_key' => 'OurLadyOfFatima',
                'color'     => ['white'],
                'grade'     => 3,
                'common'    => ['Blessed Virgin Mary'],
            ],
            'metadata'         => (object) ['action' => CalEventAction::CreateNew->value, 'since_year' => 2002],
        ]);
    }

    public function testFromObjectSetPropertyName(): void
    {
        $item = LitCalItem::fromObject((object) [
            'liturgical_event' => (object) ['event_key' => 'StGeorge', 'name' => 'St George, Martyr'],
            'metadata'         => (object) [
                'action'     => CalEventAction::SetProperty->value,
                'property'   => 'name',
                'since_year' => 1970,
            ],
        ]);
        self::assertInstanceOf(LitCalItemSetPropertyName::class, $item->liturgical_event);
        self::assertInstanceOf(LitCalItemSetPropertyNameMetadata::class, $item->metadata);

        // setName flows through the unlock/lock pattern.
        $item->setName('Renamed');
        self::assertSame('Renamed', $item->liturgical_event->name);
    }

    public function testFromObjectSetPropertyGrade(): void
    {
        $item = LitCalItem::fromObject((object) [
            'liturgical_event' => (object) ['event_key' => 'StGeorge', 'grade' => 4],
            'metadata'         => (object) [
                'action'     => CalEventAction::SetProperty->value,
                'property'   => 'grade',
                'since_year' => 1970,
            ],
        ]);
        self::assertInstanceOf(LitCalItemSetPropertyGrade::class, $item->liturgical_event);
    }

    public function testFromObjectSetPropertyRejectsUnknownProperty(): void
    {
        $this->expectException(\ValueError::class);
        LitCalItem::fromObject((object) [
            'liturgical_event' => (object) ['event_key' => 'StGeorge'],
            'metadata'         => (object) [
                'action'     => CalEventAction::SetProperty->value,
                'property'   => 'color',
                'since_year' => 1970,
            ],
        ]);
    }

    public function testFromObjectRejectsUnknownAction(): void
    {
        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage('metadata.action must be one of');
        LitCalItem::fromObject((object) [
            'liturgical_event' => (object) ['event_key' => 'X'],
            'metadata'         => (object) ['action' => 'destroyEvent', 'since_year' => 2020],
        ]);
    }

    public function testFromObjectRejectsMissingTopLevelKeys(): void
    {
        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage('liturgical_event');
        LitCalItem::fromObject((object) ['metadata' => (object) ['action' => 'moveEvent']]);
    }

    public function testFromObjectRejectsMetadataWithoutAction(): void
    {
        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage('action');
        LitCalItem::fromObject((object) [
            'liturgical_event' => (object) ['event_key' => 'X'],
            'metadata'         => (object) ['since_year' => 2020],
        ]);
    }

    public function testCreateNewWithoutEventKeyThrows(): void
    {
        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage('event_key');
        LitCalItem::fromObject((object) [
            'liturgical_event' => (object) [
                'day'    => 1,
                'month'  => 1,
                'color'  => ['white'],
                'grade'  => 3,
                'common' => [],
            ],
            'metadata'         => (object) ['action' => 'createNew', 'since_year' => 2020],
        ]);
    }

    public function testFromArrayMoveEvent(): void
    {
        $item = LitCalItem::fromArray([
            'liturgical_event' => ['event_key' => 'CorpusChristi', 'day' => 7, 'month' => 6],
            'metadata'         => [
                'action'     => 'moveEvent',
                'since_year' => 2020,
                'until_year' => null,
                'missal'     => 'IT_2020',
                'reason'     => 'CEI',
            ],
        ]);
        self::assertInstanceOf(LitCalItemMoveEvent::class, $item->liturgical_event);
    }

    public function testFromArrayRejectsMissingMetadataAction(): void
    {
        $this->expectException(\ValueError::class);
        LitCalItem::fromArray([
            'liturgical_event' => ['event_key' => 'X'],
            'metadata'         => ['since_year' => 2020],
        ]);
    }

    public function testFromArrayRejectsMissingTopLevelKeys(): void
    {
        $this->expectException(\ValueError::class);
        LitCalItem::fromArray(['metadata' => ['action' => 'moveEvent']]);
    }
}
