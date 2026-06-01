<?php

namespace LiturgicalCalendar\Tests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use LiturgicalCalendar\Api\LocaleDateFormatter;
use LiturgicalCalendar\Api\LatinUtils;
use IntlDateFormatter;

/**
 * Unit tests for the LocaleDateFormatter utility class.
 *
 * Tests formatLocalizedDate(), getChristmasWeekdayIdentifier(), and formatChristmasWeekdayName()
 * for representative locales (Latin, English, Italian, French, German).
 */
class LocaleDateFormatterTest extends TestCase
{
    /**
     * Reset gettext state to a deterministic "no translations bound" baseline
     * before each test.
     *
     * `LocaleDateFormatter::formatChristmasWeekdayName()` has hardcoded
     * sprintf paths for Latin and Italian, but the English/French/default
     * branch calls `_('%s - Christmas Weekday')` and inherits whatever the
     * process-wide gettext state currently is. Production handlers
     * (`CalendarHandler`, `EventsHandler`, `FerialEventNameGenerator`) call
     * `setlocale + bindtextdomain('litcal') + textdomain('litcal')` and don't
     * restore. When a prior test exercises one of those handlers with
     * locale=it_IT, the Italian .mo catalog stays bound for the rest of the
     * PHP process — and the English tests in this file then get back
     * "%s - Giorno feriale di Natale" instead of the expected msgid fallback.
     *
     * Resetting to `LC_ALL=C` and the default `messages` textdomain (which
     * this project never binds) means `_()` returns its msgid unchanged.
     * Tests that assert hardcoded translations (Latin/Italian) are unaffected
     * because those paths bypass gettext.
     */
    protected function setUp(): void
    {
        parent::setUp();
        \setlocale(LC_ALL, 'C');
        \textdomain('messages');
    }

    /* ========================= formatLocalizedDate Tests ========================= */

    /**
     * Test formatLocalizedDate for Latin locale.
     *
     * Latin format should be: "j + LATIN_MONTHS[n]" (e.g., "25 December" → "25 December")
     */
    public function testFormatLocalizedDateLatin(): void
    {
        $formatter = new LocaleDateFormatter('la');

        $date     = new \LiturgicalCalendar\Api\DateTime('2024-12-25', new \DateTimeZone('UTC'));
        $result   = $formatter->formatLocalizedDate($date);
        $expected = '25 ' . LatinUtils::LATIN_MONTHS[12];

        $this->assertSame($expected, $result, 'Latin date should use LATIN_MONTHS array');
        $this->assertSame('25 December', $result);
    }

    /**
     * Test formatLocalizedDate for Latin with region code (la_VA).
     */
    public function testFormatLocalizedDateLatinWithRegion(): void
    {
        $formatter = new LocaleDateFormatter('la_VA');

        $date     = new \LiturgicalCalendar\Api\DateTime('2024-01-06', new \DateTimeZone('UTC'));
        $result   = $formatter->formatLocalizedDate($date);
        $expected = '6 ' . LatinUtils::LATIN_MONTHS[1];

        $this->assertSame($expected, $result);
        $this->assertSame('6 Ianuarius', $result);
    }

    /**
     * Test formatLocalizedDate for English locale.
     *
     * English format should be: "F jS" (e.g., "December 25th")
     */
    public function testFormatLocalizedDateEnglish(): void
    {
        $formatter = new LocaleDateFormatter('en');

        $date   = new \LiturgicalCalendar\Api\DateTime('2024-12-25', new \DateTimeZone('UTC'));
        $result = $formatter->formatLocalizedDate($date);

        $this->assertSame('December 25th', $result);
    }

    /**
     * Test formatLocalizedDate for English with region code (en_US).
     */
    public function testFormatLocalizedDateEnglishUS(): void
    {
        $formatter = new LocaleDateFormatter('en_US');

        $date   = new \LiturgicalCalendar\Api\DateTime('2024-01-01', new \DateTimeZone('UTC'));
        $result = $formatter->formatLocalizedDate($date);

        $this->assertSame('January 1st', $result);
    }

    /**
     * Test formatLocalizedDate for Italian locale (uses IntlDateFormatter).
     */
    public function testFormatLocalizedDateItalian(): void
    {
        $formatter = new LocaleDateFormatter('it_IT');

        $date   = new \LiturgicalCalendar\Api\DateTime('2024-12-25', new \DateTimeZone('UTC'));
        $result = $formatter->formatLocalizedDate($date);

        // Italian format from IntlDateFormatter with 'd MMMM' pattern
        $this->assertSame('25 dicembre', $result);
    }

    /**
     * Test formatLocalizedDate for French locale (uses IntlDateFormatter).
     */
    public function testFormatLocalizedDateFrench(): void
    {
        $formatter = new LocaleDateFormatter('fr_FR');

        $date   = new \LiturgicalCalendar\Api\DateTime('2024-12-25', new \DateTimeZone('UTC'));
        $result = $formatter->formatLocalizedDate($date);

        // French format from IntlDateFormatter with 'd MMMM' pattern
        $this->assertSame('25 décembre', $result);
    }

    /**
     * Test formatLocalizedDate for German locale (uses IntlDateFormatter).
     */
    public function testFormatLocalizedDateGerman(): void
    {
        $formatter = new LocaleDateFormatter('de_DE');

        $date   = new \LiturgicalCalendar\Api\DateTime('2024-12-25', new \DateTimeZone('UTC'));
        $result = $formatter->formatLocalizedDate($date);

        // German format from IntlDateFormatter with 'd MMMM' pattern
        $this->assertSame('25 Dezember', $result);
    }

    /* ========================= getChristmasWeekdayIdentifier Tests ========================= */

    /**
     * Test getChristmasWeekdayIdentifier for Latin locale.
     *
     * Latin should return day of week from LATIN_DAYOFTHEWEEK array.
     */
    public function testGetChristmasWeekdayIdentifierLatin(): void
    {
        $formatter = new LocaleDateFormatter('la');

        // Monday, December 30, 2024
        $date     = new \LiturgicalCalendar\Api\DateTime('2024-12-30', new \DateTimeZone('UTC'));
        $result   = $formatter->getChristmasWeekdayIdentifier($date);
        $expected = LatinUtils::LATIN_DAYOFTHEWEEK[(int) $date->format('w')];

        $this->assertSame($expected, $result);
        $this->assertSame('Feria II', $result); // Monday = Feria II
    }

    /**
     * Test getChristmasWeekdayIdentifier for Latin on Sunday.
     */
    public function testGetChristmasWeekdayIdentifierLatinSunday(): void
    {
        $formatter = new LocaleDateFormatter('la_VA');

        // Sunday, December 29, 2024
        $date   = new \LiturgicalCalendar\Api\DateTime('2024-12-29', new \DateTimeZone('UTC'));
        $result = $formatter->getChristmasWeekdayIdentifier($date);

        $this->assertSame('Dominica', $result); // Sunday = Dominica
    }

    /**
     * Test getChristmasWeekdayIdentifier for Italian locale.
     *
     * Italian should return day and month (e.g., "30 dicembre").
     */
    public function testGetChristmasWeekdayIdentifierItalian(): void
    {
        $formatter = new LocaleDateFormatter('it_IT');

        // Monday, December 30, 2024
        $date   = new \LiturgicalCalendar\Api\DateTime('2024-12-30', new \DateTimeZone('UTC'));
        $result = $formatter->getChristmasWeekdayIdentifier($date);

        // Italian uses dayAndMonth formatter, then ucfirst
        $this->assertSame('30 dicembre', $result);
    }

    /**
     * Test getChristmasWeekdayIdentifier for English locale.
     *
     * English (and other non-Latin, non-Italian) should return day of week.
     */
    public function testGetChristmasWeekdayIdentifierEnglish(): void
    {
        $formatter = new LocaleDateFormatter('en_US');

        // Monday, December 30, 2024
        $date   = new \LiturgicalCalendar\Api\DateTime('2024-12-30', new \DateTimeZone('UTC'));
        $result = $formatter->getChristmasWeekdayIdentifier($date);

        // English uses dayOfTheWeek formatter with ucfirst
        $this->assertSame('Monday', $result);
    }

    /**
     * Test getChristmasWeekdayIdentifier for French locale.
     */
    public function testGetChristmasWeekdayIdentifierFrench(): void
    {
        $formatter = new LocaleDateFormatter('fr_FR');

        // Monday, December 30, 2024
        $date   = new \LiturgicalCalendar\Api\DateTime('2024-12-30', new \DateTimeZone('UTC'));
        $result = $formatter->getChristmasWeekdayIdentifier($date);

        // French uses dayOfTheWeek formatter with ucfirst
        $this->assertSame('Lundi', $result);
    }

    /* ========================= formatChristmasWeekdayName Tests ========================= */

    /**
     * Test formatChristmasWeekdayName for Latin locale.
     *
     * Latin format: "{dateIdentifier} temporis Nativitatis"
     */
    public function testFormatChristmasWeekdayNameLatin(): void
    {
        $formatter = new LocaleDateFormatter('la');
        $result    = $formatter->formatChristmasWeekdayName('Feria II');

        $this->assertSame('Feria II temporis Nativitatis', $result);
    }

    /**
     * Test formatChristmasWeekdayName for Latin with region code.
     */
    public function testFormatChristmasWeekdayNameLatinWithRegion(): void
    {
        $formatter = new LocaleDateFormatter('la_VA');
        $result    = $formatter->formatChristmasWeekdayName('Dominica');

        $this->assertSame('Dominica temporis Nativitatis', $result);
    }

    /**
     * Test formatChristmasWeekdayName for Italian locale.
     *
     * Italian format: "Feria propria del {dateIdentifier}"
     */
    public function testFormatChristmasWeekdayNameItalian(): void
    {
        $formatter = new LocaleDateFormatter('it_IT');
        $result    = $formatter->formatChristmasWeekdayName('30 dicembre');

        $this->assertSame('Feria propria del 30 dicembre', $result);
    }

    /**
     * Test formatChristmasWeekdayName for English locale.
     *
     * English format (via gettext): "{dateIdentifier} - Christmas Weekday"
     */
    public function testFormatChristmasWeekdayNameEnglish(): void
    {
        $formatter = new LocaleDateFormatter('en_US');
        $result    = $formatter->formatChristmasWeekdayName('Monday');

        // Without gettext loaded, falls back to the format string
        $this->assertSame('Monday - Christmas Weekday', $result);
    }

    /**
     * Test formatChristmasWeekdayName for French locale.
     *
     * French uses gettext translation pattern.
     */
    public function testFormatChristmasWeekdayNameFrench(): void
    {
        $formatter = new LocaleDateFormatter('fr_FR');
        $result    = $formatter->formatChristmasWeekdayName('Lundi');

        // Without gettext loaded with French translations, falls back to pattern
        $this->assertStringContainsString('Lundi', $result);
    }

    /* ========================= Edge Cases and Integration Tests ========================= */

    /**
     * Test full flow: getChristmasWeekdayIdentifier → formatChristmasWeekdayName for Latin.
     */
    public function testChristmasWeekdayFullFlowLatin(): void
    {
        $formatter = new LocaleDateFormatter('la');

        // Tuesday, December 31, 2024
        $date = new \LiturgicalCalendar\Api\DateTime('2024-12-31', new \DateTimeZone('UTC'));

        $identifier = $formatter->getChristmasWeekdayIdentifier($date);
        $name       = $formatter->formatChristmasWeekdayName($identifier);

        $this->assertSame('Feria III', $identifier);
        $this->assertSame('Feria III temporis Nativitatis', $name);
    }

    /**
     * Test full flow: getChristmasWeekdayIdentifier → formatChristmasWeekdayName for Italian.
     */
    public function testChristmasWeekdayFullFlowItalian(): void
    {
        $formatter = new LocaleDateFormatter('it_IT');

        // Tuesday, December 31, 2024
        $date = new \LiturgicalCalendar\Api\DateTime('2024-12-31', new \DateTimeZone('UTC'));

        $identifier = $formatter->getChristmasWeekdayIdentifier($date);
        $name       = $formatter->formatChristmasWeekdayName($identifier);

        $this->assertSame('31 dicembre', $identifier);
        $this->assertSame('Feria propria del 31 dicembre', $name);
    }

    /**
     * Test full flow: getChristmasWeekdayIdentifier → formatChristmasWeekdayName for English.
     */
    public function testChristmasWeekdayFullFlowEnglish(): void
    {
        $formatter = new LocaleDateFormatter('en_US');

        // Tuesday, December 31, 2024
        $date = new \LiturgicalCalendar\Api\DateTime('2024-12-31', new \DateTimeZone('UTC'));

        $identifier = $formatter->getChristmasWeekdayIdentifier($date);
        $name       = $formatter->formatChristmasWeekdayName($identifier);

        $this->assertSame('Tuesday', $identifier);
        $this->assertSame('Tuesday - Christmas Weekday', $name);
    }

    /**
     * Test formatLocalizedDate with various dates across different months.
     */
    #[DataProvider('dateProvider')]
    public function testFormatLocalizedDateVariousDates(
        string $locale,
        string $dateString,
        string $expectedPattern
    ): void {
        $formatter = new LocaleDateFormatter($locale);

        $date   = new \LiturgicalCalendar\Api\DateTime($dateString, new \DateTimeZone('UTC'));
        $result = $formatter->formatLocalizedDate($date);

        $this->assertMatchesRegularExpression($expectedPattern, $result);
    }

    /**
     * Data provider for various date formatting tests.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function dateProvider(): array
    {
        return [
            'Latin January'            => ['la', '2024-01-15', '/^15 Ianuarius$/'],
            'Latin March'              => ['la', '2024-03-19', '/^19 Martius$/'],
            'English March'            => ['en', '2024-03-03', '/^March 3rd$/'],
            'English with ordinal 1st' => ['en', '2024-05-01', '/^May 1st$/'],
            'English with ordinal 2nd' => ['en', '2024-06-02', '/^June 2nd$/'],
            'Italian June'             => ['it', '2024-06-24', '/^24 giugno$/'],
        ];
    }

    /* ========================= IntlDateFormatter Fallback Tests ========================= */

    /**
     * Test formatLocalizedDate fallback when IntlDateFormatter::format() returns false.
     *
     * When the formatter fails, formatLocalizedDate should fall back to 'j/n' format.
     */
    public function testFormatLocalizedDateFormatterFallback(): void
    {
        $formatter = new LocaleDateFormatter('it_IT');

        // Inject a mock formatter that returns false
        $mockFormatter = $this->createMock(IntlDateFormatter::class);
        $mockFormatter->method('format')->willReturn(false);
        $formatter->setDayAndMonthFormatter($mockFormatter);

        $date   = new \LiturgicalCalendar\Api\DateTime('2024-12-25', new \DateTimeZone('UTC'));
        $result = $formatter->formatLocalizedDate($date);

        // Should fall back to 'j/n' format
        $this->assertSame('25/12', $result);
    }

    /**
     * Test getChristmasWeekdayIdentifier fallback for Italian when IntlDateFormatter fails.
     *
     * When dayAndMonth formatter fails, Italian should fall back to DateTime::format('l').
     */
    public function testGetChristmasWeekdayIdentifierItalianFormatterFallback(): void
    {
        $formatter = new LocaleDateFormatter('it_IT');

        // Inject a mock formatter that returns false
        $mockFormatter = $this->createMock(IntlDateFormatter::class);
        $mockFormatter->method('format')->willReturn(false);
        $formatter->setDayAndMonthFormatter($mockFormatter);

        // Monday, December 30, 2024
        $date   = new \LiturgicalCalendar\Api\DateTime('2024-12-30', new \DateTimeZone('UTC'));
        $result = $formatter->getChristmasWeekdayIdentifier($date);

        // Should fall back to DateTime::format('l') with ucfirst
        $this->assertSame('Monday', $result);
    }

    /**
     * Test getChristmasWeekdayIdentifier fallback for non-Latin/non-Italian when formatter fails.
     *
     * When dayOfTheWeek formatter fails, other locales should fall back to DateTime::format('l').
     */
    public function testGetChristmasWeekdayIdentifierOtherLocaleFormatterFallback(): void
    {
        $formatter = new LocaleDateFormatter('fr_FR');

        // Inject a mock formatter that returns false
        $mockFormatter = $this->createMock(IntlDateFormatter::class);
        $mockFormatter->method('format')->willReturn(false);
        $formatter->setDayOfTheWeekFormatter($mockFormatter);

        // Monday, December 30, 2024
        $date   = new \LiturgicalCalendar\Api\DateTime('2024-12-30', new \DateTimeZone('UTC'));
        $result = $formatter->getChristmasWeekdayIdentifier($date);

        // Should fall back to DateTime::format('l') with ucfirst
        $this->assertSame('Monday', $result);
    }

    /* ========================= Getter/Setter Tests ========================= */

    /**
     * Test getLocale returns the correct locale.
     */
    public function testGetLocale(): void
    {
        $formatter = new LocaleDateFormatter('en_US');
        $this->assertSame('en_US', $formatter->getLocale());
    }

    /**
     * Test getPrimaryLanguage returns the correct primary language.
     */
    public function testGetPrimaryLanguage(): void
    {
        $formatter = new LocaleDateFormatter('en_US');
        $this->assertSame('en', $formatter->getPrimaryLanguage());

        $formatterSimple = new LocaleDateFormatter('la');
        $this->assertSame('la', $formatterSimple->getPrimaryLanguage());
    }

    /**
     * Test getDayAndMonthFormatter returns the formatter.
     */
    public function testGetDayAndMonthFormatter(): void
    {
        $formatter = new LocaleDateFormatter('en_US');
        $this->assertInstanceOf(IntlDateFormatter::class, $formatter->getDayAndMonthFormatter());
    }

    /**
     * Test getDayOfTheWeekFormatter returns the formatter.
     */
    public function testGetDayOfTheWeekFormatter(): void
    {
        $formatter = new LocaleDateFormatter('en_US');
        $this->assertInstanceOf(IntlDateFormatter::class, $formatter->getDayOfTheWeekFormatter());
    }

    /**
     * Test setDayAndMonthFormatter returns $this for fluent interface.
     */
    public function testSetDayAndMonthFormatterReturnsSelf(): void
    {
        $formatter     = new LocaleDateFormatter('en_US');
        $mockFormatter = $this->createMock(IntlDateFormatter::class);

        $result = $formatter->setDayAndMonthFormatter($mockFormatter);

        $this->assertSame($formatter, $result);
    }

    /**
     * Test setDayOfTheWeekFormatter returns $this for fluent interface.
     */
    public function testSetDayOfTheWeekFormatterReturnsSelf(): void
    {
        $formatter     = new LocaleDateFormatter('en_US');
        $mockFormatter = $this->createMock(IntlDateFormatter::class);

        $result = $formatter->setDayOfTheWeekFormatter($mockFormatter);

        $this->assertSame($formatter, $result);
    }
}
