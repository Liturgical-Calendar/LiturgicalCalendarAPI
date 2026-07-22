<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Enum;

use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Router;
use PHPUnit\Framework\TestCase;

final class JsonDataAmbrosianSanctoralePathTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // JsonData::path() concatenates Router::$apiFilePath; populate it.
        Router::getApiPaths();
    }

    public function testAmbrosianSanctoraleFilePath(): void
    {
        $this->assertStringEndsWith(
            'jsondata/sourcedata/missals/ambrosian/propriumdesanctis_2024/propriumdesanctis.json',
            JsonData::AMBROSIAN_SANCTORALE_FILE->path()
        );
    }

    public function testAmbrosianSanctoraleI18nFilePath(): void
    {
        $this->assertStringEndsWith(
            'jsondata/sourcedata/missals/ambrosian/propriumdesanctis_2024/i18n/{locale}.json',
            JsonData::AMBROSIAN_SANCTORALE_I18N_FILE->path()
        );
    }
}
