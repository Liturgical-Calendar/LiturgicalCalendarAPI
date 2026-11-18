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
 */
#[CoversClass(CalendarHandler::class)]
#[CoversClass(EventsHandler::class)]
final class BareLanguageLocaleSelectionTest extends AbstractHandlerTestCase
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

        self::$root          = self::createShadowProjectRoot(Router::$apiFilePath, 'litcal-bare-language-locale');
        Router::$apiFilePath = self::$root . DIRECTORY_SEPARATOR;

        $itFile = strtr(JsonData::NATIONAL_CALENDAR_FILE->path(), ['{nation}' => 'IT']);
        $it     = json_decode((string) file_get_contents($itFile), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($it);
        self::assertIsArray($it['metadata']);
        $it['litcal']              = [];
        $it['metadata']['missals'] = [];
        $it['metadata']['locales'] = ['it_IT', 'nl'];
        // Europe's layer has no bare `nl` dataset, and wider regions are not under test here.
        $it['metadata']['wider_regions'] = [];
        file_put_contents($itFile, json_encode($it, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        foreach (['it_IT', 'nl'] as $locale) {
            $i18nFile = strtr(JsonData::NATIONAL_CALENDAR_I18N_FILE->path(), ['{nation}' => 'IT', '{locale}' => $locale]);
            file_put_contents($i18nFile, '{}' . PHP_EOL);
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
    }

    protected function tearDown(): void
    {
        $_SERVER = self::$savedServer;
        // EventsHandler::setLocale() calls setlocale(), which outlives the test.
        setlocale(LC_ALL, 'C');
        parent::tearDown();
    }

    private function calendarLanguage(string $locale): ?string
    {
        $handler = new CalendarHandler(['nation', 'IT', '2024']);
        $handler->setAllowedReturnTypes([ReturnTypeParam::JSON]);
        $request  = $this->requestFor('GET', '/calendar/nation/IT/2024', ['Accept' => 'application/json'])
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

    public function testEventsSelectsADeclaredBareLanguageLocale(): void
    {
        self::assertSame('nl', $this->eventsLanguage('nl'));
    }

    public function testEventsStillTreatsAnUndeclaredRegionalLocaleAsAnExactSelector(): void
    {
        self::assertSame('it', $this->eventsLanguage('nl_BE'));
    }
}
