<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Handlers\CalendarHandler;
use LiturgicalCalendar\Api\Handlers\EventsHandler;
use LiturgicalCalendar\Api\Http\Enum\ReturnTypeParam;
use LiturgicalCalendar\Api\Http\Logs\LoggerFactory;
use LiturgicalCalendar\Api\Router;
use LiturgicalCalendar\Tests\Support\ShadowProjectRootTrait;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * A national calendar is meaningful with settings alone (#990): its events can come later,
 * through the calendar editor or from a national Missal edition. Generating such a calendar
 * must therefore work, with the nation's settings applied over the General Roman Calendar.
 *
 * Italy's calendar is emptied in a shadow copy of the project root: no `litcal` rows, no
 * Missals, and an empty translation map for its one locale — the shape a PUT with
 * `litcal: []` and `i18n: {it_IT: {}}` stores.
 */
#[CoversClass(CalendarHandler::class)]
#[CoversClass(EventsHandler::class)]
final class NationalCalendarWithoutEventsTest extends AbstractHandlerTestCase
{
    use ShadowProjectRootTrait;

    private static string $root = '';

    /** @var array<string, mixed> */
    private static array $savedServer = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Pin the 'calendar' logger to the real logs/ folder before repointing the root
        // (see WiderRegionLayerOrderTest::setUpBeforeClass()).
        $realLogs = Router::$apiFilePath . 'logs';
        if (!is_dir($realLogs)) {
            mkdir($realLogs, 0755, true);
        }
        LoggerFactory::create('calendar', $realLogs, 30, false, true, false);

        self::$root          = self::createShadowProjectRoot(Router::$apiFilePath, 'litcal-national-no-events');
        Router::$apiFilePath = self::$root . DIRECTORY_SEPARATOR;

        $itFile = strtr(JsonData::NATIONAL_CALENDAR_FILE->path(), ['{nation}' => 'IT']);
        $it     = json_decode((string) file_get_contents($itFile), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($it);
        self::assertIsArray($it['metadata']);
        $it['litcal']              = [];
        $it['metadata']['missals'] = [];
        file_put_contents($itFile, json_encode($it, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        $itI18n = strtr(JsonData::NATIONAL_CALENDAR_I18N_FILE->path(), ['{nation}' => 'IT', '{locale}' => 'it_IT']);
        file_put_contents($itI18n, '{}' . PHP_EOL);
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$root !== '') {
            self::removeTree(self::$root);
            self::$root = '';
        }
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Router::isLocalhost() bypasses the engine cache, which hashes source data but not PHP.
        self::$savedServer      = $_SERVER;
        $_SERVER['SERVER_NAME'] = 'localhost';
    }

    protected function tearDown(): void
    {
        $_SERVER = self::$savedServer;
        parent::tearDown();
    }

    public function testACalendarIsGeneratedWithTheNationsSettings(): void
    {
        $handler = new CalendarHandler(['nation', 'IT', '2024']);
        $handler->setAllowedReturnTypes([ReturnTypeParam::JSON]);
        $response = $handler->handle($this->requestFor('GET', '/calendar/nation/IT/2024', ['Accept' => 'application/json']));

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeJsonBody($response);

        self::assertSame('IT', $body['settings']['national_calendar']);
        self::assertSame('JAN6', $body['settings']['epiphany']);
        self::assertSame('SUNDAY', $body['settings']['ascension']);

        $events = [];
        foreach ($body['litcal'] as $event) {
            $events[$event['event_key']] = $event;
        }
        // The nation's settings, not the General Roman defaults, decide the mobile solemnities.
        self::assertSame('2024-01-06T00:00:00+00:00', $events['Epiphany']['date']);
        self::assertSame('2024-05-12T00:00:00+00:00', $events['Ascension']['date']);
    }

    public function testTheEventsCatalogIsServed(): void
    {
        $response = ( new EventsHandler(['nation', 'IT']) )
            ->handle($this->requestFor('GET', '/events/nation/IT', ['Accept' => 'application/json', 'Accept-Language' => 'it-IT']));

        self::assertSame(200, $response->getStatusCode());
        self::assertNotEmpty($this->decodeJsonBody($response)['litcal_events']);
    }
}
