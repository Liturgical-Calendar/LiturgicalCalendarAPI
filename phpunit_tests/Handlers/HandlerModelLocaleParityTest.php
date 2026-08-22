<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Enum\LitLocale;
use LiturgicalCalendar\Api\Handlers\CalendarHandler;
use LiturgicalCalendar\Api\Handlers\EventsHandler;
use LiturgicalCalendar\Api\Http\Enum\ReturnTypeParam;
use LiturgicalCalendar\Api\Models\Calendar\LiturgicalEvent;
use LiturgicalCalendar\Api\Models\EventsPath\LiturgicalEventAbstract;

/**
 * Regression for issue #749, item 2: CalendarHandler and EventsHandler must hand
 * their liturgical-event models the SAME normalized locale.
 *
 * CalendarHandler::prepareL10N() has always passed the resolved runtime locale
 * (LitLocale::$RUNTIME_LOCALE, e.g. 'it_IT', or 'la' for Latin). EventsHandler::setLocale()
 * used to pass the raw EventsParams->Locale instead, which for Latin is 'la_VA'. The
 * models branch on the strict primary-language form ('la') for the Masses for Various
 * Needs commons, so the two handlers disagreed on that path.
 *
 * @see \LiturgicalCalendar\Tests\Models\EventsPath\MassVariousNeedsLatinLocaleTest
 */
final class HandlerModelLocaleParityTest extends AbstractHandlerTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Force Router::isLocalhost() true so CalendarHandler::handle() bypasses the
        // response cache and actually runs prepareL10N().
        $_SERVER['SERVER_NAME'] = 'localhost';
        setlocale(LC_ALL, 'C');
        putenv('LANGUAGE');
    }

    protected function tearDown(): void
    {
        unset($_SERVER['SERVER_NAME']);
        setlocale(LC_ALL, 'C');
        putenv('LANGUAGE');
        // These tests drive the handlers precisely in order to mutate the models'
        // process-global locale statics, then read them back. Restore both class
        // defaults so a later test constructing a model without setting a locale is
        // not silently handed this test's Italian.
        LiturgicalEvent::setLocale(LitLocale::LATIN_PRIMARY_LANGUAGE);
        LiturgicalEventAbstract::setLocale(LitLocale::LATIN_PRIMARY_LANGUAGE);
        parent::tearDown();
    }

    /**
     * Read a model's process-global locale static, which both classes keep non-public.
     *
     * @param class-string $modelClass
     */
    private static function modelLocale(string $modelClass): string
    {
        $property = new \ReflectionProperty($modelClass, 'locale');
        $value    = $property->getValue();
        self::assertIsString($value);
        return $value;
    }

    public function testLatinEventsRequestNormalizesTheModelLocaleToThePrimaryLanguage(): void
    {
        // 'la_VA' is the raw request form (and EventsParams' default). The model must
        // still see 'la', the form its Latin branching tests for.
        $response = ( new EventsHandler() )->handle($this->requestFor('GET', '/events', [])->withQueryParams(['locale' => 'la_VA']));
        self::assertSame(200, $response->getStatusCode());

        self::assertSame(
            LitLocale::LATIN_PRIMARY_LANGUAGE,
            self::modelLocale(LiturgicalEventAbstract::class),
            'EventsHandler must pass the normalized runtime locale to the model, not the raw "la_VA" (#749).'
        );
    }

    public function testBothHandlersAgreeOnTheModelLocaleForLatin(): void
    {
        $eventsResponse = ( new EventsHandler() )->handle($this->requestFor('GET', '/events', [])->withQueryParams(['locale' => 'la_VA']));
        self::assertSame(200, $eventsResponse->getStatusCode());
        $eventsLocale = self::modelLocale(LiturgicalEventAbstract::class);

        $calendar = new CalendarHandler(['nation', 'VA', '2024']);
        $calendar->setAllowedReturnTypes([ReturnTypeParam::JSON]);
        $calendarResponse = $calendar->handle(
            $this->requestFor('GET', '/calendar/nation/VA/2024', ['Accept' => 'application/json'])
        );
        self::assertSame(200, $calendarResponse->getStatusCode());
        $calendarLocale = self::modelLocale(LiturgicalEvent::class);

        self::assertSame($calendarLocale, $eventsLocale, 'The two handlers must configure their models with the same locale (#749).');
    }

    public function testBothHandlersAgreeOnTheModelLocaleForATranslatedLocale(): void
    {
        $calendar = new CalendarHandler(['nation', 'IT', '2024']);
        $calendar->setAllowedReturnTypes([ReturnTypeParam::JSON]);
        $calendarResponse = $calendar->handle(
            $this->requestFor('GET', '/calendar/nation/IT/2024', ['Accept' => 'application/json', 'Accept-Language' => 'it'])
        );
        if (200 !== $calendarResponse->getStatusCode()) {
            self::markTestSkipped('The Italian /calendar request failed (the it_IT system locale is likely not installed on this host).');
        }
        $calendarLocale = self::modelLocale(LiturgicalEvent::class);

        $eventsResponse = ( new EventsHandler() )->handle($this->requestFor('GET', '/events', [])->withQueryParams(['locale' => 'it']));
        self::assertSame(200, $eventsResponse->getStatusCode());
        $eventsLocale = self::modelLocale(LiturgicalEventAbstract::class);

        self::assertSame($calendarLocale, $eventsLocale, 'The two handlers must configure their models with the same locale (#749).');
    }
}
