<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Models\RegionalData;

use LiturgicalCalendar\Api\Models\RegionalData\NationalData\NationalMetadata;
use LiturgicalCalendar\Api\Models\RegionalData\WiderRegionName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(NationalMetadata::class)]
#[CoversClass(WiderRegionName::class)]
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
        $metadata = self::metadata(['wider_regions' => ['Europe', 'Nordic']]);

        self::assertSame(['Europe', 'Nordic'], $metadata->wider_regions);
        self::assertFalse($metadata->usedLegacyWiderRegion);
    }

    public function testTheLegacyStringIsReadAsAOneElementList(): void
    {
        $metadata = self::metadata(['wider_region' => 'Europe']);

        self::assertSame(['Europe'], $metadata->wider_regions);
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
        $metadata = self::metadata(['wider_region' => 'Europe', 'wider_regions' => ['Europe']]);

        self::assertSame(['Europe'], $metadata->wider_regions);
        self::assertFalse($metadata->usedLegacyWiderRegion);
    }

    public function testBothFieldsAreRefusedWhenTheyDisagree(): void
    {
        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage('disagree');

        self::metadata(['wider_region' => 'Europe', 'wider_regions' => ['Europe', 'Nordic']]);
    }

    public function testDuplicatesAreRefused(): void
    {
        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage('more than once');

        self::metadata(['wider_regions' => ['Europe', 'Europe']]);
    }

    /** @return array<string, array{mixed}> */
    public static function badRegionProvider(): array
    {
        return [
            'lowercase'      => ['europe'],
            'trailing space' => ['Europe '],
            'digit'          => ['Region1'],
            'not a string'   => [42],
        ];
    }

    #[DataProvider('badRegionProvider')]
    public function testAnItemFailingTheNameShapeIsRefused(mixed $region): void
    {
        $this->expectException(\ValueError::class);

        self::metadata(['wider_regions' => [$region]]);
    }

    public function testMultiWordNamesMatchTheShape(): void
    {
        self::assertTrue(WiderRegionName::isValid('Middle East'));
        self::assertTrue(WiderRegionName::isValid('Central America'));
        self::assertFalse(WiderRegionName::isValid('Middle  East'));
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
