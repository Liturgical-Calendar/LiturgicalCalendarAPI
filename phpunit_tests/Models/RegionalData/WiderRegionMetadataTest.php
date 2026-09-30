<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Models\RegionalData;

use LiturgicalCalendar\Api\Models\RegionalData\WiderRegionData\WiderRegionMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WiderRegionMetadata::class)]
final class WiderRegionMetadataTest extends TestCase
{
    public function testIdAndLabelsAreRead(): void
    {
        $m = WiderRegionMetadata::fromObject((object) [
            'locales'      => ['it_IT', 'de_DE'],
            'wider_region' => 'europe',
            'labels'       => (object) ['en' => 'Europe', 'it' => 'Europa'],
        ]);

        self::assertSame('europe', $m->wider_region);
        self::assertSame(['en' => 'Europe', 'it' => 'Europa'], $m->labels);
        self::assertFalse($m->usedLegacyId);
        self::assertSame(
            ['locales' => ['it_IT', 'de_DE'], 'wider_region' => 'europe', 'labels' => ['en' => 'Europe', 'it' => 'Europa']],
            $m->jsonSerialize()
        );
    }

    public function testALegacyNameIsMappedAndFlagged(): void
    {
        $m = WiderRegionMetadata::fromArray(['locales' => ['it_IT'], 'wider_region' => 'Middle East']);

        self::assertSame('middle-east', $m->wider_region);
        self::assertTrue($m->usedLegacyId);
        self::assertSame([], $m->labels);
        self::assertArrayNotHasKey('usedLegacyId', $m->jsonSerialize());
    }

    public function testAnInvalidIdIsRefused(): void
    {
        $this->expectException(\ValueError::class);
        WiderRegionMetadata::fromArray(['locales' => ['it_IT'], 'wider_region' => 'middle east']);
    }

    public function testALabelInAnUndeclaredLanguageIsRefused(): void
    {
        $this->expectException(\ValueError::class);
        WiderRegionMetadata::fromObject((object) [
            'locales'      => ['it_IT'],
            'wider_region' => 'europe',
            'labels'       => (object) ['sw' => 'Ulaya'],
        ]);
    }
}
