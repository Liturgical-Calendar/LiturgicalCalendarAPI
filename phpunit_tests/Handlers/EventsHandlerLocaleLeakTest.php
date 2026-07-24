<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Handlers\CalendarHandler;
use LiturgicalCalendar\Api\Handlers\EventsHandler;
use LiturgicalCalendar\Api\Http\Enum\ReturnTypeParam;
use Psr\Http\Message\ResponseInterface;

/**
 * Regression for issue #743: EventsHandler::setLocale() leaks the LANGUAGE env var
 * across requests. glibc gettext() reads LANGUAGE above LC_MESSAGES, so a LANGUAGE
 * value left by a prior request in the same persistent worker (e.g. a translated
 * /calendar request, whose CalendarHandler::prepareL10N() sets it) would leak into a
 * later /events response's gettext-backed localized fields (grade_lcl, common_lcl) —
 * the /events analog of #739. Unlike /calendar, /events has no ServerCache
 * short-circuit, so this surfaces on ordinary consecutive requests.
 */
final class EventsHandlerLocaleLeakTest extends AbstractHandlerTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Force Router::isLocalhost() true so CalendarHandler::handle() bypasses the
        // response cache and actually runs prepareL10N() (which sets LANGUAGE) — the
        // pollution the leak requires.
        $_SERVER['SERVER_NAME'] = 'localhost';
        // Start each test from a clean process-global locale so the French baseline
        // below is genuinely French regardless of what earlier tests in this process
        // left behind.
        setlocale(LC_ALL, 'C');
        putenv('LANGUAGE');
    }

    protected function tearDown(): void
    {
        unset($_SERVER['SERVER_NAME']);
        // Never let this test's deliberate locale pollution leak into later tests.
        setlocale(LC_ALL, 'C');
        putenv('LANGUAGE');
        parent::tearDown();
    }

    /**
     * Map each event to its gettext-backed localized fields — the fields that leak.
     *
     * @return array<string,array{grade_lcl:?string,common_lcl:?string}>
     */
    private function localizedFields(ResponseInterface $response): array
    {
        $events = $this->decodeJsonBody($response)['litcal_events'];
        self::assertIsArray($events);
        self::assertNotEmpty($events);

        $out = [];
        foreach ($events as $event) {
            self::assertIsArray($event);
            $out[$event['event_key']] = [
                'grade_lcl'  => $event['grade_lcl'] ?? null,
                'common_lcl' => $event['common_lcl'] ?? null,
            ];
        }
        return $out;
    }

    public function testFrenchEventsKeepTheirLanguageAfterAnItalianRequest(): void
    {
        // Baseline: a French /events request with no prior pollution → French fields.
        $frBaseline = $this->localizedFields(
            ( new EventsHandler() )->handle($this->requestFor('GET', '/events', ['Accept-Language' => 'fr']))
        );

        // Pollute the worker: a translated (Italian) /calendar request leaves
        // LANGUAGE=it_IT:... via CalendarHandler::prepareL10N().
        $itCalendar = new CalendarHandler(['nation', 'IT', '2024']);
        $itCalendar->setAllowedReturnTypes([ReturnTypeParam::JSON]);
        $itResponse = $itCalendar->handle(
            $this->requestFor('GET', '/calendar/nation/IT/2024', ['Accept' => 'application/json', 'Accept-Language' => 'it'])
        );
        self::assertSame(200, $itResponse->getStatusCode());

        // The same French /events request, now in a polluted process, must still be
        // French — not the leaked Italian.
        $frAfter = $this->localizedFields(
            ( new EventsHandler() )->handle($this->requestFor('GET', '/events', ['Accept-Language' => 'fr']))
        );

        self::assertSame($frBaseline, $frAfter, '/events localized fields leaked the prior request language (#743).');
    }

    public function testEnglishEventsKeepTheirLanguageAfterAnItalianRequest(): void
    {
        // English is the source language: it has no gettext catalog of its own and,
        // on hosts where no bare 'en' system locale is installed, setlocale() fails —
        // the very path where a leaked LANGUAGE (from a prior translated request) used
        // to bleed Italian into an English /events response. The fix must degrade to
        // the English msgid base regardless.
        $enBaseline = $this->localizedFields(
            ( new EventsHandler() )->handle($this->requestFor('GET', '/events', ['Accept-Language' => 'en']))
        );

        // Pollute the worker with a translated (Italian) /calendar request.
        $itCalendar = new CalendarHandler(['nation', 'IT', '2024']);
        $itCalendar->setAllowedReturnTypes([ReturnTypeParam::JSON]);
        $itCalendar->handle(
            $this->requestFor('GET', '/calendar/nation/IT/2024', ['Accept' => 'application/json', 'Accept-Language' => 'it'])
        );

        $enAfter = $this->localizedFields(
            ( new EventsHandler() )->handle($this->requestFor('GET', '/events', ['Accept-Language' => 'en']))
        );

        self::assertSame($enBaseline, $enAfter, 'English /events localized fields leaked the prior request language (#743).');
    }

    public function testLatinEventsRequestClearsLeakedLanguage(): void
    {
        // A translated (Italian) /calendar request leaves LANGUAGE=it_IT:... set.
        $itCalendar = new CalendarHandler(['nation', 'IT', '2024']);
        $itCalendar->setAllowedReturnTypes([ReturnTypeParam::JSON]);
        $itCalendar->handle(
            $this->requestFor('GET', '/calendar/nation/IT/2024', ['Accept' => 'application/json', 'Accept-Language' => 'it'])
        );
        self::assertNotFalse(getenv('LANGUAGE'), 'Precondition: the Italian /calendar request should have set LANGUAGE.');

        // A Latin /events request must reset the leaked LANGUAGE so gettext-backed
        // output deterministically falls through to the untranslated base.
        $response = ( new EventsHandler() )->handle(
            $this->requestFor('GET', '/events', ['Accept-Language' => 'la'])
        );
        self::assertSame(200, $response->getStatusCode());
        self::assertFalse(getenv('LANGUAGE'), 'Latin /events must clear the leaked LANGUAGE env var (#743).');
    }
}
