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
 * Issue #1063: a calendar may declare a language-only locale in `metadata.locales`, and an
 * explicit `locale` parameter naming it must select it.
 *
 * `CalendarParams` expands a language-only tag to its likely region (`nl` → `nl_NL`), so the
 * exact match in `CalendarHandler::resolveCalendarLocale()` used to miss a declared bare `nl`
 * and fall back to `locales[0]`. The parameter is still an exact selector (#761): `nl_BE`,
 * which the calendar does not declare, keeps landing on `locales[0]`. `EventsParams` never
 * expanded the tag, so `/events` already behaved; its cases keep the two routes in step.
 *
 * `metadata.locales` is sorted on load, so `locales[0]` is `it_IT` only because it sorts
 * before `nl`; and the response reports the system locale applied (`nl` → `nl_NL`), not the
 * dataset's name, so the assertions compare languages.
 *
 * Italy's calendar is given `["it_IT", "nl"]` in a shadow copy of the project root, emptied
 * of events, Missals and its wider region as in {@see NationalCalendarWithoutEventsTest}, so
 * that no per-locale data beyond the translation maps is needed.
 *
 * A diocese is resolved after its nation, which may already have replaced the requested locale
 * with one of its own. So Rotterdam is given `["nl_NL", "en"]` under a Netherlands that declares
 * only `nl_NL` (diocesan locales keep their declared order): `locale=en` must still select `en`,
 * not the `nl_NL` the national layer left behind.
 */
#[CoversClass(CalendarHandler::class)]
#[CoversClass(EventsHandler::class)]
final class BareLanguageLocaleSelectionTest extends AbstractHandlerTestCase
{
    use ShadowProjectRootTrait;

    private static string $root = '';

    /** @var array<string, mixed> */
    private static array $savedServer = [];

    private string|false $savedLocale = false;

    /**
     * Read a JSON object from the shadow tree. Throws rather than asserting: this runs in
     * setUpBeforeClass(), where a PHPUnit failure crashes the PHPUnit 12 runner.
     *
     * @return array<string, mixed>
     */
    private static function readJsonObject(string $file): array
    {
        $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data) || !is_array($data['metadata'] ?? null)) {
            throw new \RuntimeException("Expected a JSON object with a metadata object in {$file}");
        }
        /** @var array<string, mixed> $data */
        return $data;
    }

    private static function writeJson(string $file, mixed $data): void
    {
        file_put_contents($file, json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

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

        self::$root          = self::createShadowProjectRoot(Router::$apiFilePath, 'litcal-bare-language-locale');
        Router::$apiFilePath = self::$root . DIRECTORY_SEPARATOR;

        $itFile                    = strtr(JsonData::NATIONAL_CALENDAR_FILE->path(), ['{nation}' => 'IT']);
        $it                        = self::readJsonObject($itFile);
        $it['litcal']              = [];
        $it['metadata']['missals'] = [];
        $it['metadata']['locales'] = ['it_IT', 'nl'];
        // Europe's layer has no bare `nl` dataset, and wider regions are not under test here.
        $it['metadata']['wider_regions'] = [];
        self::writeJson($itFile, $it);

        foreach (['it_IT', 'nl'] as $locale) {
            $i18nFile = strtr(JsonData::NATIONAL_CALENDAR_I18N_FILE->path(), ['{nation}' => 'IT', '{locale}' => $locale]);
            file_put_contents($i18nFile, '{}' . PHP_EOL);
        }

        // The Netherlands keeps its one locale, nl_NL, but loses its events, Missal and wider
        // region, none of which has an `en` dataset; Rotterdam gains `en`, a copy of its Dutch data.
        $nlFile         = strtr(JsonData::NATIONAL_CALENDAR_FILE->path(), ['{nation}' => 'NL']);
        $nl             = self::readJsonObject($nlFile);
        $nl['litcal']   = [];
        $nl['metadata'] = array_merge((array) $nl['metadata'], ['missals' => [], 'wider_regions' => []]);
        self::writeJson($nlFile, $nl);
        $nlNational = dirname($nlFile);
        file_put_contents($nlNational . '/i18n/nl_NL.json', '{}' . PHP_EOL);
        file_put_contents($nlNational . '/i18n/en.json', '{}' . PHP_EOL);

        $rotterdam       = JsonData::DIOCESAN_CALENDARS_FOLDER->path() . '/NL/rotter_nl';
        $rotterdamFile   = $rotterdam . '/Rotterdam.json';
        $rot             = self::readJsonObject($rotterdamFile);
        $rot['metadata'] = array_merge((array) $rot['metadata'], ['locales' => ['nl_NL', 'en']]);
        self::writeJson($rotterdamFile, $rot);
        foreach (['i18n', 'lectionary'] as $folder) {
            if (!copy("{$rotterdam}/{$folder}/nl_NL.json", "{$rotterdam}/{$folder}/en.json")) {
                throw new \RuntimeException("Could not copy Rotterdam's {$folder}/nl_NL.json");
            }
        }
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
        // The handlers call setlocale(), which outlives the test.
        $this->savedLocale = setlocale(LC_ALL, 0);
    }

    protected function tearDown(): void
    {
        $_SERVER = self::$savedServer;
        setlocale(LC_ALL, false !== $this->savedLocale ? $this->savedLocale : 'C');
        parent::tearDown();
    }

    /**
     * @param string[] $pathParts
     */
    private function calendarLanguage(string $locale, array $pathParts = ['nation', 'IT', '2024']): ?string
    {
        $handler = new CalendarHandler($pathParts);
        $handler->setAllowedReturnTypes([ReturnTypeParam::JSON]);
        $request  = $this->requestFor('GET', '/calendar/' . implode('/', $pathParts), ['Accept' => 'application/json'])
            ->withQueryParams(['locale' => $locale]);
        $response = $handler->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeJsonBody($response);
        /** @var array{locale?:string} $settings */
        $settings = is_array($body['settings'] ?? null) ? $body['settings'] : [];
        return isset($settings['locale']) ? \Locale::getPrimaryLanguage($settings['locale']) : null;
    }

    private function eventsLanguage(string $locale): ?string
    {
        $request  = $this->requestFor('GET', '/events/nation/IT', ['Accept' => 'application/json'])
            ->withQueryParams(['locale' => $locale]);
        $response = ( new EventsHandler(['nation', 'IT']) )->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeJsonBody($response);
        /** @var array{locale?:string} $settings */
        $settings = is_array($body['settings'] ?? null) ? $body['settings'] : [];
        return isset($settings['locale']) ? \Locale::getPrimaryLanguage($settings['locale']) : null;
    }

    public function testCalendarSelectsADeclaredBareLanguageLocale(): void
    {
        self::assertSame('nl', $this->calendarLanguage('nl'));
    }

    public function testCalendarStillTreatsAnUndeclaredRegionalLocaleAsAnExactSelector(): void
    {
        self::assertSame('it', $this->calendarLanguage('nl_BE'));
    }

    public function testDiocesanCalendarSelectsItsBareLanguageLocaleAfterTheNationResolvedAnother(): void
    {
        self::assertSame('en', $this->calendarLanguage('en', ['diocese', 'rotter_nl', '2024']));
    }

    public function testEventsSelectsADeclaredBareLanguageLocale(): void
    {
        self::assertSame('nl', $this->eventsLanguage('nl'));
    }

    public function testEventsStillTreatsAnUndeclaredRegionalLocaleAsAnExactSelector(): void
    {
        self::assertSame('it', $this->eventsLanguage('nl_BE'));
    }
}
