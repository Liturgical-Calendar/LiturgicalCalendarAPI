<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Models\Lectionary;

use LiturgicalCalendar\Api\Models\Lectionary\ReadingsFerial;
use LiturgicalCalendar\Api\Models\Lectionary\ReadingsMap;
use LiturgicalCalendar\Api\Services\SupportedLocales;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A missing readings offset must be fatal for an officially supported locale and
 * harmless for any other.
 *
 * This is the regression guard for #904: a decree created without full lectionary
 * coverage took the whole Croatian calendar down with a 500, because a missing
 * offset always threw regardless of whether the locale promised completeness.
 */
#[CoversClass(ReadingsMap::class)]
final class ReadingsMapLocaleStrictnessTest extends TestCase
{
    protected function setUp(): void
    {
        SupportedLocales::reset();
    }

    private function mapWithOneEvent(?string $locale): ReadingsMap
    {
        $map = new ReadingsMap();
        $map->addFromArray([
            'StKnownEvent' => [
                'first_reading'      => 'Sir 39, 8-14',
                'responsorial_psalm' => 'Ps 39, 2',
                'gospel_acclamation' => 'Mt 23, 9b',
                'gospel'             => 'Mt 13, 47-52'
            ]
        ]);
        $map->setLocale($locale);

        return $map;
    }

    public function testAKnownOffsetIsReturnedRegardlessOfLocale(): void
    {
        $readings = $this->mapWithOneEvent('en')->getReadings('StKnownEvent');

        self::assertSame('Sir 39, 8-14', $readings->first_reading);
    }

    #[DataProvider('officialLocales')]
    public function testAMissingOffsetThrowsForAnOfficialLocale(string $locale): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('No readings found for offset: StMissingEvent');

        $this->mapWithOneEvent($locale)->getReadings('StMissingEvent');
    }

    /** @return array<string, array{string}> */
    public static function officialLocales(): array
    {
        return ['en' => ['en'], 'fr' => ['fr'], 'it' => ['it'], 'la' => ['la'], 'nl' => ['nl']];
    }

    #[DataProvider('unofficialLocales')]
    public function testAMissingOffsetYieldsEmptyReadingsForAnUnofficialLocale(string $locale): void
    {
        $readings = $this->mapWithOneEvent($locale)->getReadings('StMissingEvent');

        self::assertInstanceOf(ReadingsFerial::class, $readings);
        self::assertSame('', $readings->first_reading);
        self::assertSame('', $readings->responsorial_psalm);
        self::assertSame('', $readings->gospel_acclamation);
        self::assertSame('', $readings->gospel);
    }

    /** @return array<string, array{string}> */
    public static function unofficialLocales(): array
    {
        return ['hr' => ['hr'], 'es' => ['es'], 'de' => ['de']];
    }

    public function testAMapWithNoLocaleIsLenient(): void
    {
        // Fixtures and array-built maps carry no locale; they must not throw.
        $readings = $this->mapWithOneEvent(null)->getReadings('StMissingEvent');

        self::assertSame('', $readings->gospel);
    }

    public function testTheCroatianCaseFromIssue904(): void
    {
        // Exactly the shape that 500'd: StJohnNewman present in some locales,
        // absent from the Croatian lectionary.
        $readings = $this->mapWithOneEvent('hr')->getReadings('StJohnNewman');

        self::assertSame('', $readings->first_reading);
    }
}
