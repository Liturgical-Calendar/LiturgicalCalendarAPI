<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Models\RegionalData;

use LiturgicalCalendar\Api\Models\RegionalData\NationalData\NationalMetadata;
use LiturgicalCalendar\Api\Models\RegionalData\WiderRegionId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(NationalMetadata::class)]
#[CoversClass(WiderRegionId::class)]
#[CoversClass(\LiturgicalCalendar\Api\Models\RegionalData\NationalData\NationalData::class)]
final class NationalMetadataTest extends TestCase
{
    /** @param array<string,mixed> $extra */
    private static function metadata(array $extra): NationalMetadata
    {
        $data = (object) array_merge(['nation' => 'SE', 'locales' => ['sv_SE'], 'missals' => []], $extra);

        return NationalMetadata::fromObject($data);
    }

    public function testAListIsReadInItsDeclaredOrder(): void
    {
        $metadata = self::metadata(['wider_regions' => ['europe', 'nordic']]);

        self::assertSame(['europe', 'nordic'], $metadata->wider_regions);
        self::assertFalse($metadata->usedLegacyWiderRegion);
    }

    public function testTheLegacyStringIsReadAsAOneElementList(): void
    {
        $metadata = self::metadata(['wider_region' => 'europe']);

        self::assertSame(['europe'], $metadata->wider_regions);
        self::assertTrue($metadata->usedLegacyWiderRegion);
    }

    public function testAnEmptyLegacyStringIsNoRegion(): void
    {
        self::assertSame([], self::metadata(['wider_region' => ''])->wider_regions);
    }

    public function testNeitherFieldIsNoRegion(): void
    {
        $metadata = self::metadata([]);

        self::assertSame([], $metadata->wider_regions);
        self::assertFalse($metadata->usedLegacyWiderRegion);
    }

    public function testBothFieldsAreAcceptedWhenTheyAgree(): void
    {
        $metadata = self::metadata(['wider_region' => 'europe', 'wider_regions' => ['europe']]);

        self::assertSame(['europe'], $metadata->wider_regions);
        self::assertFalse($metadata->usedLegacyWiderRegion);
    }

    public function testBothFieldsAreRefusedWhenTheyDisagree(): void
    {
        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage('disagree');

        self::metadata(['wider_region' => 'europe', 'wider_regions' => ['europe', 'nordic']]);
    }

    public function testANonListIsRefusedAsNotAListOfIds(): void
    {
        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage('`metadata.wider_regions` must be a list of wider region ids');

        self::metadata(['wider_regions' => 'europe']);
    }

    public function testDuplicatesAreRefused(): void
    {
        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage('more than once');

        self::metadata(['wider_regions' => ['europe', 'europe']]);
    }

    /** @return array<string, array{mixed}> */
    public static function badRegionProvider(): array
    {
        return [
            'space in id'    => ['middle east'],
            'trailing space' => ['Europe '],
            'digit'          => ['region1'],
            'not a string'   => [42],
        ];
    }

    #[DataProvider('badRegionProvider')]
    public function testAnItemFailingTheIdShapeIsRefused(mixed $region): void
    {
        $this->expectException(\ValueError::class);

        self::metadata(['wider_regions' => [$region]]);
    }

    public function testALegacyNameIsMappedToItsIdAndFlagged(): void
    {
        $m = self::metadata(['wider_regions' => ['Europe', 'Middle East']]);

        self::assertSame(['europe', 'middle-east'], $m->wider_regions);
        self::assertTrue($m->usedLegacyWiderRegionName);
    }

    public function testALegacyNameAndItsIdAreTheSameRegion(): void
    {
        $this->expectException(\ValueError::class);

        self::metadata(['wider_regions' => ['Europe', 'europe']]);
    }

    public function testIdsAreNotFlaggedAsLegacy(): void
    {
        self::assertFalse(self::metadata(['wider_regions' => ['europe']])->usedLegacyWiderRegionName);
    }

    public function testHasWiderRegionIsFalseForAnEmptyList(): void
    {
        $hr                          = \LiturgicalCalendar\Api\Utilities::jsonFileToObject(
            dirname(__DIR__, 3) . '/jsondata/sourcedata/rite/roman/calendars/nations/HR/HR.json'
        );
        $hr->metadata->wider_regions = [];
        unset($hr->metadata->wider_region);

        self::assertFalse(\LiturgicalCalendar\Api\Models\RegionalData\NationalData\NationalData::fromObject($hr)->hasWiderRegion());
    }
}
