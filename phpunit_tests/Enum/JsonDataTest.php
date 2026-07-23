<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Enum;

use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Enum\JsonDataConstants;
use LiturgicalCalendar\Api\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(JsonData::class)]
#[CoversClass(JsonDataConstants::class)]
final class JsonDataTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // JsonData::path() concatenates Router::$apiFilePath; populate it.
        Router::getApiPaths();
    }

    public function testConstantHierarchyComposesCorrectly(): void
    {
        self::assertSame('jsondata', JsonDataConstants::FOLDER);
        self::assertSame('jsondata/schemas', JsonDataConstants::SCHEMAS_FOLDER);
        self::assertSame('jsondata/sourcedata', JsonDataConstants::SOURCEDATA_FOLDER);
        self::assertSame('jsondata/sourcedata/rite/roman', JsonDataConstants::ROMAN_RITE_FOLDER);
        self::assertSame('jsondata/sourcedata/rite/ambrosian', JsonDataConstants::AMBROSIAN_RITE_FOLDER);
        self::assertSame('jsondata/sourcedata/rite/roman/decrees', JsonDataConstants::DECREES_FOLDER);
        self::assertSame('jsondata/sourcedata/rite/roman/decrees/decrees.json', JsonDataConstants::DECREES_FILE);
        self::assertSame('jsondata/sourcedata/rite/roman/missals', JsonDataConstants::MISSALS_FOLDER);
        self::assertSame(
            'jsondata/sourcedata/rite/roman/missals/propriumdetempore',
            JsonDataConstants::TEMPORALE_FOLDER
        );
        self::assertSame(
            'jsondata/sourcedata/rite/roman/missals/propriumdetempore/propriumdetempore.json',
            JsonDataConstants::TEMPORALE_FILE
        );
        self::assertSame('jsondata/sourcedata/rite/roman/calendars', JsonDataConstants::CALENDARS_FOLDER);
        self::assertSame(
            'jsondata/sourcedata/rite/roman/calendars/nations',
            JsonDataConstants::NATIONAL_CALENDARS_FOLDER
        );
        self::assertSame(
            'jsondata/sourcedata/rite/roman/calendars/dioceses',
            JsonDataConstants::DIOCESAN_CALENDARS_FOLDER
        );
        self::assertSame('jsondata/world_dioceses.json', JsonDataConstants::CATHOLIC_DIOCESES_LATIN_RITE);
    }

    public function testEnumCaseValuesEchoConstants(): void
    {
        self::assertSame(JsonDataConstants::FOLDER, JsonData::FOLDER->value);
        self::assertSame(JsonDataConstants::SCHEMAS_FOLDER, JsonData::SCHEMAS_FOLDER->value);
        self::assertSame(JsonDataConstants::DECREES_FILE, JsonData::DECREES_FILE->value);
        self::assertSame(
            JsonDataConstants::CATHOLIC_DIOCESES_LATIN_RITE,
            JsonData::CATHOLIC_DIOCESES_LATIN_RITE->value
        );
    }

    public function testPathPrependsApiFilePath(): void
    {
        $path = JsonData::FOLDER->path();
        self::assertSame(Router::$apiFilePath . 'jsondata', $path);

        $schemaPath = JsonData::SCHEMAS_FOLDER->path();
        self::assertStringEndsWith('jsondata/schemas', $schemaPath);
    }
}
