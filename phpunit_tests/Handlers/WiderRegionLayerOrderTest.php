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
}
