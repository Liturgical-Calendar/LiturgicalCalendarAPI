<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Models\Lectionary;

use LiturgicalCalendar\Api\DateTime;
use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Models\Calendar\LiturgicalEvent;
use LiturgicalCalendar\Api\Models\Lectionary\AmbrosianReadings;
use LiturgicalCalendar\Api\Models\Lectionary\ReadingsFerial;
use LiturgicalCalendar\Api\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Swaggest\JsonSchema\Schema;

/**
 * Unit tests for the Ambrosian empty-readings placeholder (Plan 7 / Task 2).
 *
 * There is no Ambrosian lectionary data source yet, but the `LitCal.json` response schema
 * requires every `LiturgicalEvent` to carry a `readings` property that validates against
 * `CommonDef.json#/definitions/Readings`. `AmbrosianReadings::empty()` produces a structurally
 * valid, content-empty `ReadingsFerial` instance (the simplest of the `ReadingsAbstract`
 * subclasses: only `first_reading`, `responsorial_psalm`, `gospel_acclamation`, and `gospel`,
 * all plain strings) to serve as that placeholder until a real Ambrosian lectionary is wired in.
 */
#[CoversClass(AmbrosianReadings::class)]
final class AmbrosianReadingsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // LitSchema / JsonData path resolution depends on Router::$apiFilePath.
        Router::getApiPaths();
    }

    private static function readingsSchema(): Schema
    {
        $commonDefPath = JsonData::SCHEMAS_FOLDER->path() . '/CommonDef.json';
        return Schema::import($commonDefPath . '#/definitions/Readings');
    }

    public function testEmptyReturnsReadingsFerialInstance(): void
    {
        $readings = AmbrosianReadings::empty();

        self::assertInstanceOf(ReadingsFerial::class, $readings);
    }

    public function testEmptyHasBlankStringFields(): void
    {
        $readings   = AmbrosianReadings::empty();
        $serialized = $readings->jsonSerialize();

        self::assertSame(
            [
                'first_reading'      => '',
                'responsorial_psalm' => '',
                'gospel_acclamation' => '',
                'gospel'             => '',
            ],
            $serialized
        );
    }

    public function testEmptyValidatesAgainstCommonDefReadingsSchema(): void
    {
        $readings = AmbrosianReadings::empty();

        $json = json_encode($readings->jsonSerialize());
        self::assertIsString($json);
        $decoded = json_decode($json);
        self::assertInstanceOf(\stdClass::class, $decoded);

        self::readingsSchema()->in($decoded);
        $this->addToAssertionCount(1);
    }

    public function testEmptyCanBeAssignedToLiturgicalEventViaSetReadings(): void
    {
        $event            = new LiturgicalEvent(
            'Test Ambrosian Event',
            new DateTime('2026-07-20T00:00:00+00:00')
        );
        $event->event_key = 'TestAmbrosianEvent';

        $event->setReadings(AmbrosianReadings::empty());

        $serialized = $event->jsonSerialize();
        self::assertArrayHasKey('readings', $serialized);
        self::assertSame(
            [
                'first_reading'      => '',
                'responsorial_psalm' => '',
                'gospel_acclamation' => '',
                'gospel'             => '',
            ],
            $serialized['readings']
        );
    }
}
