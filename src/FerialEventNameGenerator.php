<?php

namespace LiturgicalCalendar\Api;

use LiturgicalCalendar\Api\Enum\LitLocale;
use LiturgicalCalendar\Api\Router;
use LiturgicalCalendar\Api\Services\LocaleConfigurator;

/**
 * Generates localized names for ferial (weekday) liturgical events.
 *
 * This class parses event_keys for ferial events and generates appropriate
 * localized names using gettext, IntlDateFormatter, and NumberFormatter.
 *
 * Event key patterns supported:
 * - AdventWeekday{week}{day} (e.g., AdventWeekday1Monday)
 * - AdventWeekdayDec{date} (e.g., AdventWeekdayDec17)
 * - ChristmasWeekdayDec{date} (e.g., ChristmasWeekdayDec29)
 * - ChristmasWeekdayJan{date} (e.g., ChristmasWeekdayJan2)
 * - DayAfterEpiphany{day} (e.g., DayAfterEpiphanyMonday)
 * - DayAfterEpiphanyJan{date} (e.g., DayAfterEpiphanyJan8)
 * - {day}AfterAshWednesday (e.g., ThursdayAfterAshWednesday)
 * - LentWeekday{week}{day} (e.g., LentWeekday1Monday)
 * - {day}OctaveEaster (e.g., MonOctaveEaster)
 * - EasterWeekday{week}{day} (e.g., EasterWeekday2Monday)
 * - OrdWeekday{week}{day} (e.g., OrdWeekday1Monday)
 *
 * @todo CalendarHandler::calculateWeekdaysOrdinaryTime() has similar logic for
 *       generating Ordinary Time weekday names. Consider refactoring CalendarHandler
 *       to use this class to avoid code duplication.
 */
class FerialEventNameGenerator
{
    private string $locale;
    private string $primaryLanguage;
    private \IntlDateFormatter $dayOfTheWeekFormatter;
    private \NumberFormatter $ordinalFormatter;

    /**
     * Mapping of English day abbreviations to full day names.
     */
    private const array DAY_ABBREV_MAP = [
        'Mon' => 'Monday',
        'Tue' => 'Tuesday',
        'Wed' => 'Wednesday',
        'Thu' => 'Thursday',
        'Fri' => 'Friday',
        'Sat' => 'Saturday',
        'Sun' => 'Sunday',
    ];

    /**
     * Mapping of English day names to day-of-week numbers (0=Sunday).
     */
    private const array DAY_TO_NUMBER = [
        'Sunday'    => 0,
        'Monday'    => 1,
        'Tuesday'   => 2,
        'Wednesday' => 3,
        'Thursday'  => 4,
        'Friday'    => 5,
        'Saturday'  => 6,
    ];

    /**
     * Latin ordinal forms for week numbers (genitive case).
     */
    private const array LATIN_ORDINALS_GENITIVE = [
        1  => 'Primæ',
        2  => 'Secundæ',
        3  => 'Tertiæ',
        4  => 'Quartæ',
        5  => 'Quintæ',
        6  => 'Sextæ',
        7  => 'Septimæ',
        8  => 'Octavæ',
        9  => 'Nonæ',
        10 => 'Decimæ',
        11 => 'Undecimæ',
        12 => 'Duodecimæ',
        13 => 'Decimæ Tertiæ',
        14 => 'Decimæ Quartæ',
        15 => 'Decimæ Quintæ',
        16 => 'Decimæ Sextæ',
        17 => 'Decimæ Septimæ',
        18 => 'Decimæ Octavæ',
        19 => 'Decimæ Nonæ',
        20 => 'Vigesimæ',
        21 => 'Vigesimæ Primæ',
        22 => 'Vigesimæ Secundæ',
        23 => 'Vigesimæ Tertiæ',
        24 => 'Vigesimæ Quartæ',
        25 => 'Vigesimæ Quintæ',
        26 => 'Vigesimæ Sextæ',
        27 => 'Vigesimæ Septimæ',
        28 => 'Vigesimæ Octavæ',
        29 => 'Vigesimæ Nonæ',
        30 => 'Trigesimæ',
        31 => 'Trigesimæ Primæ',
        32 => 'Trigesimæ Secundæ',
        33 => 'Trigesimæ Tertiæ',
        34 => 'Trigesimæ Quartæ',
    ];

    /**
     * Latin ordinal forms for day numbers.
     */
    private const array LATIN_ORDINALS_DAY = [
        1 => 'Prima',
        2 => 'Secunda',
        3 => 'Tertia',
        4 => 'Quarta',
        5 => 'Quinta',
        6 => 'Sexta',
        7 => 'Septima',
        8 => 'Octava',
    ];

    /**
     * Create a new FerialEventNameGenerator instance.
     *
     * @param string $locale The locale to use for name generation
     */
    public function __construct(string $locale)
    {
        $this->locale = $locale;
        // Derive primary language from either underscore or hyphen separated tags (e.g. "en_US", "en-US")
        $parts                 = preg_split('/[-_]/', $locale);
        $this->primaryLanguage = $parts[0] ?? $locale;

        $this->initializeFormatters();
        $this->initializeGettext();
    }

    /**
     * Initialize IntlDateFormatter and NumberFormatter for the locale.
     */
    private function initializeFormatters(): void
    {
        // Use 'la' for Latin since ICU doesn't support it, we'll handle Latin specially
        $formatterLocale = $this->primaryLanguage === 'la' ? 'en' : $this->primaryLanguage;

        $dayOfWeekFormatter = \IntlDateFormatter::create(
            $formatterLocale,
            \IntlDateFormatter::FULL,
            \IntlDateFormatter::NONE,
            'UTC',
            \IntlDateFormatter::GREGORIAN,
            'EEEE'
        );

        if (null === $dayOfWeekFormatter) {
            // Fallback to English
            $dayOfWeekFormatter = \IntlDateFormatter::create(
                'en',
                \IntlDateFormatter::FULL,
                \IntlDateFormatter::NONE,
                'UTC',
                \IntlDateFormatter::GREGORIAN,
                'EEEE'
            );
        }

        /** @var \IntlDateFormatter $dayOfWeekFormatter */
        $this->dayOfTheWeekFormatter = $dayOfWeekFormatter;

        $ordinalFormatter = \NumberFormatter::create($formatterLocale, \NumberFormatter::ORDINAL);
        if (null === $ordinalFormatter) {
            // Fallback to English
            $ordinalFormatter = \NumberFormatter::create('en', \NumberFormatter::ORDINAL);
        }

        /** @var \NumberFormatter $ordinalFormatter */
        $this->ordinalFormatter = $ordinalFormatter;
    }

    /**
     * Initialize gettext for the locale.
     *
     * Delegates process-global locale/LANGUAGE/ICU setup to the shared
     * LocaleConfigurator (#745) — which resets for Latin and pins the catalog for
     * translated locales, overriding any state a prior request left in this worker —
     * then binds the gettext text domain. Latin phrase templates fall through to the
     * English msgid base (there is no installable Latin locale); Latin day names and
     * ordinals are emitted from hardcoded tables elsewhere in this class.
     */
    private function initializeGettext(): void
    {
        LocaleConfigurator::configure($this->locale);

        // Bind textdomain using Router::$apiFilePath if available, else fall back.
        $i18nPath = Router::$apiFilePath . 'i18n';
        if (!is_dir($i18nPath)) {
            $i18nPath = dirname(__DIR__) . '/i18n';
        }

        bindtextdomain('litcal', $i18nPath);
        bind_textdomain_codeset('litcal', 'UTF-8');
        textdomain('litcal');
    }

    /**
     * Generate a localized name for a ferial event.
     *
     * @param string $eventKey The event key to generate a name for
     * @return string|null The generated name, or null if the event key is not recognized
     */
    public function generateName(string $eventKey): ?string
    {
        // Try each pattern in order
        if (preg_match('/^AdventWeekdayDec(\d+)$/', $eventKey, $matches)) {
            return $this->generateAdventWeekdayDecName((int) $matches[1]);
        }

        if (preg_match('/^AdventWeekday(\d)(\w+)$/', $eventKey, $matches)) {
            return $this->generateAdventWeekdayName((int) $matches[1], $matches[2]);
        }

        if (preg_match('/^ChristmasWeekdayDec(\d+)$/', $eventKey, $matches)) {
            return $this->generateChristmasOctaveName((int) $matches[1]);
        }

        if (preg_match('/^ChristmasWeekdayJan(\d+)$/', $eventKey, $matches)) {
            return $this->generateChristmasWeekdayJanName((int) $matches[1]);
        }

        if (preg_match('/^DayAfterEpiphany(\w+)$/', $eventKey, $matches)) {
            return $this->generateDayAfterEpiphanyName($matches[1]);
        }

        if (preg_match('/^DayAfterEpiphanyJan(\d+)$/', $eventKey, $matches)) {
            return $this->generateDayAfterEpiphanyJanName((int) $matches[1]);
        }

        if (preg_match('/^(Thursday|Friday|Saturday)AfterAshWednesday$/', $eventKey, $matches)) {
            return $this->generateAfterAshWednesdayName($matches[1]);
        }

        if (preg_match('/^LentWeekday(\d)(\w+)$/', $eventKey, $matches)) {
            return $this->generateLentWeekdayName((int) $matches[1], $matches[2]);
        }

        if (preg_match('/^(Mon|Tue|Wed|Thu|Fri|Sat)OctaveEaster$/', $eventKey, $matches)) {
            return $this->generateEasterOctaveName($matches[1]);
        }

        if (preg_match('/^EasterWeekday(\d)(\w+)$/', $eventKey, $matches)) {
            return $this->generateEasterWeekdayName((int) $matches[1], $matches[2]);
        }

        if (preg_match('/^OrdWeekday(\d+)(\w+)$/', $eventKey, $matches)) {
            return $this->generateOrdinaryTimeWeekdayName((int) $matches[1], $matches[2]);
        }

        if (preg_match('/^(Mon|Tue|Wed)HolyWeek$/', $eventKey, $matches)) {
            return $this->generateHolyWeekName($matches[1]);
        }

        return null;
    }

    /**
     * Get the localized day of the week name.
     *
     * @param string $dayEnglish The English day name (e.g., 'Monday')
     * @return string The localized day name
     */
    private function getLocalizedDayName(string $dayEnglish): string
    {
        // For Latin, use the Latin day names
        if ($this->primaryLanguage === 'la') {
            $dayNum = self::DAY_TO_NUMBER[$dayEnglish] ?? 1;
            return LatinUtils::LATIN_DAYOFTHEWEEK[$dayNum];
        }

        // Create a date for that day of the week
        // Use UTC timezone to match the IntlDateFormatter timezone
        $dayNum    = self::DAY_TO_NUMBER[$dayEnglish] ?? 1;
        $baseDate  = new \DateTime('2024-01-01', new \DateTimeZone('UTC')); // A Monday in UTC
        $daysToAdd = ( $dayNum - 1 + 7 ) % 7; // Days from Monday
        $baseDate->modify("+{$daysToAdd} days");

        $formatted = $this->dayOfTheWeekFormatter->format($baseDate);
        return $formatted !== false ? Utilities::ucfirst($formatted) : $dayEnglish;
    }

    /**
     * Get a localized ordinal number for a week.
     *
     * @param int $weekNumber The week number
     * @return string The localized ordinal
     */
    private function getLocalizedOrdinal(int $weekNumber): string
    {
        if ($this->primaryLanguage === 'la') {
            return self::LATIN_ORDINALS_GENITIVE[$weekNumber] ?? (string) $weekNumber;
        }

        $formatted = $this->ordinalFormatter->format($weekNumber);
        return $formatted !== false ? $formatted : (string) $weekNumber;
    }

    /**
     * Generate name for Advent weekday with specific December date (Dec 17-24).
     */
    private function generateAdventWeekdayDecName(int $dayOfMonth): string
    {
        if ($this->primaryLanguage === 'la') {
            return sprintf('%s Decembris', $dayOfMonth);
        }

        /**translators: %s is a day number (17, 18, etc.) - for Advent weekdays Dec 17-24 */
        return sprintf(_('December %s'), $dayOfMonth);
    }

    /**
     * Generate name for Advent weekday (e.g., "Monday of the 1st Week of Advent").
     */
    private function generateAdventWeekdayName(int $week, string $day): string
    {
        $dayName = $this->getLocalizedDayName($day);
        $ordinal = $this->getLocalizedOrdinal($week);

        if ($this->primaryLanguage === 'la') {
            return sprintf('%s Hebdomadæ %s Adventus', $dayName, $ordinal);
        }

        /**translators: %s is an ordinal number (1st, 2nd, 3rd) */
        $weekPhrase = sprintf(_('of the %s Week of Advent'), $ordinal);
        return $dayName . ' ' . $weekPhrase;
    }

    /**
     * Generate name for Christmas Octave day (Dec 26-31).
     */
    private function generateChristmasOctaveName(int $dayOfMonth): string
    {
        // Calculate which day of the octave (Dec 25 = day 1)
        $octaveDay = $dayOfMonth - 24;
        $ordinal   = $this->getLocalizedOrdinal($octaveDay);

        if ($this->primaryLanguage === 'la') {
            $latinOrdinal = self::LATIN_ORDINALS_DAY[$octaveDay] ?? $ordinal;
            return sprintf('Dies %s infra Octavam Nativitatis', $latinOrdinal);
        }

        /**translators: %s is an ordinal number (2nd, 3rd, etc.) - Day of Christmas Octave */
        return sprintf(_('%s Day of the Octave of Christmas'), $ordinal);
    }

    /**
     * Generate name for Christmas weekday in January (Jan 2-6).
     */
    private function generateChristmasWeekdayJanName(int $dayOfMonth): string
    {
        if ($this->primaryLanguage === 'la') {
            return sprintf('%s Ianuarii', $dayOfMonth);
        }

        if ($this->primaryLanguage === 'it') {
            return sprintf('Feria propria del %s gennaio', $dayOfMonth);
        }

        /**translators: %s is a day number (2, 3, etc.) - for Christmas weekdays Jan 2-6 */
        return sprintf(_('January %s'), $dayOfMonth);
    }

    /**
     * Generate name for day after Epiphany (when Epiphany is on a Sunday).
     */
    private function generateDayAfterEpiphanyName(string $day): string
    {
        // Expand abbreviation if needed
        $fullDay = self::DAY_ABBREV_MAP[$day] ?? $day;
        $dayName = $this->getLocalizedDayName($fullDay);

        if ($this->primaryLanguage === 'la') {
            return sprintf('%s post Epiphaniam', $dayName);
        }

        /**translators: %s is a day of the week - for days after Epiphany */
        return sprintf(_('%s after Epiphany'), $dayName);
    }

    /**
     * Generate name for day after Epiphany with specific January date.
     */
    private function generateDayAfterEpiphanyJanName(int $dayOfMonth): string
    {
        if ($this->primaryLanguage === 'la') {
            return sprintf('%s Ianuarii', $dayOfMonth);
        }

        if ($this->primaryLanguage === 'it') {
            return sprintf('Feria propria del %s gennaio', $dayOfMonth);
        }

        /**translators: %s is a day number (7, 8, etc.) - for days after Epiphany */
        return sprintf(_('January %s'), $dayOfMonth);
    }

    /**
     * Generate name for days after Ash Wednesday.
     */
    private function generateAfterAshWednesdayName(string $day): string
    {
        $dayName = $this->getLocalizedDayName($day);

        if ($this->primaryLanguage === 'la') {
            return sprintf('%s post Feria IV Cinerum', $dayName);
        }

        /**translators: Day after Ash Wednesday pattern */
        $postStr = _('after Ash Wednesday');
        return $dayName . ' ' . $postStr;
    }

    /**
     * Generate name for Lent weekday (e.g., "Monday of the 1st Week of Lent").
     */
    private function generateLentWeekdayName(int $week, string $day): string
    {
        $dayName = $this->getLocalizedDayName($day);
        $ordinal = $this->getLocalizedOrdinal($week);

        if ($this->primaryLanguage === 'la') {
            return sprintf('%s Hebdomadæ %s Quadragesimæ', $dayName, $ordinal);
        }

        /**translators: %s is an ordinal number (1st, 2nd, 3rd) */
        $weekPhrase = sprintf(_('of the %s Week of Lent'), $ordinal);
        return $dayName . ' ' . $weekPhrase;
    }

    /**
     * Generate name for Easter Octave day.
     */
    private function generateEasterOctaveName(string $dayAbbrev): string
    {
        $fullDay = self::DAY_ABBREV_MAP[$dayAbbrev] ?? $dayAbbrev;
        $dayName = $this->getLocalizedDayName($fullDay);

        if ($this->primaryLanguage === 'la') {
            return sprintf('%s infra Octavam Paschæ', $dayName);
        }

        /**translators: %s is a day of the week - Easter Octave pattern */
        return sprintf(_('%s within the Octave of Easter'), $dayName);
    }

    /**
     * Generate name for Easter weekday (e.g., "Monday of the 2nd Week of Easter").
     */
    private function generateEasterWeekdayName(int $week, string $day): string
    {
        $dayName = $this->getLocalizedDayName($day);
        $ordinal = $this->getLocalizedOrdinal($week);

        if ($this->primaryLanguage === 'la') {
            return sprintf('%s Hebdomadæ %s Temporis Paschali', $dayName, $ordinal);
        }

        /**translators: %s is an ordinal number (2nd, 3rd, etc.) */
        $weekPhrase = sprintf(_('of the %s Week of Easter'), $ordinal);
        return $dayName . ' ' . $weekPhrase;
    }

    /**
     * Generate name for Ordinary Time weekday.
     */
    private function generateOrdinaryTimeWeekdayName(int $week, string $day): string
    {
        $dayName = $this->getLocalizedDayName($day);
        $ordinal = $this->getLocalizedOrdinal($week);

        if ($this->primaryLanguage === 'la') {
            // "Tempus per annum" is the liturgically correct Latin for "Ordinary Time"
            return sprintf('%s Hebdomadæ %s Temporis per annum', $dayName, $ordinal);
        }

        /**translators: %s is an ordinal number (1st, 2nd, etc.) */
        $weekPhrase = sprintf(_('of the %s Week of Ordinary Time'), $ordinal);
        return $dayName . ' ' . $weekPhrase;
    }

    /**
     * Generate name for Holy Week days (Mon, Tue, Wed).
     */
    private function generateHolyWeekName(string $dayAbbrev): string
    {
        $fullDay = self::DAY_ABBREV_MAP[$dayAbbrev] ?? $dayAbbrev;
        $dayName = $this->getLocalizedDayName($fullDay);

        if ($this->primaryLanguage === 'la') {
            return sprintf('%s Hebdomadæ Sanctæ', $dayName);
        }

        /**translators: %s is a day of the week - Holy Week pattern */
        return sprintf(_('%s of Holy Week'), $dayName);
    }
}
