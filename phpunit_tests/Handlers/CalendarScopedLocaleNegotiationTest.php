<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Handlers\CalendarHandler;
use LiturgicalCalendar\Api\Handlers\EventsHandler;
use LiturgicalCalendar\Api\Http\Enum\ReturnTypeParam;
use LiturgicalCalendar\Api\Http\Enum\StatusCode;
use LiturgicalCalendar\Api\Http\Exception\ApiException;
use LiturgicalCalendar\Api\Http\Negotiator;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Issue #845: an `Accept-Language` **range** must be negotiated against the requested
 * calendar's own declared `metadata->locales`, not matched against them for literal
 * equality after having been negotiated against something else.
 *
 * Canada declares `["en_CA", "fr_CA"]`. Under RFC 4647 §3.3.1 basic filtering the range
 * `fr` matches the tag `fr-CA`, so a French-speaking client sending the plain `fr` range
 * must be served `fr_CA`. Before this fix it was served `en_CA` — a different *language*,
 * not merely a different region of the same one — because the two halves of the decision
 * ran in the wrong order:
 *
 * 1. `handle()` negotiated the header against the *rite's* candidate set, which is empty
 *    for the Roman rite and therefore means "the API-wide locale list". `fr` exists there
 *    as a tag in its own right, and an exact match beats a prefix match, so the header
 *    correctly resolved to `fr`. The requested calendar was not yet known at that point.
 * 2. Once the calendar JSON loaded, the resolved locale was tested for literal membership
 *    in `["en_CA", "fr_CA"]` — which `fr` fails — and silently replaced by `locales[0]`.
 *
 * So the right matching ran against the wrong candidate set, and the right candidate set
 * was applied with the wrong matching. The fix re-runs the *original header* through
 * `Negotiator::pickLanguage()` once the calendar's own locales are known.
 *
 * The re-negotiation is deliberately narrow: it only re-opens the question when the locale
 * already in hand is *not* one of the calendar's declared locales — i.e. exactly where the
 * old code would have thrown it away for `locales[0]`. A locale the calendar does declare is
 * left alone, so the #761 "unacceptable header degrades to the API-wide Latin default"
 * behaviour survives wherever Latin is among the calendar's own locales.
 *
 * The header/parameter asymmetry established by #761 is preserved and asserted here: a
 * header states a *preference* and is negotiated, while an explicit `locale` parameter is
 * an *exact selector* naming a dataset, so `?locale=fr` is NOT widened to `fr_CA`.
 */
final class CalendarScopedLocaleNegotiationTest extends AbstractHandlerTestCase
{
    private ?string $savedServerName = null;

    /**
     * Pin `SERVER_NAME` to localhost so `Router::isLocalhost()` is true and `CalendarHandler`
     * takes its "never touch `engineCache/`" path. The engine cache is keyed on the calendar
     * params (locale included) but is written to a path relative to the process working
     * directory and is blind to PHP source changes, so a cached body from an earlier revision
     * could otherwise answer for the code under test. Mirrors
     * {@see AmbrosianLocaleEnforcementTest::setUp()}.
     */
    protected function setUp(): void
    {
        $this->savedServerName = $_SERVER['SERVER_NAME'] ?? null;

        parent::setUp();
        $_SERVER['SERVER_NAME'] = 'localhost';
    }

    /**
     * `EventsHandler::setLocale()` calls `setlocale(LC_ALL, ...)`, which persists for the
     * lifetime of the PHPUnit process; later suites rely on gettext falling through to the
     * untranslated (Latin) msgid rather than being left pinned to a translated catalog.
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
     * In-process handler tests call `handle()` directly, bypassing the PSR-15
     * ErrorHandlingMiddleware that turns an ApiException into a problem+json response, so
     * catch it here and read its status the way the middleware would.
     *
     * @param string[]             $pathParts
     * @param array<string,string> $queryParams
     * @param array<string,string> $headers
     * @return array{status:int,locale:?string}
     */
    private function events(array $pathParts, Rite $rite, string $uri, array $queryParams = [], array $headers = []): array
    {
        $handler = new EventsHandler($pathParts, $rite);
        $request = $this->requestFor('GET', $uri, $headers)->withQueryParams($queryParams);

        try {
            $response = $handler->handle($request);
        } catch (ApiException $e) {
            return ['status' => $e->getStatus(), 'locale' => null];
        }

        $body = $this->decodeJsonBody($response);
        /** @var array{locale?:string} $settings */
        $settings = is_array($body['settings'] ?? null) ? $body['settings'] : [];

        return ['status' => $response->getStatusCode(), 'locale' => $settings['locale'] ?? null];
    }

    /**
     * @param string[]             $pathParts
     * @param array<string,string> $queryParams
     * @param array<string,string> $headers
     * @return array{status:int,locale:?string}
     */
    private function calendar(array $pathParts, Rite $rite, string $uri, array $queryParams = [], array $headers = []): array
    {
        $handler = new CalendarHandler($pathParts, $rite);
        $handler->setAllowedReturnTypes([ReturnTypeParam::JSON]);
        $request = $this->requestFor('GET', $uri, $headers)->withQueryParams($queryParams);

        try {
            $response = $handler->handle($request);
        } catch (ApiException $e) {
            return ['status' => $e->getStatus(), 'locale' => null];
        }

        $body = $this->decodeJsonBody($response);
        /** @var array{locale?:string} $settings */
        $settings = is_array($body['settings'] ?? null) ? $body['settings'] : [];

        return ['status' => $response->getStatusCode(), 'locale' => $settings['locale'] ?? null];
    }

    /**
     * Every `Accept-Language` shape a Canadian client plausibly sends, with the locale the
     * bilingual Canadian national calendar (`["en_CA", "fr_CA"]`) must resolve it to.
     *
     * @return array<string,array{0:string,1:string}>
     */
    public static function canadianAcceptLanguageHeaders(): array
    {
        return [
            // The bug: a bare range matches `fr_CA` under RFC 4647 basic filtering.
            'bare French range'           => ['fr', 'fr_CA'],
            // Regression guard: the fully-qualified tag already worked.
            'French (Canada), hyphen'     => ['fr-CA', 'fr_CA'],
            'French (Canada), underscore' => ['fr_CA', 'fr_CA'],
            'bare English range'          => ['en', 'en_CA'],
            'English (Canada)'            => ['en-CA', 'en_CA'],
            // What a real French browser sends: `fr-FR` matches nothing Canada declares,
            // but the `fr` range behind it does.
            'French (France) then French' => ['fr-FR,fr;q=0.9', 'fr_CA'],
            // Quality still governs which range is tried first.
            'French outranked by English' => ['fr;q=0.5,en;q=0.9', 'en_CA'],
            // A wildcard takes the calendar's first declared locale.
            'wildcard'                    => ['*', 'en_CA'],
            // Matching nothing the calendar declares lands on the documented default,
            // i.e. `metadata->locales[0]`. `fr-FR` is the sharp case: French, but not a
            // French *range* — RFC 4647 basic filtering does not match it to `fr-CA`.
            'unrelated language'          => ['de', 'en_CA'],
            'French (France) alone'       => ['fr-FR', 'en_CA'],
        ];
    }

    #[DataProvider('canadianAcceptLanguageHeaders')]
    public function testEventsNegotiatesAcceptLanguageAgainstTheNationalCalendarsOwnLocales(string $header, string $expected): void
    {
        $result = $this->events(['nation', 'CA'], Rite::ROMAN, '/events/nation/CA', [], ['Accept-Language' => $header]);

        self::assertSame(StatusCode::OK->value, $result['status']);
        self::assertSame($expected, $result['locale']);
    }

    #[DataProvider('canadianAcceptLanguageHeaders')]
    public function testCalendarNegotiatesAcceptLanguageAgainstTheNationalCalendarsOwnLocales(string $header, string $expected): void
    {
        $result = $this->calendar(
            ['nation', 'CA', '2026'],
            Rite::ROMAN,
            '/calendar/nation/CA/2026',
            [],
            ['Accept-Language' => $header]
        );

        self::assertSame(StatusCode::OK->value, $result['status']);
        self::assertSame($expected, $result['locale']);
    }

    /**
     * The case `CalendarMetadataProvider::negotiableLocalesForRite()`'s workaround was built
     * for, now asserted directly against the diocesan layer instead of indirectly through the
     * shape of the rite-level candidate set.
     *
     * The Ambrosian dioceses declare `["it_IT", "la_VA"]`. `la-VA` already worked, because the
     * rite-level negotiation happened to emit that exact identifier. `la` did not: it is a tag
     * in its own right in the API-wide list, so the rite-level negotiation answered `la`, which
     * then failed the diocesan literal-membership test and silently came back in Italian —
     * exactly the failure mode the workaround's docblock describes, reachable through a
     * different door.
     *
     * @return array<string,array{0:string,1:string,2:string}> Accept-Language, /events locale, /calendar locale
     */
    public static function ambrosianAcceptLanguageHeaders(): array
    {
        return [
            // `/calendar` collapses Latin to its runtime primary language, `/events` does not.
            'bare Latin range'     => ['la', 'la_VA', 'la'],
            'Latin (Vatican)'      => ['la-VA', 'la_VA', 'la'],
            'bare Italian range'   => ['it', 'it_IT', 'it_IT'],
            'Italian (Italy)'      => ['it-IT', 'it_IT', 'it_IT'],
            // The rite has no books for Dutch, so nothing in the header is negotiable at the
            // rite level and the request falls through to the API-wide Latin default (#761).
            // The diocese declares Latin, so that default is already one of its own locales
            // and survives untouched — calendar-scoped negotiation only re-opens the question
            // when the locale in hand is *not* one the calendar declares.
            'unsupported language' => ['nl', 'la_VA', 'la'],
        ];
    }

    #[DataProvider('ambrosianAcceptLanguageHeaders')]
    public function testEventsNegotiatesAcceptLanguageAgainstTheDiocesesOwnLocales(string $header, string $expectedEventsLocale, string $expectedCalendarLocale): void
    {
        $result = $this->events(
            ['diocese', 'milano_it'],
            Rite::AMBROSIAN,
            '/events/ambrosian/diocese/milano_it',
            [],
            ['Accept-Language' => $header]
        );

        self::assertSame(StatusCode::OK->value, $result['status']);
        self::assertSame($expectedEventsLocale, $result['locale']);
    }

    #[DataProvider('ambrosianAcceptLanguageHeaders')]
    public function testCalendarNegotiatesAcceptLanguageAgainstTheDiocesesOwnLocales(string $header, string $expectedEventsLocale, string $expectedCalendarLocale): void
    {
        $result = $this->calendar(
            ['diocese', 'milano_it', '2025'],
            Rite::AMBROSIAN,
            '/calendar/ambrosian/diocese/milano_it/2025',
            [],
            ['Accept-Language' => $header]
        );

        self::assertSame(StatusCode::OK->value, $result['status']);
        self::assertSame($expectedCalendarLocale, $result['locale']);
    }

    /**
     * The header/parameter asymmetry from #761, in the direction #845 must not disturb.
     *
     * An explicit `locale` parameter is a client assertion naming a dataset, and regional
     * calendars store their data under specific regional variants, so `fr` on its own does
     * not name one of Canada's datasets. It therefore keeps its existing exact-match
     * treatment and lands on the calendar's default rather than being widened to `fr_CA` —
     * even though the identical string arriving as an `Accept-Language` *range* now does
     * resolve to `fr_CA`. The two are the same characters and deliberately different things.
     */
    public function testExplicitLocaleParamIsNotWidenedToARegionalVariant(): void
    {
        $events = $this->events(['nation', 'CA'], Rite::ROMAN, '/events/nation/CA', ['locale' => 'fr']);
        self::assertSame(StatusCode::OK->value, $events['status']);
        self::assertSame('en_CA', $events['locale'], 'an explicit `locale` param is an exact selector, not a range');

        $calendar = $this->calendar(['nation', 'CA', '2026'], Rite::ROMAN, '/calendar/nation/CA/2026', ['locale' => 'fr']);
        self::assertSame(StatusCode::OK->value, $calendar['status']);
        self::assertSame('en_CA', $calendar['locale'], 'an explicit `locale` param is an exact selector, not a range');
    }

    /**
     * An explicit parameter must win over the header, and must not be silently re-negotiated
     * back into something the header would have preferred: `?locale=en_CA` alongside
     * `Accept-Language: fr` is English, not French.
     */
    public function testExplicitLocaleParamOverridesTheHeader(): void
    {
        $events = $this->events(
            ['nation', 'CA'],
            Rite::ROMAN,
            '/events/nation/CA',
            ['locale' => 'en_CA'],
            ['Accept-Language' => 'fr']
        );
        self::assertSame(StatusCode::OK->value, $events['status']);
        self::assertSame('en_CA', $events['locale']);

        $calendar = $this->calendar(
            ['nation', 'CA', '2026'],
            Rite::ROMAN,
            '/calendar/nation/CA/2026',
            ['locale' => 'fr_CA'],
            ['Accept-Language' => 'en']
        );
        self::assertSame(StatusCode::OK->value, $calendar['status']);
        self::assertSame('fr_CA', $calendar['locale']);
    }

    /**
     * The ambiguity rule, pinned rather than left emergent (#845 point 5).
     *
     * When a range matches more than one declared locale at the same quality — `fr` against
     * `["fr_CA", "fr_FR"]` — every candidate scores identically (same q, same match
     * specificity, same header index), and `Negotiator::pickLanguage()` keeps the first
     * candidate to reach that score because it replaces its running best only on a strictly
     * greater score. Since it iterates the supported list in the order it was handed, the
     * winner is **the calendar's first declared locale among those that match** — the same
     * declaration order that already decides the `metadata->locales[0]` default. Reversing
     * the declared order reverses the answer.
     *
     * An exact match still outranks any prefix match regardless of order, so a calendar that
     * declared both `fr` and `fr_CA` would serve `fr` for the range `fr`.
     */
    public function testAmbiguousRangeResolvesToTheFirstDeclaredMatchingLocale(): void
    {
        self::assertSame('fr_CA', self::negotiate('fr', ['fr_CA', 'fr_FR']));
        self::assertSame('fr_FR', self::negotiate('fr', ['fr_FR', 'fr_CA']));

        // Exact beats prefix, whichever way round they are declared.
        self::assertSame('fr', self::negotiate('fr', ['fr_CA', 'fr']));
        self::assertSame('fr', self::negotiate('fr', ['fr', 'fr_CA']));
    }

    /**
     * `pickLanguage()` returns null — not the fallback — when a non-empty header matches
     * nothing supported; the fallback argument only covers a *missing* header. Both callers
     * of the new calendar-scoped negotiation therefore have to coalesce, so pin the contract.
     */
    public function testNegotiatorReturnsNullWhenNothingMatchesButFallsBackOnAMissingHeader(): void
    {
        self::assertNull(self::negotiate('de', ['en_CA', 'fr_CA']));
        self::assertSame('en_CA', self::negotiate(null, ['en_CA', 'fr_CA']));
    }

    /**
     * @param string[] $supported
     */
    private static function negotiate(?string $header, array $supported): ?string
    {
        $request = new ServerRequest('GET', '/', null === $header ? [] : ['Accept-Language' => $header]);

        return Negotiator::pickLanguage($request, $supported, $supported[0]);
    }
}
