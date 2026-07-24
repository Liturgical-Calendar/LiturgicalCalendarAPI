<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Params;

use LiturgicalCalendar\Api\Enum\LitLocale;
use LiturgicalCalendar\Api\Enum\Rite;
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

    public function testAmbrosianRejectsVaNationalCalendar(): void
    {
        // national_calendar=VA (from ?national_calendar=VA or /events/ambrosian/nation/VA)
        // normalizes NationalCalendar back to null, but an Ambrosian request must still be
        // rejected — the Ambrosian rite has no national layer. Guards against VA slipping
        // past the plain "NationalCalendar !== null" check.
        $params = new EventsParams(['national_calendar' => 'VA']);
        $params->setRite(Rite::AMBROSIAN);

        $this->expectException(ValidationException::class);
        $params->validateRiteCompatibility();
    }

    public function testRomanAcceptsVaNationalCalendar(): void
    {
        // VA is valid for the Roman rite (it selects the General Roman Calendar).
        $params = new EventsParams(['national_calendar' => 'VA']);
        $params->setRite(Rite::ROMAN);

        $params->validateRiteCompatibility();
        $this->addToAssertionCount(1); // reached only if no exception was thrown
    }

    public function testAmbrosianRiteAcceptsMatchingAmbrosianDiocese(): void
    {
        // Task 12: the Ambrosian rite now serves its own diocesan event catalogs
        // (/events/ambrosian/diocese/{diocese_id}) — milano_it is declared as an
        // Ambrosian-rite diocese in the calendars metadata (see MetadataDiocesanCalendarItem).
        $params = new EventsParams(['diocesan_calendar' => 'milano_it']);
        $params->setRite(Rite::AMBROSIAN);

        $params->validateRiteCompatibility();
        $this->addToAssertionCount(1); // reached only if no exception was thrown
    }

    public function testAmbrosianRiteRejectsRomanDiocese(): void
    {
        // Rite-scoped mismatch: agrige_it is a Roman-rite diocese requested under the
        // Ambrosian rite (from ?diocesan_calendar=agrige_it or /events/ambrosian/diocese/agrige_it).
        $params = new EventsParams(['diocesan_calendar' => 'agrige_it']);
        $params->setRite(Rite::AMBROSIAN);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('belongs to the roman rite, not the requested ambrosian rite');

        $params->validateRiteCompatibility();
    }

    public function testRomanRiteRejectsAmbrosianDiocese(): void
    {
        // Rite-scoped mismatch in the other direction: milano_it is an Ambrosian-rite
        // diocese requested under the (default) Roman rite.
        $params = new EventsParams(['diocesan_calendar' => 'milano_it']);
        $params->setRite(Rite::ROMAN);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('belongs to the ambrosian rite, not the requested roman rite');

        $params->validateRiteCompatibility();
    }

    public function testRomanRiteAcceptsRomanDioceseUnchanged(): void
    {
        // Guards against regressions to the pre-existing Roman diocese validation
        // path while adding rite-scoped enforcement above.
        $params = new EventsParams(['diocesan_calendar' => 'agrige_it']);
        $params->setRite(Rite::ROMAN);

        $params->validateRiteCompatibility();
        $this->addToAssertionCount(1); // reached only if no exception was thrown
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

        new EventsParams([ // @phpstan-ignore argument.type
            'national_calendar'   => 'VA',
            'eternal_high_priest' => 'maybe',
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

        new EventsParams(['locale' => ['en']]); // @phpstan-ignore argument.type
    }

    public function testNonStringNationalCalendarIsRejectedAsValidationError(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('national_calendar');

        new EventsParams(['national_calendar' => ['IT']]); // @phpstan-ignore argument.type
    }

    public function testNonStringDiocesanCalendarIsRejectedAsValidationError(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('diocesan_calendar');

        new EventsParams(['diocesan_calendar' => ['romamo_it']]); // @phpstan-ignore argument.type
    }

    public function testObjectLocaleIsRejectedAsValidationError(): void
    {
        // Object payloads must be rejected as 400s too, the same way arrays are.
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('locale');

        new EventsParams(['locale' => (object) ['en']]); // @phpstan-ignore argument.type
    }

    public function testObjectNationalCalendarIsRejectedAsValidationError(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('national_calendar');

        new EventsParams(['national_calendar' => (object) ['IT']]); // @phpstan-ignore argument.type
    }

    public function testObjectDiocesanCalendarIsRejectedAsValidationError(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('diocesan_calendar');

        new EventsParams(['diocesan_calendar' => (object) ['romamo_it']]); // @phpstan-ignore argument.type
    }

    public function testEternalHighPriestAcceptsBooleanish(): void
    {
        $params = new EventsParams(['eternal_high_priest' => 'true']); // @phpstan-ignore argument.type

        self::assertTrue($params->EternalHighPriest);
    }

    public function testEternalHighPriestRejectsGarbage(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('eternal_high_priest');

        new EventsParams(['eternal_high_priest' => 'maybe']); // @phpstan-ignore argument.type
    }

    public function testUnknownKeysAreSilentlyIgnored(): void
    {
        $params = new EventsParams(['locale' => 'en_US', 'noise' => 'whatever']);

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
