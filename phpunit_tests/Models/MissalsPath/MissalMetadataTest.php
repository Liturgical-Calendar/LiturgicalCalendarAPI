<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Models\MissalsPath;

use LiturgicalCalendar\Api\Models\MissalsPath\MissalMetadata;
use LiturgicalCalendar\Api\Models\MissalsPath\MissalYearLimits;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MissalMetadata::class)]
#[CoversClass(MissalYearLimits::class)]
final class MissalMetadataTest extends TestCase
{
    public function testYearLimitsFromArrayWithUntilYear(): void
    {
        $limits = MissalYearLimits::fromArray(['since_year' => 1970, 'until_year' => 2002]);
        self::assertSame(1970, $limits->since_year);
        self::assertSame(2002, $limits->until_year);
    }

    public function testYearLimitsFromArrayOmitsUntilYearWhenNullInJson(): void
    {
        $limits = MissalYearLimits::fromArray(['since_year' => 1970]);
        self::assertNull($limits->until_year);
        // Serialization omits until_year when null.
        self::assertSame(['since_year' => 1970], $limits->jsonSerialize());
    }

    public function testYearLimitsFromObject(): void
    {
        $limits = MissalYearLimits::fromObject((object) ['since_year' => 1983, 'until_year' => 2002]);
        self::assertSame(1983, $limits->since_year);
        self::assertSame(2002, $limits->until_year);
        self::assertSame(['since_year' => 1983, 'until_year' => 2002], $limits->jsonSerialize());
    }

    public function testMissalMetadataFromArrayHappyPath(): void
    {
        $missal = MissalMetadata::fromArray([
            'missal_id'      => 'EDITIO_TYPICA_1970',
            'name'           => 'Editio Typica 1970',
            'region'         => 'VA',
            'locales'        => ['la'],
            'api_path'       => 'https://example.test/missals/EDITIO_TYPICA_1970',
            'year_published' => 1970,
            'year_limits'    => ['since_year' => 1970],
        ]);

        self::assertSame('EDITIO_TYPICA_1970', $missal->missal_id);
        self::assertSame('Editio Typica 1970', $missal->name);
        self::assertSame('VA', $missal->region);
        self::assertSame(['la'], $missal->locales);
        self::assertSame(1970, $missal->year_published);
        self::assertSame(1970, $missal->year_limits->since_year);

        $serialized = $missal->jsonSerialize();
        self::assertSame('EDITIO_TYPICA_1970', $serialized['missal_id']);
        self::assertSame(['since_year' => 1970], $serialized['year_limits']);
    }

    public function testMissalMetadataFromObjectHappyPath(): void
    {
        $missal = MissalMetadata::fromObject((object) [
            'missal_id'      => 'IT_1983',
            'name'           => 'CEI 1983',
            'region'         => 'IT',
            'locales'        => ['it'],
            'api_path'       => null,
            'year_published' => 1983,
            'year_limits'    => (object) ['since_year' => 1983, 'until_year' => 2002],
        ]);
        self::assertSame('IT_1983', $missal->missal_id);
        self::assertNull($missal->api_path);
        self::assertSame(2002, $missal->year_limits->until_year);
    }

    public function testMissalMetadataLockingPreventsMutation(): void
    {
        $missal = MissalMetadata::fromArray([
            'missal_id'      => 'EDITIO_TYPICA_1970',
            'name'           => 'Editio Typica 1970',
            'region'         => 'VA',
            'locales'        => [],
            'api_path'       => null,
            'year_published' => 1970,
            'year_limits'    => ['since_year' => 1970],
        ]);

        $this->expectException(\LogicException::class);
        $missal->extra = 'nope';
    }
}
