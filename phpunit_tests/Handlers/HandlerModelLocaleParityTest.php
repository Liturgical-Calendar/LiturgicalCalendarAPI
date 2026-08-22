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
    private string $savedLocale              = 'C';
    private ?string $savedLanguageEnv        = null;
    private string $savedIcuDefault          = 'en';
    private string $savedPrimaryLanguage     = LitLocale::LATIN_PRIMARY_LANGUAGE;
    private string $savedRuntimeLocale       = 'en_US';
    private bool $hadServerName              = false;
    private ?string $savedServerName         = null;
    private string $savedCalendarModelLocale = LitLocale::LATIN_PRIMARY_LANGUAGE;
    private string $savedEventsModelLocale   = LitLocale::LATIN_PRIMARY_LANGUAGE;

    protected function setUp(): void
    {
        // Snapshot every process-global bit of state this test (and the handlers it
        // invokes) mutates, so tearDown() restores the world as it was found rather
        // than imposing its own opinion of the baseline on later tests.
        //
        // Taken BEFORE parent::setUp() deliberately: that call can markTestSkipped()
        // when JWT_SECRET is absent, and PHPUnit still runs tearDown() after a skip in
        // setUp(). A snapshot taken after it would leave tearDown() restoring values it
        // never captured.
        $this->savedLocale          = setlocale(LC_ALL, 0) ?: 'C';
        $languageEnv                = getenv('LANGUAGE');
        $this->savedLanguageEnv     = false === $languageEnv ? null : $languageEnv;
        $this->savedIcuDefault      = \Locale::getDefault();
        $this->savedPrimaryLanguage = LitLocale::$PRIMARY_LANGUAGE;
        $this->savedRuntimeLocale   = LitLocale::$RUNTIME_LOCALE;
        $this->hadServerName        = array_key_exists('SERVER_NAME', $_SERVER);
        $this->savedServerName      = $this->hadServerName ? (string) $_SERVER['SERVER_NAME'] : null;

        // The models' locale statics too: these tests drive the handlers precisely in
        // order to mutate them, then read them back.
        $this->savedCalendarModelLocale = self::modelLocale(LiturgicalEvent::class);
        $this->savedEventsModelLocale   = self::modelLocale(LiturgicalEventAbstract::class);

        parent::setUp();

        // Force Router::isLocalhost() true so CalendarHandler::handle() bypasses the
        // response cache and actually runs prepareL10N().
        $_SERVER['SERVER_NAME'] = 'localhost';

        // Start from a known-clean process-global locale, whatever an earlier test in
        // this process left behind.
        setlocale(LC_ALL, 'C');
        putenv('LANGUAGE');
    }

    protected function tearDown(): void
    {
        if ($this->hadServerName) {
            $_SERVER['SERVER_NAME'] = $this->savedServerName;
        } else {
            unset($_SERVER['SERVER_NAME']);
        }
        setlocale(LC_ALL, $this->savedLocale);
        putenv(null === $this->savedLanguageEnv ? 'LANGUAGE' : 'LANGUAGE=' . $this->savedLanguageEnv);
        \Locale::setDefault($this->savedIcuDefault);
        LitLocale::$PRIMARY_LANGUAGE = $this->savedPrimaryLanguage;
        LitLocale::$RUNTIME_LOCALE   = $this->savedRuntimeLocale;

        // Restore the models' locale statics to what they held on entry, so a later
        // test constructing a model without setting a locale is not silently handed
        // this test's Italian. Restored via setLocale() rather than by writing the
        // static directly: LiturgicalEvent derives four IntlDateFormatters from the
        // locale, which must stay consistent with it.
        LiturgicalEvent::setLocale($this->savedCalendarModelLocale);
        LiturgicalEventAbstract::setLocale($this->savedEventsModelLocale);

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
        // Probe the precondition directly instead of inferring it from a status code.
        // A missing Italian locale makes LocaleConfigurator::configure() THROW
        // ServiceUnavailableException, and the middleware that would render that as a
        // 503 is not in the in-process handler path — so the case this skip exists for
        // never produces a status code at all, while "skip on any non-200" would
        // silently swallow a genuine 404/500 regression. The candidates mirror the ones
        // LocaleConfigurator itself tries for 'it'.
        $italian = setlocale(LC_ALL, 'it_IT.utf8', 'it_IT.UTF-8', 'it_IT', 'it.utf8', 'it.UTF-8', 'it');
        setlocale(LC_ALL, 'C');
        if (false === $italian) {
            self::markTestSkipped('No Italian system locale is installed on this host.');
        }

        $calendar = new CalendarHandler(['nation', 'IT', '2024']);
        $calendar->setAllowedReturnTypes([ReturnTypeParam::JSON]);
        $calendarResponse = $calendar->handle(
            $this->requestFor('GET', '/calendar/nation/IT/2024', ['Accept' => 'application/json', 'Accept-Language' => 'it'])
        );
        self::assertSame(200, $calendarResponse->getStatusCode());
        $calendarLocale = self::modelLocale(LiturgicalEvent::class);

        $eventsResponse = ( new EventsHandler() )->handle($this->requestFor('GET', '/events', [])->withQueryParams(['locale' => 'it']));
        self::assertSame(200, $eventsResponse->getStatusCode());
        $eventsLocale = self::modelLocale(LiturgicalEventAbstract::class);

        self::assertSame($calendarLocale, $eventsLocale, 'The two handlers must configure their models with the same locale (#749).');
    }
}
