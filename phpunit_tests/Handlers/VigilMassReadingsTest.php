<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Handlers\CalendarHandler;
use LiturgicalCalendar\Api\Http\Enum\ReturnTypeParam;
use LiturgicalCalendar\Api\Models\Calendar\LiturgicalEventCollection;
use LiturgicalCalendar\Api\Models\Lectionary\ReadingsGeneralRoman;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A vigil Mass whose lectionary entry has readings of its own must be served them, not the day Mass.
 *
 * The lectionary stores such an entry as `{vigil, day}` (Pentecost in the Sunday cycles;
 * NativityJohnBaptist, StsPeterPaulAp and Assumption in the sanctorale), and the getters that set an
 * event's readings unwrap it to `day`. The vigil Mass is created afterwards from the event, so it used
 * to inherit the day Mass: Psalm 139 instead of 71 on the eve of the Nativity of John the Baptist,
 * Psalm 45 instead of 132 on the eve of the Assumption. Found by comparing the Italian psalms with
 * the CEI's liturgy of the day.
 *
 * The lectionary is a process-wide static loaded in whatever locale the first calendar of the run
 * asked for, so this class pins it to English and reads the expectations from the English sources.
 */
#[CoversClass(CalendarHandler::class)]
#[CoversClass(LiturgicalEventCollection::class)]
#[CoversClass(ReadingsGeneralRoman::class)]
final class VigilMassReadingsTest extends AbstractHandlerTestCase
{
    /** @var array<string, array<mixed>> */
    private static array $events = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        LiturgicalEventCollection::$lectionary = new ReadingsGeneralRoman('en');
    }

    /**
     * @return array<string, array{0: string, 1: JsonData}>
     */
    public static function vigilProvider(): array
    {
        return [
            'Pentecost (Sunday cycle A)' => ['Pentecost', JsonData::LECTIONARY_SUNDAYS_SOLEMNITIES_A_FILE],
            'NativityJohnBaptist'        => ['NativityJohnBaptist', JsonData::LECTIONARY_SAINTS_FILE],
            'StsPeterPaulAp'             => ['StsPeterPaulAp', JsonData::LECTIONARY_SAINTS_FILE],
            'Assumption'                 => ['Assumption', JsonData::LECTIONARY_SAINTS_FILE],
        ];
    }

    #[DataProvider('vigilProvider')]
    public function testVigilMassIsServedItsOwnReadings(string $eventKey, JsonData $lectionaryFile): void
    {
        $source = $this->sourceEntry($lectionaryFile, $eventKey);
        $events = $this->calendar2026();

        self::assertArrayHasKey($eventKey . '_vigil', $events, "{$eventKey} has no vigil Mass in 2026");
        self::assertSame($source['vigil'], $events[$eventKey . '_vigil']['readings'], "{$eventKey}_vigil readings");
        self::assertSame($source['day'], $events[$eventKey]['readings'], "{$eventKey} readings");
    }

    /**
     * An entry without a vigil of its own keeps serving its readings at the vigil, as before.
     */
    public function testVigilMassWithoutReadingsOfItsOwnRepeatsTheDayMass(): void
    {
        $events = $this->calendar2026();

        self::assertArrayHasKey('StJoseph_vigil', $events);
        self::assertSame($events['StJoseph']['readings'], $events['StJoseph_vigil']['readings']);
    }

    /**
     * @return array{vigil: array<string, string>, day: array<string, string>}
     */
    private function sourceEntry(JsonData $lectionaryFile, string $eventKey): array
    {
        $path = strtr($lectionaryFile->path(), ['{locale}' => 'en']);
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertIsArray($data[$eventKey] ?? null, "{$eventKey} is missing from {$path}");
        self::assertIsArray($data[$eventKey]['vigil'] ?? null, "{$eventKey} has no vigil readings in {$path}");
        self::assertIsArray($data[$eventKey]['day'] ?? null, "{$eventKey} has no day readings in {$path}");
        self::assertNotSame($data[$eventKey]['vigil'], $data[$eventKey]['day'], "{$eventKey}: a vigil identical to the day proves nothing");

        /** @var array{vigil: array<string, string>, day: array<string, string>} */
        return $data[$eventKey];
    }

    /**
     * @return array<string, array<mixed>>
     */
    private function calendar2026(): array
    {
        if ([] === self::$events) {
            $handler = new CalendarHandler(['2026']);
            $handler->setAllowedReturnTypes([ReturnTypeParam::JSON]);
            $response = $handler->handle($this->requestFor('GET', '/calendar/2026?locale=en', ['Accept' => 'application/json']));
            self::assertSame(200, $response->getStatusCode());

            $body = $this->decodeJsonBody($response);
            self::assertIsArray($body['litcal'] ?? null);
            foreach ($body['litcal'] as $event) {
                self::assertIsArray($event);
                self::assertIsString($event['event_key']);
                self::$events[$event['event_key']] = $event;
            }
        }

        return self::$events;
    }
}
