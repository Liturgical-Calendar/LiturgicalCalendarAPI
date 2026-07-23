<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Enum;

use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Router;
use PHPUnit\Framework\TestCase;

final class JsonDataAmbrosianPathTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // JsonData::path() concatenates Router::$apiFilePath; populate it.
        Router::getApiPaths();
    }

    public function testAmbrosianTemporaleFilePath(): void
    {
        $this->assertStringEndsWith(
            'jsondata/sourcedata/rite/ambrosian/missals/propriumdetempore/propriumdetempore.json',
            JsonData::AMBROSIAN_TEMPORALE_FILE->path()
        );
    }

    public function testAmbrosianTemporaleI18nFilePath(): void
    {
        $this->assertStringEndsWith(
            'jsondata/sourcedata/rite/ambrosian/missals/propriumdetempore/i18n/{locale}.json',
            JsonData::AMBROSIAN_TEMPORALE_I18N_FILE->path()
        );
    }
}
