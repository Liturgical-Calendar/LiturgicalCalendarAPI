<?php

namespace LiturgicalCalendar\Api\Enum;

enum LitSeason: string
{
    use EnumToArrayTrait;

    case ADVENT         = 'ADVENT';
    case CHRISTMAS      = 'CHRISTMAS';
    case LENT           = 'LENT';
    case EASTER_TRIDUUM = 'EASTER_TRIDUUM';
    case EASTER         = 'EASTER';
    case ORDINARY_TIME  = 'ORDINARY_TIME';

    /**
     * Ambrosian rite only: Sundays and ferias between the Baptism of the Lord and the start of Lent.
     * Distinguishes rank-4 "after Epiphany" Sundays from the rank-2 Advent/Lent/Easter Sundays.
     */
    case AFTER_EPIPHANY = 'AFTER_EPIPHANY';

    /**
     * Ambrosian rite only: Sundays and ferias between Pentecost and the start of Advent.
     * Distinguishes rank-4 "after Pentecost" Sundays from the rank-2 Advent/Lent/Easter Sundays.
     * Also covers dominical events proper to this period, e.g. `DedicationDuomo`.
     */
    case AFTER_PENTECOST = 'AFTER_PENTECOST';

    /**
     * Patterns for detecting ADVENT events.
     */
    private const array ADVENT_PATTERNS = [
        '/^Advent\d/',
        '/^AdventWeekday/',
    ];

    /**
     * Patterns for detecting CHRISTMAS events.
     */
    private const array CHRISTMAS_PATTERNS = [
        '/^Christmas/',
        '/^HolyFamily$/',
        '/^Epiphany/',
        '/^BaptismLord$/',
        '/^MaryMotherOfGod$/',
        '/^DayAfterEpiphany/',
        '/^Circoncisione$/',
    ];

    /**
     * Patterns for detecting LENT events.
     */
    private const array LENT_PATTERNS = [
        '/^AshWednesday$/',
        '/^(Friday|Saturday|Thursday)AfterAshWednesday$/',
        '/^Lent\d/',
        '/^LentWeekday\d/',
        '/^PalmSun$/',
        '/^(Mon|Tue|Wed)HolyWeek$/',
        '/^HolyThursChrism$/',
        '/^AshesMonday$/',
        '/^SabatoTradSymb$/',
    ];

    /**
     * Patterns for detecting EASTER_TRIDUUM events.
     */
    private const array EASTER_TRIDUUM_PATTERNS = [
        '/^HolyThurs$/',
        '/^GoodFri$/',
        '/^EasterVigil$/',
    ];

    /**
     * Patterns for detecting EASTER events.
     */
    private const array EASTER_PATTERNS = [
        '/^Easter\d*$/',
        '/^(Mon|Tue|Wed|Thu|Fri|Sat)OctaveEaster$/',
        '/^EasterWeekday\d/',
        '/^Ascension$/',
        '/^Pentecost$/',
    ];

    /**
     * Patterns for detecting AFTER_EPIPHANY events (Ambrosian rite only).
     * These keys are distinct from the Roman `Epiphany`/`DayAfterEpiphany*` keys,
     * which remain classified as CHRISTMAS.
     */
    private const array AFTER_EPIPHANY_PATTERNS = ['/^AfterEpiphany/'];

    /**
     * Patterns for detecting AFTER_PENTECOST events (Ambrosian rite only).
     * `DedicationDuomo` (Dedication of the Cathedral of Milan) falls within this
     * period even though it is a dominical solemnity in its own right.
     */
    private const array AFTER_PENTECOST_PATTERNS = [
        '/^DedicationDuomo$/',
        '/^AfterPentecost/',
    ];

    /**
     * Determine the liturgical season for a given temporale event key.
     *
     * @param string $eventKey The temporale event key.
     * @return self The liturgical season for the event.
     */
    public static function forEventKey(string $eventKey): self
    {
        foreach (self::ADVENT_PATTERNS as $pattern) {
            if (preg_match($pattern, $eventKey)) {
                return self::ADVENT;
            }
        }
        foreach (self::CHRISTMAS_PATTERNS as $pattern) {
            if (preg_match($pattern, $eventKey)) {
                return self::CHRISTMAS;
            }
        }
        foreach (self::LENT_PATTERNS as $pattern) {
            if (preg_match($pattern, $eventKey)) {
                return self::LENT;
            }
        }
        foreach (self::EASTER_TRIDUUM_PATTERNS as $pattern) {
            if (preg_match($pattern, $eventKey)) {
                return self::EASTER_TRIDUUM;
            }
        }
        foreach (self::EASTER_PATTERNS as $pattern) {
            if (preg_match($pattern, $eventKey)) {
                return self::EASTER;
            }
        }
        foreach (self::AFTER_EPIPHANY_PATTERNS as $pattern) {
            if (preg_match($pattern, $eventKey)) {
                return self::AFTER_EPIPHANY;
            }
        }
        foreach (self::AFTER_PENTECOST_PATTERNS as $pattern) {
            if (preg_match($pattern, $eventKey)) {
                return self::AFTER_PENTECOST;
            }
        }

        // Default: Ordinary Time (includes OrdSunday*, OrdWeekday*, solemnities like Trinity, CorpusChristi, etc.)
        return self::ORDINARY_TIME;
    }

    /**
     * Translate a liturgical season value into the specified locale.
     *
     * @param string $locale The locale for the translation.
     * @return string The translated liturgical season value.
     */
    public function i18n(string $locale): string
    {
        $isLatin = LitLocale::isLatin($locale);
        return match ($this) {
            /**translators: context = liturgical season */
            LitSeason::ADVENT         => $isLatin ? 'Tempus Adventus'     : _('Advent'),
            /**translators: context = liturgical season */
            LitSeason::CHRISTMAS      => $isLatin ? 'Tempus Nativitatis'  : _('Christmas'),
            /**translators: context = liturgical season */
            LitSeason::LENT           => $isLatin ? 'Tempus Quadragesima' : _('Lent'),
            /**translators: context = liturgical season */
            LitSeason::EASTER_TRIDUUM => $isLatin ? 'Triduum Paschale'    : _('Easter Triduum'),
            /**translators: context = liturgical season */
            LitSeason::EASTER         => $isLatin ? 'Tempus Paschale'     : _('Easter'),
            /**translators: context = liturgical season */
            LitSeason::ORDINARY_TIME  => $isLatin ? 'Tempus per annum'    : _('Ordinary Time'),
            /**translators: context = liturgical season (Ambrosian rite) */
            LitSeason::AFTER_EPIPHANY  => $isLatin ? 'Tempus post Epiphaniam'  : _('After Epiphany'),
            /**translators: context = liturgical season (Ambrosian rite) */
            LitSeason::AFTER_PENTECOST => $isLatin ? 'Tempus post Pentecosten' : _('After Pentecost')
        };
    }
}
