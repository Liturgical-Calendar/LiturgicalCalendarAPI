<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Enum\LitLocale;
use LiturgicalCalendar\Api\Handlers\CalendarHandler;
use LiturgicalCalendar\Api\Handlers\EventsHandler;
use LiturgicalCalendar\Api\Http\Enum\ReturnTypeParam;
use LiturgicalCalendar\Api\Http\Logs\LoggerFactory;
use LiturgicalCalendar\Api\Models\Calendar\LiturgicalEventCollection;
use LiturgicalCalendar\Api\Models\Lectionary\ReadingsGeneralRoman;
use LiturgicalCalendar\Api\Router;
use LiturgicalCalendar\Api\Services\WiderRegionLayers;
use LiturgicalCalendar\Tests\Services\WiderRegionLayersTest;
use LiturgicalCalendar\Tests\Support\ShadowProjectRootTrait;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(WiderRegionLayers::class)]
#[CoversClass(CalendarHandler::class)]
#[CoversClass(EventsHandler::class)]
final class WiderRegionLayerOrderTest extends AbstractHandlerTestCase
{
    use ShadowProjectRootTrait;

    private static string $root = '';

    /** @var array<string, mixed> */
    private static array $savedServer = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Pin the 'calendar' logger channel to the REAL logs/ folder while Router::$apiFilePath
        // still points there. LoggerFactory memoises both the resolved logs folder and each
        // channel for the whole process; letting CalendarHandler/EventsHandler resolve it later,
        // under the shadow root allocated below, would leave every subsequent test class in this
        // process logging into a directory this class deletes in tearDownAfterClass() (mirrors
        // RegionalDataHandlerTest::setUpBeforeClass()'s pin of the 'audit' channel).
        $realLogs = Router::$apiFilePath . 'logs';
        if (!is_dir($realLogs)) {
            mkdir($realLogs, 0755, true);
        }
        LoggerFactory::create('calendar', $realLogs, 30, false, true, false);

        self::$root          = self::createShadowProjectRoot(Router::$apiFilePath, 'litcal-wider-region-order');
        Router::$apiFilePath = self::$root . DIRECTORY_SEPARATOR;
        WiderRegionLayersTest::writeNordicRegion();
        self::addStEdithSteinToNordicRegion();
        self::seedEuropeStBenedictReading();

        $itFile = strtr(JsonData::NATIONAL_CALENDAR_FILE->path(), ['{nation}' => 'IT']);
        $it     = json_decode((string) file_get_contents($itFile), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($it);
        $it['metadata']['wider_regions'] = ['europe', 'nordic'];
        $it['litcal'][]                  = [
            'liturgical_event' => ['event_key' => 'StBenedict', 'grade' => 4],
            'metadata'         => ['action' => 'makePatron', 'since_year' => 1964, 'url' => 'https://example.test/'],
        ];
        file_put_contents($itFile, json_encode($it, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        $itI18n = strtr(JsonData::NATIONAL_CALENDAR_I18N_FILE->path(), ['{nation}' => 'IT', '{locale}' => 'it_IT']);
        $names  = json_decode((string) file_get_contents($itI18n), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($names);
        $names['StBenedict'] = 'Italian Benedict';
        file_put_contents($itI18n, json_encode($names, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Extends the shared `Nordic` fixture (see {@see WiderRegionLayersTest::writeNordicRegion()}, which
     * declares only `StBenedict` and `StCatherineSiena`) with a third key Europe also declares:
     * `StEdithStein`. Copied from Europe's own row the same way `writeNordicRegion()` copies the other
     * two, and named in Nordic's own `it_IT` i18n file.
     *
     * Needed because `/events` is year-agnostic: it never filters `LitCalItem`s by `since_year`/
     * `until_year`, so IT's OWN national `StCatherineSiena` patronage declaration (real shipped data,
     * `since_year: 1939`, `until_year: 1999`) always re-applies after every wider region layer, on every
     * request, regardless of the year `/calendar` would actually be showing it for — unlike `/calendar`,
     * which resolves precedence for a specific year and lets that declaration lapse by 2024. `StBenedict`
     * is no better a choice here: this same `setUpBeforeClass()` appends IT's own `StBenedict` override
     * below, for {@see self::testTheNationActsAfterEveryRegion()}. `StEdithStein` is the one key Europe
     * declares that IT's national `litcal` never touches, so it is the only one of the three that can
     * isolate "does a later region layer override an earlier one" from "does the nation override every
     * region" for `/events`.
     */
    private static function addStEdithSteinToNordicRegion(): void
    {
        $folder = dirname(strtr(JsonData::WIDER_REGION_FILE->path(), ['{wider_region}' => 'nordic']));

        $nordic = json_decode((string) file_get_contents("{$folder}/nordic.json"), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($nordic);
        $europe = json_decode((string) file_get_contents(strtr(JsonData::WIDER_REGION_FILE->path(), ['{wider_region}' => 'europe'])), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($europe);
        // Europe declares StEdithStein twice (a `createNew` row for the feast day itself, then a
        // `makePatron` row for the "patron of Europe" title, mirroring StBenedict/StCatherineSiena
        // above): copy only the `makePatron` row, so Nordic renames the title rather than
        // re-declaring the feast day's own day/month/grade a second time.
        foreach ($europe['litcal'] as $row) {
            if ($row['liturgical_event']['event_key'] === 'StEdithStein' && $row['metadata']['action'] === 'makePatron') {
                $row['liturgical_event']['grade'] = 5;
                $nordic['litcal'][]               = $row;
                break;
            }
        }
        file_put_contents("{$folder}/nordic.json", json_encode($nordic, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        $names = json_decode((string) file_get_contents("{$folder}/i18n/it_IT.json"), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($names);
        $names['StEdithStein'] = 'Nordic Edith Stein';
        file_put_contents("{$folder}/i18n/it_IT.json", json_encode($names, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Seeds a real `StBenedict` entry into the shadow root's copy of Europe's own `lectionary/it_IT.json` —
     * the exact file `CalendarHandler` reads via `JsonData::WIDER_REGION_LECTIONARY_FILE`.
     *
     * Every wider-region lectionary file shipped today (every region, every locale) carries only
     * empty-string placeholder readings, and the one key Europe's `it_IT.json` does declare
     * (`StEdithStein`) is already declared — with the same empty values — by the GENERAL sanctorale
     * lectionary (`lectionary/sanctorum/it.json`), which loads regardless of any wider region. So a
     * content comparison using only shipped, unmodified data can never distinguish "the region's
     * lectionary loaded" from "it did not" for Italy today — it would pass identically either way,
     * pinning nothing. Seeding a distinguishable entry here, in the shadow copy this test class already
     * builds and already edits (IT's own national file, IT's own i18n), makes the pin real: `StBenedict`
     * carries no lectionary entry of its own before this, IT ships no `lectionary/` folder at all (so
     * nothing else can supply it), and `ReadingsMap::offsetSet()` makes a later-loaded file win — so if
     * `CalendarHandler` truly loads this file for the nation's `Europe` layer, the response's `StBenedict`
     * readings must equal exactly this seeded content; if it does not load, `StBenedict` falls back to the
     * (empty) general sanctorale entry instead.
     */
    private static function seedEuropeStBenedictReading(): void
    {
        $europeLectionaryFile = strtr(
            JsonData::WIDER_REGION_LECTIONARY_FILE->path(),
            ['{wider_region}' => 'europe', '{locale}' => 'it_IT']
        );
        $readings             = json_decode((string) file_get_contents($europeLectionaryFile), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($readings);
        $readings['StBenedict'] = [
            'first_reading'      => 'Pr 2,1-9 (#1005 pin)',
            'responsorial_psalm' => 'Sal 33 (#1005 pin)',
            'gospel_acclamation' => 'Mt 11,29ab (#1005 pin)',
            'gospel'             => 'Mt 19,27-29 (#1005 pin)',
        ];
        file_put_contents($europeLectionaryFile, json_encode($readings, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$root !== '') {
            self::removeTree(self::$root);
            self::$root = '';
        }
        parent::tearDownAfterClass();

        self::resetLectionaryStatic();
    }

    /**
     * `LiturgicalEventCollection::$lectionary` is a `ReadingsGeneralRoman` cached in a static
     * property for the whole PHPUnit process: its constructor only builds one when
     * `!isset(self::$lectionary)`. `seedEuropeStBenedictReading()` above makes `CalendarHandler`
     * merge a synthetic `StBenedict` reading into that shared instance (via
     * `addSanctoraleReadingsFromFile()`), which would otherwise leak into every later test in
     * this process that happens to read `StBenedict`'s sanctorale readings.
     *
     * PHP has no supported way to return a typed static property to its pre-initialization
     * state once set — `unset()` on a static property is a hard `Error` even from the
     * declaring class's own scope, and `ReflectionProperty` has no equivalent (its
     * lazy-object-only raw-value setters explicitly refuse static properties). So this rebuilds
     * a fresh instance the same way `LiturgicalEventCollection`'s own lazy-init does
     * (`new ReadingsGeneralRoman(LitLocale::$PRIMARY_LANGUAGE)`) — run after
     * `parent::tearDownAfterClass()` has restored `Router::$apiFilePath` to the real project
     * root, so the fresh instance reads real, unmodified source data rather than the (by then
     * removed) shadow copy. That is functionally equivalent to a true reset for later tests:
     * the pin is gone, because the rebuilt sanctorale map never references it.
     */
    private static function resetLectionaryStatic(): void
    {
        LiturgicalEventCollection::$lectionary = new ReadingsGeneralRoman(LitLocale::$PRIMARY_LANGUAGE);
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Router::isLocalhost() bypasses the engine cache, which hashes source data but not PHP (see CLAUDE.md).
        self::$savedServer      = $_SERVER;
        $_SERVER['SERVER_NAME'] = 'localhost';
    }

    protected function tearDown(): void
    {
        $_SERVER = self::$savedServer;
        parent::tearDown();
    }

    /** @return array<string, array<string, mixed>> event_key => event */
    private function italy2024(): array
    {
        $handler = new CalendarHandler(['nation', 'IT', '2024']);
        $handler->setAllowedReturnTypes([
            ReturnTypeParam::JSON,
            ReturnTypeParam::YAML,
            ReturnTypeParam::XML,
            ReturnTypeParam::ICS,
        ]);
        $response = $handler->handle($this->requestFor('GET', '/calendar/nation/IT/2024', ['Accept' => 'application/json']));
        self::assertSame(200, $response->getStatusCode());

        $events = [];
        foreach ($this->decodeJsonBody($response)['litcal'] as $event) {
            $events[$event['event_key']] = $event;
        }

        return $events;
    }

    public function testAMoreSpecificRegionActsAfterAMoreGeneralOne(): void
    {
        $catherine = $this->italy2024()['StCatherineSiena'];

        self::assertSame('Nordic Catherine', $catherine['name']);
        self::assertSame(5, $catherine['grade']);
    }

    public function testTheNationActsAfterEveryRegion(): void
    {
        self::assertSame('Italian Benedict', $this->italy2024()['StBenedict']['name']);
    }

    /**
     * Pins that the nation's wider region's OWN lectionary actually loads (#1005): before this fix,
     * `CalendarHandler` substituted the nation's own lectionary file a second time in place of the
     * region's, so a region's sanctorale readings never reached the nation. See
     * {@see self::seedEuropeStBenedictReading()} for why this reads the seeded entry rather than the
     * (currently all-empty) shipped content directly.
     */
    public function testTheNationLoadsItsWiderRegionsLectionary(): void
    {
        $europeLectionaryFile = strtr(
            JsonData::WIDER_REGION_LECTIONARY_FILE->path(),
            ['{wider_region}' => 'europe', '{locale}' => 'it_IT']
        );
        $readings             = json_decode((string) file_get_contents($europeLectionaryFile), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($readings);
        self::assertArrayHasKey('StBenedict', $readings);

        self::assertSame($readings['StBenedict'], $this->italy2024()['StBenedict']['readings']);
    }

    /** @return array<string, array<string, mixed>> event_key => event */
    private function italyEvents(): array
    {
        $response = ( new EventsHandler(['nation', 'IT']) )
            ->handle($this->requestFor('GET', '/events/nation/IT', ['Accept' => 'application/json', 'Accept-Language' => 'it-IT']));
        self::assertSame(200, $response->getStatusCode());

        $events = [];
        foreach ($this->decodeJsonBody($response)['litcal_events'] as $event) {
            $events[$event['event_key']] = $event;
        }

        return $events;
    }

    public function testEventsAppliesEveryRegionInOrder(): void
    {
        self::assertSame('Nordic Edith Stein', $this->italyEvents()['StEdithStein']['name']);
    }
}
