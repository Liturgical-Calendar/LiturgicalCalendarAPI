<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Schemas;

use LiturgicalCalendar\Api\Enum\LitSchema;
use LiturgicalCalendar\Api\Router;
use PHPUnit\Framework\TestCase;
use Swaggest\JsonSchema\Schema;

/**
 * Unit tests for the per-action decree shapes in LitCalDecreesSource.json (issue #314).
 *
 * The source schema discriminates decrees by metadata.action (and metadata.property
 * for setProperty), mirroring the five model classes in src/Models/Decrees/.
 */
final class DecreesSourceSchemaTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // LitSchema::path() depends on Router::$apiFilePath; initialize it.
        Router::getApiPaths();
    }

    private static function schema(): Schema
    {
        return Schema::import(LitSchema::DECREES_SRC->path());
    }

    private function assertValidDecree(\stdClass $decree): void
    {
        self::schema()->in([$decree]);
        $this->addToAssertionCount(1);
    }

    private function assertInvalidDecree(\stdClass $decree): void
    {
        $this->expectException(\Swaggest\JsonSchema\Exception::class);
        self::schema()->in([$decree]);
    }

    private static function decode(string $json): \stdClass
    {
        $obj = json_decode($json);
        assert($obj instanceof \stdClass);
        return $obj;
    }

    private static function createNewFixedDecree(): \stdClass
    {
        return self::decode(<<<'JSON'
        {
            "decree_id": "StTest_Create",
            "decree_date": "2025-01-01",
            "decree_protocol": "Prot. N. 1/25",
            "description": "Test decree creating a new fixed-date liturgical event.",
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
                "url": "https://www.vatican.va/roman_curia/congregations/ccdds/documents/test.html",
                "url_lang_map": { "en": "en", "pt": "po" },
                "urls_langs": {
                    "en": "https://www.vatican.va/roman_curia/congregations/ccdds/documents/test_en.html",
                    "pt": "https://www.vatican.va/roman_curia/congregations/ccdds/documents/test_po.html"
                }
            }
        }
        JSON);
    }

    private static function createNewMobileDecree(): \stdClass
    {
        return self::decode(<<<'JSON'
        {
            "decree_id": "StTestMobile_Create",
            "decree_date": "2025-01-01",
            "decree_protocol": "Prot. N. 2/25",
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
                "url": "https://www.vatican.va/roman_curia/congregations/ccdds/documents/test.html"
            }
        }
        JSON);
    }

    private static function setPropertyGradeDecree(): \stdClass
    {
        return self::decode(<<<'JSON'
        {
            "decree_id": "StTest_Upgrade",
            "decree_date": "2025-01-01",
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
                "url": "https://www.vatican.va/roman_curia/congregations/ccdds/documents/test.html"
            }
        }
        JSON);
    }

    private static function setPropertyNameDecree(): \stdClass
    {
        return self::decode(<<<'JSON'
        {
            "decree_id": "StTest_NameChange",
            "decree_date": "2025-01-01",
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
                "url": "https://www.vatican.va/roman_curia/congregations/ccdds/documents/test.html"
            }
        }
        JSON);
    }

    private static function makeDoctorDecree(): \stdClass
    {
        return self::decode(<<<'JSON'
        {
            "decree_id": "StTest_Doctor",
            "decree_date": "2025-01-01",
            "decree_protocol": "Prot. N. 5/25",
            "description": "Test decree declaring a saint Doctor of the Church.",
            "liturgical_event": {
                "event_key": "StTest",
                "common": ["Proper"],
                "calendar": "GENERAL ROMAN"
            },
            "metadata": {
                "action": "makeDoctor",
                "since_year": 2025,
                "url": "https://www.vatican.va/roman_curia/congregations/ccdds/documents/test.html"
            }
        }
        JSON);
    }

    public function testCreateNewFixedDecreeIsValid(): void
    {
        $this->assertValidDecree(self::createNewFixedDecree());
    }

    public function testCreateNewMobileDecreeIsValid(): void
    {
        $this->assertValidDecree(self::createNewMobileDecree());
    }

    public function testSetPropertyGradeDecreeIsValid(): void
    {
        $this->assertValidDecree(self::setPropertyGradeDecree());
    }

    public function testSetPropertyNameDecreeIsValid(): void
    {
        $this->assertValidDecree(self::setPropertyNameDecree());
    }

    public function testMakeDoctorDecreeIsValid(): void
    {
        $this->assertValidDecree(self::makeDoctorDecree());
    }

    public function testStrayGradeOnSetPropertyNameIsRejected(): void
    {
        $decree                          = self::setPropertyNameDecree();
        $decree->liturgical_event->grade = 3;
        $this->assertInvalidDecree($decree);
    }

    public function testMismatchedDecreeIdSuffixIsRejected(): void
    {
        // createNew decree with a _Doctor suffix: no oneOf branch matches.
        $decree            = self::createNewFixedDecree();
        $decree->decree_id = 'StTest_Doctor';
        $this->assertInvalidDecree($decree);
    }

    public function testMissingSinceYearIsRejected(): void
    {
        $decree = self::createNewFixedDecree();
        unset($decree->metadata->since_year);
        $this->assertInvalidDecree($decree);
    }

    public function testNameInsideLiturgicalEventIsRejected(): void
    {
        // In source data the event name lives in the i18n sidecar files, never inline.
        $decree                         = self::createNewFixedDecree();
        $decree->liturgical_event->name = 'Saint Test';
        $this->assertInvalidDecree($decree);
    }

    public function testApiPathInSourceIsRejected(): void
    {
        $decree           = self::createNewFixedDecree();
        $decree->api_path = 'https://litcal.johnromanodorazio.com/api/dev/decrees/StTest_Create';
        $this->assertInvalidDecree($decree);
    }

    public function testMakeDoctorWithDayAndMonthIsRejected(): void
    {
        $decree                          = self::makeDoctorDecree();
        $decree->liturgical_event->day   = 14;
        $decree->liturgical_event->month = 2;
        $this->assertInvalidDecree($decree);
    }

    public function testSetPropertyWithUnknownPropertyIsRejected(): void
    {
        $decree                     = self::setPropertyGradeDecree();
        $decree->metadata->property = 'color';
        $this->assertInvalidDecree($decree);
    }

    public function testCreateNewFixedWithoutTypeIsRejected(): void
    {
        $decree = self::createNewFixedDecree();
        unset($decree->liturgical_event->type);
        $this->assertInvalidDecree($decree);
    }
}
