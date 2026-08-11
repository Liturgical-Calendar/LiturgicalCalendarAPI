<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Handlers\CalendarHandler;
use LiturgicalCalendar\Api\Handlers\EventsHandler;
use LiturgicalCalendar\Api\Http\Enum\ReturnTypeParam;
use LiturgicalCalendar\Api\Http\Enum\StatusCode;
use LiturgicalCalendar\Api\Http\Exception\ApiException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Issue #761: the Ambrosian routes must honour the locale set their own `/calendars`
 * metadata declares.
 *
 * The Ambrosian rite has exactly two official liturgical languages — Italian
 * (*Messale Ambrosiano*, 1976; 2nd ed. in force from Advent 2024) and Latin
 * (*Missale Ambrosianum*, 1981, the *editio typica*) — and the metadata says so
 * (`ambrosian_calendars[].locales === ['it', 'la']`, derived from the Ambrosian
 * Proprium de Tempore i18n folder). Before this test existed, the endpoints did not
 * enforce it: `/calendar/ambrosian?locale=nl` returned 200 and echoed `nl_NL` back as
 * the applied locale while silently serving Italian source data.
 *
 * The enforcement is deliberately asymmetric between the two ways an unsupported
 * locale can arrive, and both halves are asserted here:
 *
 * - An explicit `locale` **parameter** is a client assertion about what it wants, so an
 *   unsupported value is a 400 naming the supported set — the same treatment every
 *   other invalid parameter gets.
 * - An **`Accept-Language` header** merely expresses a preference, so it is negotiated
 *   against the rite's locales instead of rejected (RFC 9110 semantics — a browser
 *   pointed at `/calendar/ambrosian` must not get a 400 because of its UI language).
 *   When nothing in the header is acceptable the request falls through to the API-wide
 *   default of Latin, exactly as if no header had been sent.
 */
final class AmbrosianLocaleEnforcementTest extends AbstractHandlerTestCase
{
    private ?string $savedServerName = null;

    /**
     * `CalendarHandler` reads and writes `engineCache/` for every non-localhost request,
     * keyed on `md5(serialize($this->CalendarParams))` — so two tests that build the same
     * parameters share one cache entry, and whichever runs first decides what the other
     * one sees. That entry is written wholesale, including each event's `event_idx`, which
     * comes from a process-lifetime static counter; a test like
     * {@see CalendarHandlerAmbrosianResponseSchemaTest} that resets that counter to keep
     * `event_idx` under the schema's 2000 cap gets its reset silently undone if it is
     * served this class's cached body instead of computing its own.
     *
     * Pinning `SERVER_NAME` to localhost makes `Router::isLocalhost()` true, which is the
     * handler's own "never touch the cache" path, so these tests neither read a stale
     * entry nor leave one behind for anyone else.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->savedServerName  = $_SERVER['SERVER_NAME'] ?? null;
        $_SERVER['SERVER_NAME'] = 'localhost';
    }

    /**
     * Restores `SERVER_NAME`, and resets the process-global locale: `EventsHandler::setLocale()`
     * calls `setlocale(LC_ALL, ...)`, which persists across tests in the same PHPUnit process,
     * and later suites rely on gettext falling through to the untranslated (Latin) msgid rather
     * than being left pinned to a real translated catalog. Mirrors
     * {@see EventsHandlerRiteRoutingTest::tearDown()}.
     */
    protected function tearDown(): void
    {
        if (null === $this->savedServerName) {
            unset($_SERVER['SERVER_NAME']);
        } else {
            $_SERVER['SERVER_NAME'] = $this->savedServerName;
        }
        setlocale(LC_ALL, 'C');
        parent::tearDown();
    }

    /**
     * In-process handler tests call handle() directly, bypassing the PSR-15
     * ErrorHandlingMiddleware that converts an ApiException into an HTTP
     * problem+json response, so catch it here and read its status the way the
     * middleware would. Mirrors {@see CalendarRiteRoutingTest::handle()}.
     *
     * @param string[] $pathParts
     * @param array<string,string> $headers
     * @param array<string,string> $queryParams
     * @return array{status:int,message:string,locale:?string}
     */
    private function calendar(array $pathParts, Rite $rite, string $uri, array $queryParams = [], array $headers = []): array
    {
        $handler = new CalendarHandler($pathParts, $rite);
        $handler->setAllowedReturnTypes([ReturnTypeParam::JSON]);
        $request = $this->requestFor('GET', $uri, $headers)->withQueryParams($queryParams);

        try {
            $response = $handler->handle($request);
        } catch (ApiException $e) {
            return ['status' => $e->getStatus(), 'message' => $e->getMessage(), 'locale' => null];
        }

        $body = $this->decodeJsonBody($response);
        /** @var array{locale?:string} $settings */
        $settings = is_array($body['settings'] ?? null) ? $body['settings'] : [];

        return [
            'status'  => $response->getStatusCode(),
            'message' => '',
            'locale'  => $settings['locale'] ?? null,
        ];
    }

    /**
     * @param string[] $pathParts
     * @param array<string,string> $headers
     * @param array<string,string> $queryParams
     * @return array{status:int,message:string,locale:?string}
     */
    private function events(array $pathParts, Rite $rite, string $uri, array $queryParams = [], array $headers = []): array
    {
        $handler = new EventsHandler($pathParts, $rite);
        $request = $this->requestFor('GET', $uri, $headers)->withQueryParams($queryParams);

        try {
            $response = $handler->handle($request);
        } catch (ApiException $e) {
            return ['status' => $e->getStatus(), 'message' => $e->getMessage(), 'locale' => null];
        }

        $body = $this->decodeJsonBody($response);
        /** @var array{locale?:string} $settings */
        $settings = is_array($body['settings'] ?? null) ? $body['settings'] : [];

        return [
            'status'  => $response->getStatusCode(),
            'message' => '',
            'locale'  => $settings['locale'] ?? null,
        ];
    }

    /**
     * Locales the Ambrosian rite has no liturgical books for. `nl` and `en` are the two
     * named in the issue; `fr` stands in for the rest of the API-wide locale set.
     *
     * @return array<string,array{0:string}>
     */
    public static function unsupportedLocales(): array
    {
        return [
            'Dutch'   => ['nl'],
            'English' => ['en'],
            'French'  => ['fr'],
        ];
    }

    /**
     * The two official Ambrosian liturgical languages, with the runtime locale each
     * resolves to in `settings.locale` (Latin collapses to its primary language `la`,
     * Italian is maximized to `it_IT` — see `LocaleConfigurator`).
     *
     * @return array<string,array{0:string,1:string}>
     */
    public static function supportedLocales(): array
    {
        return [
            'Italian' => ['it', 'it_IT'],
            'Latin'   => ['la', 'la'],
        ];
    }

    #[DataProvider('unsupportedLocales')]
    public function testCalendarComuneRejectsUnsupportedLocaleParamWith400(string $locale): void
    {
        $result = $this->calendar([], Rite::AMBROSIAN, '/calendar/ambrosian', ['locale' => $locale]);

        self::assertSame(StatusCode::BAD_REQUEST->value, $result['status']);
        self::assertStringContainsString('locale', $result['message']);
        self::assertMatchesRegularExpression('/\bit\b/', $result['message'], 'the 400 must name the supported set');
        self::assertMatchesRegularExpression('/\bla\b/', $result['message'], 'the 400 must name the supported set');
    }

    #[DataProvider('supportedLocales')]
    public function testCalendarComuneAcceptsSupportedLocaleParam(string $locale, string $expectedRuntimeLocale): void
    {
        $result = $this->calendar(['2025'], Rite::AMBROSIAN, '/calendar/ambrosian/2025', ['locale' => $locale]);

        self::assertSame(StatusCode::OK->value, $result['status']);
        self::assertSame($expectedRuntimeLocale, $result['locale']);
    }

    /**
     * The diocesan Ambrosian routes declare the same two locales (`it_IT`, `la_VA` for
     * every one of lugano_ch, bergam_it, milano_it, novara_it), so they enforce the same
     * set. Before the fix this route did not lie about the applied locale the way the
     * rite-level one did — `updateSettingsBasedOnDiocesanCalendar()` silently downgraded
     * it to `it_IT` — but silently downgrading an explicit parameter is still not the
     * documented contract for an unsupported parameter value.
     */
    public function testCalendarDioceseRejectsUnsupportedLocaleParamWith400(): void
    {
        $result = $this->calendar(
            ['diocese', 'milano_it'],
            Rite::AMBROSIAN,
            '/calendar/ambrosian/diocese/milano_it',
            ['locale' => 'nl']
        );

        self::assertSame(StatusCode::BAD_REQUEST->value, $result['status']);
    }

    public function testCalendarDioceseAcceptsSupportedLocaleParam(): void
    {
        $result = $this->calendar(
            ['diocese', 'milano_it', '2025'],
            Rite::AMBROSIAN,
            '/calendar/ambrosian/diocese/milano_it/2025',
            ['locale' => 'it']
        );

        self::assertSame(StatusCode::OK->value, $result['status']);
        self::assertSame('it_IT', $result['locale']);
    }

    /**
     * A browser's `Accept-Language` must never turn into a 400: it is negotiated against
     * the rite's locales, and when nothing matches the request falls through to the
     * API-wide Latin default.
     */
    public function testCalendarNegotiatesUnsupportedAcceptLanguageInsteadOfRejecting(): void
    {
        $result = $this->calendar(
            ['2025'],
            Rite::AMBROSIAN,
            '/calendar/ambrosian/2025',
            [],
            ['Accept-Language' => 'nl-NL,nl;q=0.9,en;q=0.8']
        );

        self::assertSame(StatusCode::OK->value, $result['status']);
        self::assertSame('la', $result['locale'], 'unacceptable Accept-Language must degrade to Latin, not be echoed back');
    }

    /**
     * A supported language buried in an otherwise unsupported Accept-Language list must
     * still win — this is negotiation, not an all-or-nothing filter.
     */
    public function testCalendarNegotiatesSupportedAcceptLanguageOutOfAMixedList(): void
    {
        $result = $this->calendar(
            ['2025'],
            Rite::AMBROSIAN,
            '/calendar/ambrosian/2025',
            [],
            ['Accept-Language' => 'nl-NL,nl;q=0.9,it;q=0.7']
        );

        self::assertSame(StatusCode::OK->value, $result['status']);
        self::assertSame('it_IT', $result['locale']);
    }

    /**
     * Regression guard: the Roman rite is unrestricted and must keep accepting every
     * locale the API supports globally. The whole enforcement is rite-scoped.
     */
    #[DataProvider('unsupportedLocales')]
    public function testRomanRiteStillAcceptsEveryApiLocale(string $locale): void
    {
        $result = $this->calendar(['2025'], Rite::ROMAN, '/calendar/2025', ['locale' => $locale]);

        self::assertSame(StatusCode::OK->value, $result['status']);
    }

    #[DataProvider('unsupportedLocales')]
    public function testEventsComuneRejectsUnsupportedLocaleParamWith400(string $locale): void
    {
        $result = $this->events([], Rite::AMBROSIAN, '/events/ambrosian', ['locale' => $locale]);

        self::assertSame(StatusCode::BAD_REQUEST->value, $result['status']);
        self::assertStringContainsString('locale', $result['message']);
    }

    public function testEventsComuneAcceptsSupportedLocaleParam(): void
    {
        $result = $this->events([], Rite::AMBROSIAN, '/events/ambrosian', ['locale' => 'it']);

        self::assertSame(StatusCode::OK->value, $result['status']);
        // `/events` reports the canonicalized request locale as-is (EventsParams does no
        // likely-subtags maximization), unlike `/calendar`'s `it` → `it_IT`.
        self::assertSame('it', $result['locale']);
    }

    public function testEventsDioceseRejectsUnsupportedLocaleParamWith400(): void
    {
        $result = $this->events(
            ['diocese', 'milano_it'],
            Rite::AMBROSIAN,
            '/events/ambrosian/diocese/milano_it',
            ['locale' => 'nl']
        );

        self::assertSame(StatusCode::BAD_REQUEST->value, $result['status']);
    }

    public function testEventsNegotiatesUnsupportedAcceptLanguageInsteadOfRejecting(): void
    {
        $result = $this->events(
            [],
            Rite::AMBROSIAN,
            '/events/ambrosian',
            [],
            ['Accept-Language' => 'nl-NL,nl;q=0.9,en;q=0.8']
        );

        self::assertSame(StatusCode::OK->value, $result['status']);
        // `/events` falls back to the full `la_VA` Latin locale rather than `/calendar`'s
        // runtime-collapsed `la`.
        self::assertSame('la_VA', $result['locale']);
    }

    #[DataProvider('unsupportedLocales')]
    public function testEventsRomanRiteStillAcceptsEveryApiLocale(string $locale): void
    {
        $result = $this->events([], Rite::ROMAN, '/events', ['locale' => $locale]);

        self::assertSame(StatusCode::OK->value, $result['status']);
    }
}
