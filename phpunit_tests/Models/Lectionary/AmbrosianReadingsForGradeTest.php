<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Models\Lectionary;

use LiturgicalCalendar\Api\Enum\LitGrade;
use LiturgicalCalendar\Api\Models\Lectionary\AmbrosianReadings;
use LiturgicalCalendar\Api\Models\Lectionary\ReadingsFerial;
use LiturgicalCalendar\Api\Models\Lectionary\ReadingsFestive;
use PHPUnit\Framework\TestCase;

final class AmbrosianReadingsForGradeTest extends TestCase
{
    public function testFeastAndAboveGetTheFestivePlaceholder(): void
    {
        self::assertInstanceOf(ReadingsFestive::class, AmbrosianReadings::forGrade(LitGrade::FEAST));
        self::assertInstanceOf(ReadingsFestive::class, AmbrosianReadings::forGrade(LitGrade::FEAST_LORD));
        self::assertInstanceOf(ReadingsFestive::class, AmbrosianReadings::forGrade(LitGrade::SOLEMNITY));
        self::assertInstanceOf(ReadingsFestive::class, AmbrosianReadings::forGrade(LitGrade::HIGHER_SOLEMNITY));
    }

    public function testBelowFeastGetsTheFerialPlaceholder(): void
    {
        self::assertInstanceOf(ReadingsFerial::class, AmbrosianReadings::forGrade(LitGrade::MEMORIAL));
        self::assertInstanceOf(ReadingsFerial::class, AmbrosianReadings::forGrade(LitGrade::MEMORIAL_OPT));
        self::assertInstanceOf(ReadingsFerial::class, AmbrosianReadings::forGrade(LitGrade::COMMEMORATION));
        self::assertInstanceOf(ReadingsFerial::class, AmbrosianReadings::forGrade(LitGrade::WEEKDAY));
    }

    public function testPlaceholderFieldsAreEmptyStrings(): void
    {
        $festive = AmbrosianReadings::forGrade(LitGrade::SOLEMNITY);
        self::assertSame('', $festive->first_reading);
        self::assertSame('', $festive->second_reading);

        $ferial = AmbrosianReadings::forGrade(LitGrade::MEMORIAL);
        self::assertSame('', $ferial->first_reading);
    }
}
