<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Schemas;

use LiturgicalCalendar\Api\Enum\LitSchema;
use LiturgicalCalendar\Api\Router;
use PHPUnit\Framework\TestCase;
use Swaggest\JsonSchema\Schema;

/**
 * Pins the `setProperty` branches added to DiocesanCalendar.json.
 */
final class DiocesanCalendarSetPropertySchemaTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        Router::getApiPaths();
    }

    /**
     * Wraps litcal rows in the surrounding diocesan-calendar document.
     *
     * @param \stdClass[] $litcalItems
     */
    private function wrap(array $litcalItems): \stdClass
    {
        return (object) [
            'litcal'   => $litcalItems,
            'metadata' => (object) [
                'diocese_id'   => 'lugano_ch',
                'diocese_name' => 'Lugano',
                'nation'       => 'CH',
                'locales'      => ['it_IT', 'la_VA'],
                'timezone'     => 'Europe/Zurich',
                'rite'         => 'ambrosian',
            ],
        ];
    }

    public function testSetPropertyGradeRowValidates(): void
    {
        $schema = Schema::import(LitSchema::DIOCESAN->path());

        $schema->in($this->wrap([
            (object) [
                'liturgical_event' => (object) ['event_key' => 'StsProtaseGervase', 'grade' => 3],
                'metadata'         => (object) [
                    'action'      => 'setProperty',
                    'property'    => 'grade',
                    'since_year'  => 2024,
                    'form_rownum' => 1,
                ],
            ],
        ]));

        self::assertTrue(true, 'A setProperty:grade row must validate.');
    }

    public function testSetPropertyCommonRowValidates(): void
    {
        $schema = Schema::import(LitSchema::DIOCESAN->path());

        $schema->in($this->wrap([
            (object) [
                'liturgical_event' => (object) ['event_key' => 'StsProtaseGervase', 'common' => ['Proper']],
                'metadata'         => (object) [
                    'action'      => 'setProperty',
                    'property'    => 'common',
                    'since_year'  => 2024,
                    'form_rownum' => 2,
                ],
            ],
        ]));

        self::assertTrue(true, 'A setProperty:common row must validate.');
    }

    public function testSetPropertyNameRowValidatesWithOnlyAnEventKey(): void
    {
        $schema = Schema::import(LitSchema::DIOCESAN->path());

        $schema->in($this->wrap([
            (object) [
                'liturgical_event' => (object) ['event_key' => 'StFrancisOfAssisi'],
                'metadata'         => (object) [
                    'action'      => 'setProperty',
                    'property'    => 'name',
                    'since_year'  => 2024,
                    'form_rownum' => 3,
                ],
            ],
        ]));

        self::assertTrue(true, 'A setProperty:name row needs only an event_key.');
    }

    public function testCreateNewRowWithoutAnActionStillValidates(): void
    {
        $schema = Schema::import(LitSchema::DIOCESAN->path());

        $schema->in($this->wrap([
            (object) [
                'liturgical_event' => (object) [
                    'event_key' => 'BeatoManfredoSettala',
                    'color'     => ['white'],
                    'grade'     => 3,
                    'common'    => ['Proper'],
                    'day'       => 27,
                    'month'     => 1,
                ],
                'metadata'         => (object) ['since_year' => 2024, 'form_rownum' => 0],
            ],
        ]));

        self::assertTrue(true, 'An action-less createNew row must keep validating.');
    }

    public function testSetPropertyGradeRowMissingGradeIsRejected(): void
    {
        $schema = Schema::import(LitSchema::DIOCESAN->path());

        $this->expectException(\Throwable::class);

        $schema->in($this->wrap([
            (object) [
                'liturgical_event' => (object) ['event_key' => 'StsProtaseGervase'],
                'metadata'         => (object) [
                    'action'      => 'setProperty',
                    'property'    => 'grade',
                    'since_year'  => 2024,
                    'form_rownum' => 1,
                ],
            ],
        ]));
    }
}
