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
    private static string $savedApiPath = '';

    public static function setUpBeforeClass(): void
    {
        self::$savedApiPath = Router::$apiPath ?? '';
        Router::$apiPath    = 'file://' . realpath(__DIR__ . '/../fixtures/api');
    }

    public static function tearDownAfterClass(): void
    {
        Router::$apiPath = self::$savedApiPath;
    }

    public function testConstructorAppliesDefaults(): void
    {
        $params = new EventsParams();

        self::assertSame((int) date('Y'), $params->Year);
        self::assertFalse($params->EternalHighPriest);
        self::assertNull($params->NationalCalendar);
        self::assertNull($params->DiocesanCalendar);
        self::assertNotEmpty($params->calendarsMetadata->national_calendars_keys);
    }

    public function testValidLocaleSplitsLocaleAndBase(): void
    {
        $params = new EventsParams(['locale' => 'en_US']);

        self::assertSame('en_US', $params->Locale);
        self::assertSame('en', $params->baseLocale);
    }

    public function testUnsupportedLocaleFallsBackToLatin(): void
    {
        // Per current behavior, when a locale is canonicalisable but unsupported,
        // it silently falls back to Latin rather than rejecting outright.
        $params = new EventsParams(['locale' => 'not-a-locale']);

        self::assertSame(LitLocale::LATIN, $params->Locale);
        self::assertSame(LitLocale::LATIN_PRIMARY_LANGUAGE, $params->baseLocale);
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
