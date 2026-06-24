<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Params;

use LiturgicalCalendar\Api\Enum\LitLocale;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use LiturgicalCalendar\Api\Params\EventsParams;
use LiturgicalCalendar\Api\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EventsParams::class)]
final class EventsParamsTest extends TestCase
{
    private static string $savedApiPath     = '';
    private static string $savedApiFilePath = '';

    public static function setUpBeforeClass(): void
    {
        // EventsParams builds the calendars metadata index in-process from local
        // source data (no HTTP self-call), so pin Router::$apiPath/$apiFilePath
        // to the real project root the way the production Router does, making
        // JsonData::*->path() resolve to the bundled sourcedata.
        self::$savedApiPath     = isset(Router::$apiPath) ? Router::$apiPath : '';
        self::$savedApiFilePath = isset(Router::$apiFilePath) ? Router::$apiFilePath : '';
        Router::$apiPath        = '';
        Router::$apiFilePath    = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;
    }

    public static function tearDownAfterClass(): void
    {
        Router::$apiPath     = self::$savedApiPath;
        Router::$apiFilePath = self::$savedApiFilePath;
    }

    public function testConstructorAppliesDefaults(): void
    {
        $params = new EventsParams();

        self::assertSame((int) date('Y'), $params->Year);
        self::assertFalse($params->EternalHighPriest);
        self::assertNull($params->NationalCalendar);
        self::assertNull($params->DiocesanCalendar);
        self::assertNotEmpty($params->calendarsMetadata->national_calendars_keys);
        // Locale defaults to Latin even when no locale param is supplied, per the
        // documented contract (previously left uninitialized).
        self::assertSame(LitLocale::LATIN, $params->Locale);
        self::assertSame(LitLocale::LATIN_PRIMARY_LANGUAGE, $params->baseLocale);
    }

    public function testLocaleDefaultsToLatinWhenOnlyNonLocaleParamsGiven(): void
    {
        // Regression: a national_calendar (or any non-locale param) without a
        // locale must still leave Locale/baseLocale initialized to the Latin
        // default rather than uninitialized typed properties (an Error on access).
        $params = new EventsParams(['national_calendar' => 'IT']);

        self::assertSame('IT', $params->NationalCalendar);
        self::assertSame(LitLocale::LATIN, $params->Locale);
        self::assertSame(LitLocale::LATIN_PRIMARY_LANGUAGE, $params->baseLocale);
    }

    public function testValidLocaleSplitsLocaleAndBase(): void
    {
        $params = new EventsParams(['locale' => 'en_US']);

        self::assertSame('en_US', $params->Locale);
        self::assertSame('en', $params->baseLocale);
    }

    public function testUnsupportedLocaleIsRejected(): void
    {
        // Regression for #578: EventsParams now matches the rest of the
        // src/Params/ family — an unsupported (but canonicalisable) locale
        // raises ValidationException rather than silently falling back to
        // Latin.
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid value');
        $this->expectExceptionMessage('param `locale`');

        new EventsParams(['locale' => 'not-a-locale']);
    }

    public function testEmptyLocaleStringIsRejected(): void
    {
        // An empty locale is now rejected deterministically by an explicit
        // empty/whitespace guard, independent of the ambient ICU default locale
        // (\Locale::getDefault()), which other requests/tests can mutate via
        // \Locale::setDefault(). Previously this relied on canonicalize('')
        // happening to yield the unsupported 'en_US_POSIX', which made the test
        // order-dependent.
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('param `locale`');

        new EventsParams(['locale' => '']);
    }

    public function testWhitespaceOnlyLocaleStringIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('param `locale`');

        new EventsParams(['locale' => '   ']);
    }

    public function testNationalCalendarFromMetadataIsAccepted(): void
    {
        $params = new EventsParams(['national_calendar' => 'IT']);

        self::assertSame('IT', $params->NationalCalendar);
    }

    public function testNationalCalendarVaForcesLatinAndSkipsAssignment(): void
    {
        // VA is the General Roman Calendar — the branch hard-codes the locale
        // back to Latin and intentionally does not assign NationalCalendar so
        // downstream code treats the request as "no nation override".
        $params = new EventsParams(['national_calendar' => 'VA']);

        self::assertSame(LitLocale::LATIN, $params->Locale);
        self::assertSame(LitLocale::LATIN_PRIMARY_LANGUAGE, $params->baseLocale);
        self::assertFalse($params->EternalHighPriest);
        self::assertNull($params->NationalCalendar);
    }

    /**
     * Regression for #576: when national_calendar=VA appears anywhere in the
     * input, its locale=la_VA / eternal_high_priest=false invariants must win
     * regardless of where it sits relative to sibling keys.
     */
    public function testNationalCalendarVaOverridesEvenWhenItComesFirst(): void
    {
        $params = new EventsParams([
            'national_calendar'   => 'VA',
            'eternal_high_priest' => true,
            'locale'              => 'en_US',
        ]);

        self::assertSame(LitLocale::LATIN, $params->Locale);
        self::assertSame(LitLocale::LATIN_PRIMARY_LANGUAGE, $params->baseLocale);
        self::assertFalse($params->EternalHighPriest);
        self::assertNull($params->NationalCalendar);
    }

    public function testNationalCalendarVaOverridesWhenItComesLast(): void
    {
        $params = new EventsParams([
            'eternal_high_priest' => true,
            'locale'              => 'en_US',
            'national_calendar'   => 'VA',
        ]);

        self::assertSame(LitLocale::LATIN, $params->Locale);
        self::assertSame(LitLocale::LATIN_PRIMARY_LANGUAGE, $params->baseLocale);
        self::assertFalse($params->EternalHighPriest);
        self::assertNull($params->NationalCalendar);
    }

    public function testInvalidSiblingValuesStillRejectedEvenWhenVaIsSet(): void
    {
        // VA only overrides the *successfully* parsed sibling values; it does
        // not silence input validation for malformed siblings.
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('eternal_high_priest');

        new EventsParams([
            'national_calendar'   => 'VA',
            'eternal_high_priest' => 'maybe', // @phpstan-ignore-line
        ]);
    }

    public function testRepeatedSetParamsVaClearsPreviousNationalCalendar(): void
    {
        // setParams() is public; switching from a real nation to VA on the same
        // instance must clear NationalCalendar so the post-loop VA invariants
        // fully describe the request shape (no stale nation override left over).
        $params = new EventsParams(['national_calendar' => 'IT']);
        self::assertSame('IT', $params->NationalCalendar);

        $params->setParams(['national_calendar' => 'VA']);

        self::assertNull($params->NationalCalendar);
        self::assertSame(LitLocale::LATIN, $params->Locale);
        self::assertSame(LitLocale::LATIN_PRIMARY_LANGUAGE, $params->baseLocale);
        self::assertFalse($params->EternalHighPriest);
    }

    public function testUnknownNationalCalendarIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('nation parameter');

        new EventsParams(['national_calendar' => 'ZZ']);
    }

    public function testDiocesanCalendarFromMetadataIsAccepted(): void
    {
        $params = new EventsParams(['diocesan_calendar' => 'romamo_it']);

        self::assertSame('romamo_it', $params->DiocesanCalendar);
    }

    public function testUnknownDiocesanCalendarIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('diocese parameter');

        new EventsParams(['diocesan_calendar' => 'nowhere']);
    }

    public function testNonStringLocaleIsRejectedAsValidationError(): void
    {
        // A non-string locale (e.g. ?locale[]=en in a POST body) must surface as
        // a 400 ValidationException, not a 500 TypeError from string operations.
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('locale');

        new EventsParams(['locale' => ['en']]); // @phpstan-ignore-line
    }

    public function testNonStringNationalCalendarIsRejectedAsValidationError(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('national_calendar');

        new EventsParams(['national_calendar' => ['IT']]); // @phpstan-ignore-line
    }

    public function testNonStringDiocesanCalendarIsRejectedAsValidationError(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('diocesan_calendar');

        new EventsParams(['diocesan_calendar' => ['romamo_it']]); // @phpstan-ignore-line
    }

    public function testEternalHighPriestAcceptsBooleanish(): void
    {
        $params = new EventsParams(['eternal_high_priest' => 'true']); // @phpstan-ignore-line

        self::assertTrue($params->EternalHighPriest);
    }

    public function testEternalHighPriestRejectsGarbage(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('eternal_high_priest');

        new EventsParams(['eternal_high_priest' => 'maybe']); // @phpstan-ignore-line
    }

    public function testUnknownKeysAreSilentlyIgnored(): void
    {
        $params = new EventsParams(['locale' => 'en_US', 'noise' => 'whatever']); // @phpstan-ignore-line

        self::assertSame('en_US', $params->Locale);
    }

    public function testNationalCalendarValueIsUppercased(): void
    {
        // Only valid values reach the strtoupper branch, so we verify by passing
        // 'IT' and confirming the stored value equals strtoupper('IT'). The
        // metadata fixture only exposes 'IT' and 'US' as valid keys.
        $params = new EventsParams(['national_calendar' => 'IT']);

        self::assertSame('IT', $params->NationalCalendar);
    }
}
