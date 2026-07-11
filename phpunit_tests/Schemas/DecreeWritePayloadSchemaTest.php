<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Schemas;

use LiturgicalCalendar\Api\Enum\LitSchema;
use LiturgicalCalendar\Api\Router;
use PHPUnit\Framework\TestCase;
use Swaggest\JsonSchema\Schema;

final class DecreeWritePayloadSchemaTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // LitSchema::path() depends on Router::$apiFilePath; initialize it.
        Router::getApiPaths();
    }

    private static function schema(): Schema
    {
        return Schema::import(LitSchema::DECREE_WRITE->path());
    }

    private static function validCreateNewPayload(): \stdClass
    {
        $json = <<<'JSON'
        {
            "decree_id": "StTest_Create",
            "decree_date": "2025-01-01",
            "decree_protocol": "Prot. N. 1/25",
            "description": "Test decree creating a new liturgical event.",
            "liturgical_event": {
                "event_key": "StTest",
                "day": 14,
                "month": 2,
                "color": ["white"],
                "grade": 2,
                "common": ["Pastors"],
                "type": "fixed",
                "calendar": "GENERAL ROMAN"
            },
            "metadata": {
                "action": "createNew",
                "since_year": 2025,
                "url": "https://www.vatican.va/roman_curia/congregations/ccdds/documents/test.html"
            },
            "i18n": { "en": "Saint Test" },
            "readings": {
                "en": {
                    "first_reading": "Genesis 1:1",
                    "responsorial_psalm": "Psalm 1",
                    "gospel_acclamation": "John 1:1",
                    "gospel": "John 1:1-14"
                }
            }
        }
        JSON;
        $obj  = json_decode($json);
        assert($obj instanceof \stdClass);
        return $obj;
    }

    public function testValidCreateNewPayloadPasses(): void
    {
        self::schema()->in(self::validCreateNewPayload());
        $this->addToAssertionCount(1);
    }

    public function testUnknownTopLevelPropertyFails(): void
    {
        $payload        = self::validCreateNewPayload();
        $payload->bogus = true;
        $this->expectException(\Swaggest\JsonSchema\Exception::class);
        self::schema()->in($payload);
    }

    public function testEmptyI18nObjectFails(): void
    {
        $payload       = self::validCreateNewPayload();
        $payload->i18n = new \stdClass();
        $this->expectException(\Swaggest\JsonSchema\Exception::class);
        self::schema()->in($payload);
    }

    public function testRegionalLocaleKeyInI18nFails(): void
    {
        $payload       = self::validCreateNewPayload();
        $payload->i18n = (object) ['en_US' => 'Saint Test'];
        $this->expectException(\Swaggest\JsonSchema\Exception::class);
        self::schema()->in($payload);
    }
}
