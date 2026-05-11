<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Params;

use LiturgicalCalendar\Api\Enum\Ascension;
use LiturgicalCalendar\Api\Enum\CorpusChristi;
use LiturgicalCalendar\Api\Enum\Epiphany;
use LiturgicalCalendar\Api\Enum\LitLocale;
use LiturgicalCalendar\Api\Enum\YearType;
use LiturgicalCalendar\Api\Http\Enum\ReturnTypeParam;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use LiturgicalCalendar\Api\Params\CalendarParams;
use LiturgicalCalendar\Api\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CalendarParams::class)]
final class CalendarParamsTest extends TestCase
{
    private static string $savedApiPath = '';

    public static function setUpBeforeClass(): void
    {
        // Stand in a file:// URL pointing at the bundled metadata fixture so
        // the constructor's HTTP fetch resolves locally and deterministically.
        self::$savedApiPath = Router::$apiPath ?? '';
        Router::$apiPath    = 'file://' . realpath(__DIR__ . '/../fixtures/api');
    }

    public static function tearDownAfterClass(): void
    {
        Router::$apiPath = self::$savedApiPath;
    }

    public function testConstructorAppliesDefaults(): void
    {
        $params = new CalendarParams();

        self::assertSame((int) date('Y'), $params->Year);
        self::assertSame(LitLocale::LATIN, $params->Locale);
        self::assertSame(YearType::LITURGICAL, $params->YearType);
        self::assertSame(Epiphany::JAN6, $params->Epiphany);
        self::assertSame(Ascension::THURSDAY, $params->Ascension);
        self::assertSame(CorpusChristi::THURSDAY, $params->CorpusChristi);
        self::assertNull($params->ReturnType);
        self::assertNull($params->NationalCalendar);
        self::assertNull($params->DiocesanCalendar);
        self::assertFalse($params->EternalHighPriest);
    }

    public function testEmptyParamsLeavesDefaultsUnchanged(): void
    {
        $params = new CalendarParams();
        $params->setParams([]);

        self::assertSame((int) date('Y'), $params->Year);
    }

    public function testValidYearAsIntegerIsApplied(): void
    {
        $params = new CalendarParams();
        $params->setParams(['year' => 2030]);

        self::assertSame(2030, $params->Year);
    }

    public function testValidYearAsFourDigitStringIsApplied(): void
    {
        $params = new CalendarParams();
        $params->setParams(['year' => '2025']);

        self::assertSame(2025, $params->Year);
    }

    public function testYearStringWithWrongLengthIsRejected(): void
    {
        $params = new CalendarParams();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('numeric String with 4 digits');

        $params->setParams(['year' => '202']);
    }

    public function testYearBelowLowerLimitIsRejected(): void
    {
        $params = new CalendarParams();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('out of bounds');

        $params->setParams(['year' => 1969]);
    }

    public function testYearAboveUpperLimitIsRejected(): void
    {
        $params = new CalendarParams();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('out of bounds');

        $params->setParams(['year' => 10000]);
    }

    public function testEpiphanyAscensionAndCorpusChristiAreApplied(): void
    {
        $params = new CalendarParams();
        $params->setParams([
            'epiphany'       => 'SUNDAY_JAN2_JAN8',
            'ascension'      => 'SUNDAY',
            'corpus_christi' => 'SUNDAY',
        ]);

        self::assertSame(Epiphany::SUNDAY_JAN2_JAN8, $params->Epiphany);
        self::assertSame(Ascension::SUNDAY, $params->Ascension);
        self::assertSame(CorpusChristi::SUNDAY, $params->CorpusChristi);
    }

    public function testInvalidEpiphanyIsRejected(): void
    {
        $params = new CalendarParams();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('parameter `epiphany`');

        $params->setParams(['epiphany' => 'NOPE']);
    }

    public function testInvalidAscensionIsRejected(): void
    {
        $params = new CalendarParams();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('parameter `ascension`');

        $params->setParams(['ascension' => 'NOPE']);
    }

    public function testInvalidCorpusChristiIsRejected(): void
    {
        $params = new CalendarParams();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('parameter `corpus_christi`');

        $params->setParams(['corpus_christi' => 'NOPE']);
    }

    public function testYearTypeIsApplied(): void
    {
        $params = new CalendarParams();
        $params->setParams(['year_type' => 'CIVIL']);

        self::assertSame(YearType::CIVIL, $params->YearType);
    }

    public function testInvalidYearTypeIsRejected(): void
    {
        $params = new CalendarParams();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('parameter `year_type`');

        $params->setParams(['year_type' => 'lunar']);
    }

    public function testEternalHighPriestAcceptsBoolean(): void
    {
        $params = new CalendarParams();
        $params->setParams(['eternal_high_priest' => true]);

        self::assertTrue($params->EternalHighPriest);
    }

    public function testEternalHighPriestAcceptsBooleanishString(): void
    {
        $params = new CalendarParams();
        $params->setParams(['eternal_high_priest' => 'true']); // @phpstan-ignore-line

        self::assertTrue($params->EternalHighPriest);
    }

    public function testEternalHighPriestRejectsGarbage(): void
    {
        $params = new CalendarParams();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('eternal_high_priest');

        $params->setParams(['eternal_high_priest' => 'maybe']); // @phpstan-ignore-line
    }

    public function testLocaleIsCanonicalisedAndAccepted(): void
    {
        $params = new CalendarParams();
        $params->setParams(['locale' => 'en-us']);

        self::assertSame('en_US', $params->Locale);
    }

    public function testInvalidLocaleIsRejected(): void
    {
        $params = new CalendarParams();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('parameter `locale`');

        $params->setParams(['locale' => 'xx_YY']);
    }

    public function testReturnTypeIsApplied(): void
    {
        $params = new CalendarParams();
        $params->setParams(['return_type' => 'YML']);

        self::assertSame(ReturnTypeParam::YAML, $params->ReturnType);
    }

    public function testReturnTypeRespectsAllowedSubset(): void
    {
        $params = new CalendarParams();
        $params->setAllowedReturnTypes([ReturnTypeParam::JSON]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('parameter `return_type`');

        $params->setParams(['return_type' => 'XML']);
    }

    public function testNationalCalendarIsAcceptedFromMetadata(): void
    {
        $params = new CalendarParams();
        $params->setParams(['national_calendar' => 'IT']);

        self::assertSame('IT', $params->NationalCalendar);
    }

    public function testVaticanIsAcceptedAsImplicitNationalCalendar(): void
    {
        // 'VA' is the General Roman Calendar — accepted even when not present
        // in the metadata's national_calendars_keys list.
        $params = new CalendarParams();
        $params->setParams(['national_calendar' => 'VA']);

        self::assertSame('VA', $params->NationalCalendar);
    }

    public function testUnknownNationalCalendarIsRejected(): void
    {
        $params = new CalendarParams();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('national_calendar');

        $params->setParams(['national_calendar' => 'ZZ']);
    }

    public function testDiocesanCalendarIsAcceptedFromMetadata(): void
    {
        $params = new CalendarParams();
        $params->setParams(['diocesan_calendar' => 'romamo_it']);

        self::assertSame('romamo_it', $params->DiocesanCalendar);
    }

    public function testUnknownDiocesanCalendarIsRejected(): void
    {
        $params = new CalendarParams();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Diocesan calendar');

        $params->setParams(['diocesan_calendar' => 'nowhere']);
    }

    public function testHolyDaysOfObligationOverride(): void
    {
        $params = new CalendarParams();
        $params->setParams([
            'holydays_of_obligation' => [
                'Christmas'            => true,
                'Epiphany'             => false,
                'Ascension'            => true,
                'CorpusChristi'        => true,
                'MaryMotherOfGod'      => true,
                'ImmaculateConception' => true,
                'Assumption'           => true,
                'StJoseph'             => true,
                'StsPeterPaulAp'       => true,
                'AllSaints'            => true,
            ],
        ]);

        self::assertFalse($params->HolyDaysOfObligation['Epiphany']);
        self::assertTrue($params->HolyDaysOfObligation['Christmas']);
    }

    public function testHolyDaysOfObligationMissingRequiredKeyIsRejected(): void
    {
        $params = new CalendarParams();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('missing required keys');

        $params->setParams([
            'holydays_of_obligation' => [
                'Christmas'            => true,
                'Epiphany'             => true,
                'Ascension'            => true,
                'CorpusChristi'        => true,
                'MaryMotherOfGod'      => true,
                'ImmaculateConception' => true,
                'Assumption'           => true,
                'StJoseph'             => true,
                'StsPeterPaulAp'       => true,
                // AllSaints intentionally missing
            ],
        ]);
    }

    public function testHolyDaysOfObligationUnknownKeyIsRejected(): void
    {
        $params = new CalendarParams();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid key');

        $params->setParams([
            'holydays_of_obligation' => [
                'Christmas'            => true,
                'Epiphany'             => true,
                'Ascension'            => true,
                'CorpusChristi'        => true,
                'MaryMotherOfGod'      => true,
                'ImmaculateConception' => true,
                'Assumption'           => true,
                'StJoseph'             => true,
                'StsPeterPaulAp'       => true,
                'AllSaints'            => true,
                'NotAHoliday'          => true,
            ],
        ]);
    }

    public function testNonStringValueRejectedForStringParam(): void
    {
        $params = new CalendarParams();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Expected value of type String');

        $params->setParams(['locale' => 123]); // @phpstan-ignore-line
    }

    public function testInitParamsFromRequestPathYearOnly(): void
    {
        $params = new CalendarParams();
        $params->initParamsFromRequestPath(['2024']);

        self::assertSame(2024, $params->Year);
    }

    public function testInitParamsFromRequestPathRequiresNumericYear(): void
    {
        $params = new CalendarParams();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Year value');

        $params->initParamsFromRequestPath(['notayear']);
    }

    public function testInitParamsFromRequestPathNationFormat(): void
    {
        $params = new CalendarParams();
        $params->initParamsFromRequestPath(['nation', 'IT', '2024']);

        self::assertSame('IT', $params->NationalCalendar);
        self::assertSame(2024, $params->Year);
    }

    public function testInitParamsFromRequestPathDioceseFormat(): void
    {
        $params = new CalendarParams();
        $params->initParamsFromRequestPath(['diocese', 'romamo_it']);

        self::assertSame('romamo_it', $params->DiocesanCalendar);
    }

    public function testInitParamsFromRequestPathRejectsBadCategory(): void
    {
        $params = new CalendarParams();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('first parameter');

        $params->initParamsFromRequestPath(['planet', 'mars']);
    }

    public function testInitParamsFromRequestPathRejectsTooManyParts(): void
    {
        $params = new CalendarParams();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('at most three');

        $params->initParamsFromRequestPath(['nation', 'IT', '2024', 'extra']);
    }
}
