<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Models\Metadata;

use LiturgicalCalendar\Api\Models\Metadata\MetadataNationalCalendarItem;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A national calendar merged after #1018's deploy from a change request queued before it can still name its regions
 * the old way; `/calendars` must list the nation under the region's id rather than drop it.
 */
#[CoversClass(MetadataNationalCalendarItem::class)]
final class MetadataNationalCalendarItemWiderRegionsTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function base(): array
    {
        return [
            'calendar_id' => 'IT',
            'locales'     => ['it_IT'],
            'missals'     => ['IT_1983'],
            'settings'    => [
                'epiphany'            => 'JAN6',
                'ascension'           => 'SUNDAY',
                'corpus_christi'      => 'SUNDAY',
                'eternal_high_priest' => false,
            ],
        ];
    }

    public function testFromArrayMapsLegacyNamesToIdsAndSkipsGarbage(): void
    {
        $item = MetadataNationalCalendarItem::fromArray(self::base() + ['wider_regions' => ['Europe', 'German Language Area', 'not a region!']]);
        self::assertSame(['europe', 'german-language-area'], $item->wider_regions);

        $legacy = MetadataNationalCalendarItem::fromArray(self::base() + ['wider_region' => 'Europe']);
        self::assertSame(['europe'], $legacy->wider_regions);
    }

    public function testFromObjectMapsLegacyNamesToIds(): void
    {
        $data = json_decode((string) json_encode(self::base() + ['wider_regions' => ['Europe', 'nordic']]));
        self::assertInstanceOf(\stdClass::class, $data);
        $item = MetadataNationalCalendarItem::fromObject($data);
        self::assertSame(['europe', 'nordic'], $item->wider_regions);
    }
}
