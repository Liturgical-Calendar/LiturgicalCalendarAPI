<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Handlers\CalendarHandler;
use LiturgicalCalendar\Api\Http\Enum\ReturnTypeParam;
use LiturgicalCalendar\Api\Router;
use LiturgicalCalendar\Api\Services\WiderRegionLayers;
use LiturgicalCalendar\Tests\Services\WiderRegionLayersTest;
use LiturgicalCalendar\Tests\Support\ShadowProjectRootTrait;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(WiderRegionLayers::class)]
#[CoversClass(CalendarHandler::class)]
final class WiderRegionLayerOrderTest extends AbstractHandlerTestCase
{
    use ShadowProjectRootTrait;

    private static string $root = '';

    /** @var array<string, mixed> */
    private static array $savedServer = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$root          = self::createShadowProjectRoot(Router::$apiFilePath, 'litcal-wider-region-order');
        Router::$apiFilePath = self::$root . DIRECTORY_SEPARATOR;
        WiderRegionLayersTest::writeNordicRegion();
        self::seedEuropeStBenedictReading();

        $itFile = strtr(JsonData::NATIONAL_CALENDAR_FILE->path(), ['{nation}' => 'IT']);
        $it     = json_decode((string) file_get_contents($itFile), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($it);
        $it['metadata']['wider_regions'] = ['Europe', 'Nordic'];
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
            ['{wider_region}' => 'Europe', '{locale}' => 'it_IT']
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
            ['{wider_region}' => 'Europe', '{locale}' => 'it_IT']
        );
        $readings             = json_decode((string) file_get_contents($europeLectionaryFile), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($readings);
        self::assertArrayHasKey('StBenedict', $readings);

        self::assertSame($readings['StBenedict'], $this->italy2024()['StBenedict']['readings']);
    }
}
