<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Enum;

use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Enum\JsonDataConstants;
use LiturgicalCalendar\Api\Router;
use PHPUnit\Framework\TestCase;

final class JsonDataAmbrosianDiocesanPathTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // JsonData::path() concatenates Router::$apiFilePath; populate it.
        Router::getApiPaths();
    }

    public function testAmbrosianDiocesanCalendarsFolderConstant(): void
    {
        $this->assertSame(
            'jsondata/sourcedata/rite/ambrosian/calendars/dioceses',
            JsonDataConstants::AMBROSIAN_DIOCESAN_CALENDARS_FOLDER
        );
    }

    public function testAmbrosianDiocesanCalendarFileConstant(): void
    {
        $this->assertSame(
            'jsondata/sourcedata/rite/ambrosian/calendars/dioceses/{nation}/{diocese}/{diocese_name}.json',
            JsonDataConstants::AMBROSIAN_DIOCESAN_CALENDAR_FILE
        );
    }

    public function testAmbrosianDiocesanCalendarsFolderEnumValue(): void
    {
        $this->assertSame(
            JsonDataConstants::AMBROSIAN_DIOCESAN_CALENDARS_FOLDER,
            JsonData::AMBROSIAN_DIOCESAN_CALENDARS_FOLDER->value
        );
    }

    public function testAmbrosianDiocesanCalendarFileEnumValue(): void
    {
        $this->assertSame(
            JsonDataConstants::AMBROSIAN_DIOCESAN_CALENDAR_FILE,
            JsonData::AMBROSIAN_DIOCESAN_CALENDAR_FILE->value
        );
    }

    public function testAmbrosianDiocesanCalendarI18nFolderConstant(): void
    {
        $this->assertSame(
            'jsondata/sourcedata/rite/ambrosian/calendars/dioceses/{nation}/{diocese}/i18n',
            JsonDataConstants::AMBROSIAN_DIOCESAN_CALENDAR_I18N_FOLDER
        );
    }

    public function testAmbrosianDiocesanCalendarI18nFileConstant(): void
    {
        $this->assertSame(
            'jsondata/sourcedata/rite/ambrosian/calendars/dioceses/{nation}/{diocese}/i18n/{locale}.json',
            JsonDataConstants::AMBROSIAN_DIOCESAN_CALENDAR_I18N_FILE
        );
    }

    public function testAmbrosianDiocesanCalendarI18nFolderEnumValue(): void
    {
        $this->assertSame(
            JsonDataConstants::AMBROSIAN_DIOCESAN_CALENDAR_I18N_FOLDER,
            JsonData::AMBROSIAN_DIOCESAN_CALENDAR_I18N_FOLDER->value
        );
    }

    public function testAmbrosianDiocesanCalendarI18nFileEnumValue(): void
    {
        $this->assertSame(
            JsonDataConstants::AMBROSIAN_DIOCESAN_CALENDAR_I18N_FILE,
            JsonData::AMBROSIAN_DIOCESAN_CALENDAR_I18N_FILE->value
        );
    }
}
