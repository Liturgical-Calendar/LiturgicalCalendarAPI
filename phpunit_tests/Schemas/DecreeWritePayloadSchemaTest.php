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

    public function testUrlLangMapAcceptsAnyIso6391KeyAndArbitraryVaticanCode(): void
    {
        // DecreeLangs is no longer a closed 8-language enum: source URLs may be
        // in any language, so any ISO 639-1 key maps to an arbitrary Vatican code.
        $payload                         = self::validCreateNewPayload();
        $payload->metadata->url          = 'https://www.vatican.va/content/john-paul-ii/%s/apost_letters/1997/documents/test.html';
        $payload->metadata->url_lang_map = (object) ['ru' => 'russian', 'zh' => 'zh', 'de' => 'ge'];
        self::schema()->in($payload);
        $this->addToAssertionCount(1);
    }

    public function testUrlLangMapRejectsNonIso6391Key(): void
    {
        $payload                         = self::validCreateNewPayload();
        $payload->metadata->url_lang_map = (object) ['eng' => 'en']; // 3-letter key not allowed
        $this->expectException(\Swaggest\JsonSchema\Exception::class);
        self::schema()->in($payload);
    }

    private static function decodePayload(string $json): \stdClass
    {
        $obj = json_decode($json);
        assert($obj instanceof \stdClass);
        return $obj;
    }

    private static function validMakeDoctorPayload(): \stdClass
    {
        return self::decodePayload(<<<'JSON'
        {
            "decree_id": "StTest_Doctor",
            "decree_date": "2025-06-01",
            "decree_protocol": "Prot. N. 2/25",
            "description": "Test decree elevating a saint to Doctor of the Church.",
            "liturgical_event": {
                "event_key": "StTest",
                "common": ["Proper"],
                "calendar": "GENERAL ROMAN"
            },
            "metadata": {
                "action": "makeDoctor",
                "since_year": 2025,
                "url": "https://www.vatican.va/roman_curia/congregations/ccdds/documents/test-doctor.html"
            },
            "i18n": { "en": "Saint Test, Doctor of the Church" }
        }
        JSON);
    }

    private static function validSetPropertyGradePayload(): \stdClass
    {
        return self::decodePayload(<<<'JSON'
        {
            "decree_id": "StTest_Upgrade",
            "decree_date": "2025-06-01",
            "decree_protocol": "Prot. N. 3/25",
            "description": "Test decree upgrading the grade of a liturgical event.",
            "liturgical_event": {
                "event_key": "StTest",
                "grade": 4,
                "calendar": "GENERAL ROMAN"
            },
            "metadata": {
                "action": "setProperty",
                "property": "grade",
                "since_year": 2025,
                "url": "https://www.vatican.va/roman_curia/congregations/ccdds/documents/test-grade.html"
            }
        }
        JSON);
    }

    private static function validSetPropertyNamePayload(): \stdClass
    {
        return self::decodePayload(<<<'JSON'
        {
            "decree_id": "StTest_NameChange",
            "decree_date": "2025-06-01",
            "decree_protocol": "Prot. N. 4/25",
            "description": "Test decree changing the name of a liturgical event.",
            "liturgical_event": {
                "event_key": "StTest",
                "calendar": "GENERAL ROMAN"
            },
            "metadata": {
                "action": "setProperty",
                "property": "name",
                "since_year": 2025,
                "url": "https://www.vatican.va/roman_curia/congregations/ccdds/documents/test-name.html"
            },
            "i18n": { "en": "Saint Test, renamed" }
        }
        JSON);
    }

    private static function validCreateNewMobilePayload(): \stdClass
    {
        return self::decodePayload(<<<'JSON'
        {
            "decree_id": "StTestMobile_Create",
            "decree_date": "2025-06-01",
            "decree_protocol": "Prot. N. 5/25",
            "description": "Test decree creating a new mobile liturgical event.",
            "liturgical_event": {
                "event_key": "StTestMobile",
                "color": ["white"],
                "grade": 2,
                "common": ["Proper"],
                "type": "mobile",
                "calendar": "GENERAL ROMAN",
                "strtotime": {
                    "day_of_the_week": "Monday",
                    "relative_time": "after",
                    "event_key": "Pentecost"
                }
            },
            "metadata": {
                "action": "createNew",
                "since_year": 2025,
                "url": "https://www.vatican.va/roman_curia/congregations/ccdds/documents/test-mobile.html"
            },
            "i18n": { "en": "Saint Test Mobile" },
            "readings": {
                "en": {
                    "first_reading": "Genesis 1:1",
                    "responsorial_psalm": "Psalm 1",
                    "gospel_acclamation": "John 1:1",
                    "gospel": "John 1:1-14"
                }
            }
        }
        JSON);
    }

    public function testValidMakeDoctorPayloadPasses(): void
    {
        self::schema()->in(self::validMakeDoctorPayload());
        $this->addToAssertionCount(1);
    }

    public function testValidSetPropertyGradePayloadPasses(): void
    {
        self::schema()->in(self::validSetPropertyGradePayload());
        $this->addToAssertionCount(1);
    }

    public function testValidSetPropertyNamePayloadPasses(): void
    {
        self::schema()->in(self::validSetPropertyNamePayload());
        $this->addToAssertionCount(1);
    }

    public function testValidCreateNewMobilePayloadPasses(): void
    {
        self::schema()->in(self::validCreateNewMobilePayload());
        $this->addToAssertionCount(1);
    }

    public function testDecreeIdSuffixMismatchedWithActionFails(): void
    {
        // createNew payload with a _Doctor decree_id: no oneOf branch matches.
        $payload            = self::validCreateNewPayload();
        $payload->decree_id = 'StTest_Doctor';
        $this->expectException(\Swaggest\JsonSchema\Exception::class);
        self::schema()->in($payload);
    }

    public function testStrayGradeOnSetPropertyNamePayloadFails(): void
    {
        $payload                          = self::validSetPropertyNamePayload();
        $payload->liturgical_event->grade = 3;
        $this->expectException(\Swaggest\JsonSchema\Exception::class);
        self::schema()->in($payload);
    }

    public function testMissingSinceYearFails(): void
    {
        $payload = self::validCreateNewPayload();
        unset($payload->metadata->since_year);
        $this->expectException(\Swaggest\JsonSchema\Exception::class);
        self::schema()->in($payload);
    }
}
