<?php

namespace LiturgicalCalendar\Api;

use LiturgicalCalendar\Api\DateTime;
use LiturgicalCalendar\Api\Enum\LitColor;
use LiturgicalCalendar\Api\Enum\LitFeastType;
use LiturgicalCalendar\Api\Enum\LitLocale;
use LiturgicalCalendar\Api\Enum\LitGrade;
use LiturgicalCalendar\Api\Enum\LitCommon;
use LiturgicalCalendar\Api\Enum\LitSeason;

class Festivity implements \JsonSerializable
{
    public static $eventidx = 0;

    public int $idx;

    /** The following properties are generally passed in the constructor */
    public string $name;
    public DateTime $date;
    public array $color = [];
    public string $type;
    public int $grade;
    public string $grade_display;
    public array $common;  //"Proper" or specified common(s) of saints...

    /** The following properties are set externally, but may be optional and therefore may remain null */
    public ?int $psalter_week         = null;
    public ?bool $is_vigil_mass       = null;
    public ?bool $has_vigil_mass      = null;
    public ?bool $has_vesper_i        = null;
    public ?bool $has_vesper_ii       = null;
    public ?string $is_vigil_for      = null;
    public ?string $liturgical_year   = null;
    public ?string $liturgical_season = null;

    /** The following properties are set based on properties passed in the constructor or on properties set externally*/
    public array $color_lcl;
    public string $grade_lcl;
    public string $common_lcl;

    private static string $locale   = LitLocale::LATIN;
    private static LitGrade $LitGrade;
    private static LitCommon $LitCommon;
    private static \IntlDateFormatter $dayOfTheWeekShort;
    private static \IntlDateFormatter $dayOfTheWeekLong;
    private static \IntlDateFormatter $monthShort;
    private static \IntlDateFormatter $monthLong;

    public function __construct(
        string $name,
        DateTime $date,
        string|array $color = [ '???' ],
        string $type = '???',
        int $grade = LitGrade::WEEKDAY,
        string|array $common = [ '???' ],
        string $displayGrade = ''
    ) {
        $this->idx          = self::$eventidx++;
        $this->name         = $name;
        $this->date         = $date; //DateTime object
        if (is_array($color)) {
            if (LitColor::areValid($color)) {
                $this->color = $color;
            }
        } elseif (is_string($color)) {
            $_color             = strtolower($color);
            //the color string can contain multiple colors separated by a comma, when there are multiple commons to choose from for that festivity
            $this->color        = strpos($_color, ",") && LitColor::areValid(explode(",", $_color)) ? explode(",", $_color) : ( LitColor::isValid($_color) ? [ $_color ] : [ '???' ] );
        }
        $this->color_lcl    = array_map(fn($item) => LitColor::i18n($item, self::$locale), $this->color);
        $_type              = strtolower($type);
        $this->type         = LitFeastType::isValid($_type) ? $_type : '???';
        $this->grade        = $grade >= LitGrade::WEEKDAY && $grade <= LitGrade::HIGHER_SOLEMNITY ? $grade : -1;
        $this->grade_display = $displayGrade;
        $this->grade_lcl     = self::$LitGrade->i18n($this->grade, false);
        //Festivity::debugWrite( "*** Festivity.php *** common vartype = " . gettype( $common ) );
        if (is_string($common)) {
            //Festivity::debugWrite( "*** Festivity.php *** common vartype is string, value = $common" );
            $this->common       = LitCommon::areValid(explode(",", $common)) ? explode(",", $common) : [];
        } elseif (is_array($common)) {
            //Festivity::debugWrite( "*** Festivity.php *** common vartype is array, value = " . implode( ', ', $common ) );
            if (LitCommon::areValid($common)) {
                $this->common = $common;
            } else {
                //Festivity::debugWrite( "*** Festivity.php *** common values have not passed the validity test!" );
                $this->common = [];
            }
        }
        $this->common_lcl = self::$LitCommon->c($this->common);
    }
    /*
    private static function debugWrite( string $string ) {
        file_put_contents( "debug.log", $string . PHP_EOL, FILE_APPEND );
    }
    */

    /* * * * * * * * * * * * * * * * * * * * * * * * *
     * Funzione statica di comparazione
     * in vista dell'ordinamento di un array di oggetti Festivity
     * Tiene conto non soltanto del valore della data,
     * ma anche del grado della festa qualora ci fosse una concomitanza
     * * * * * * * * * * * * * * * * * * * * * * * * * */
    public static function compDate(Festivity $a, Festivity $b)
    {
        if ($a->date == $b->date) {
            if ($a->grade == $b->grade) {
                return 0;
            }
            return ( $a->grade > $b->grade ) ? +1 : -1;
        }
        return ( $a->date > $b->date ) ? +1 : -1;
    }

    /**
     * @inheritDoc
     *
     * This function is used to output the object as a JSON string.
     * It returns an associative array with the following keys:
     * - event_idx: the index of the event in the array of festivities
     * - name: the name of the festivity
     * - date: a PHP timestamp (seconds since the Unix Epoch) for the date of the festivity
     * - color: the color of the festivity
     * - color_lcl: the color of the festivity, translated according to the current locale
     * - type: the type of the festivity
     * - grade: the grade of the festivity
     * - grade_lcl: the grade of the festivity, translated according to the current locale
     * - grade_display: a boolean indicating whether the grade of the festivity should be displayed
     * - common: an array of common prayers associated with the festivity
     * - common_lcl: an array of common prayers associated with the festivity, translated according to the current locale
     * - day_of_the_week_iso8601: the day of the week of the festivity, in the ISO 8601 format (1 for Monday, 7 for Sunday)
     * - month: the month of the festivity, in the ISO 8601 format (1 for January, 12 for December)
     * - day: the day of the month of the festivity
     * - year: the year of the festivity
     * - month_short: the short month name for the festivity, translated according to the current locale
     * - month_long: the long month name for the festivity, translated according to the current locale
     * - day_of_the_week_short: the short day of the week name for the festivity, translated according to the current locale
     * - day_of_the_week_long: the long day of the week name for the festivity, translated according to the current locale
     * - liturgical_year: the liturgical year of the festivity, if applicable
     * - is_vigil_mass: a boolean indicating whether the festivity is a vigil mass, if applicable
     * - is_vigil_for: the festivity that the current festivity is a vigil for, if applicable
     * - has_vigil_mass: a boolean indicating whether the festivity has a vigil mass, if applicable
     * - has_vesper_i: a boolean indicating whether the festivity has a first vespers, if applicable
     * - has_vesper_ii: a boolean indicating whether the festivity has a second vespers, if applicable
     * - psalter_week: the psalter week of the festivity, if applicable
     * - liturgical_season: the liturgical season of the festivity, if applicable
     * - liturgical_season_lcl: the liturgical season of the festivity, translated according to the current locale
     */
    public function jsonSerialize(): array
    {
        $returnArr = [
            'event_idx'                => $this->idx,
            'name'                    => $this->name,
            //serialize the DateTime   object as a PHP timestamp (seconds since the Unix Epoch)
            'date'                    => (int) $this->date->format('U'),
            'color'                   => $this->color,
            'color_lcl'               => $this->color_lcl,
            'type'                    => $this->type,
            'grade'                   => $this->grade,
            'grade_lcl'               => $this->grade_lcl,
            'grade_display'           => $this->grade_display,
            'common'                  => $this->common,
            'common_lcl'              => $this->common_lcl,
            'day_of_the_week_iso8601' => (int) $this->date->format('N'), //1 for Monday, 7 for Sunday
            'month'                   => (int) $this->date->format('n'), //1 for January, 12 for December
            'day'                     => (int) $this->date->format('j'),
            'year'                    => (int) $this->date->format('Y'),
            'month_short'             => self::$monthShort->format($this->date->format('U')),
            'month_long'              => self::$monthLong->format($this->date->format('U')),
            'day_of_the_week_short'   => self::$dayOfTheWeekShort->format($this->date->format('U')),
            'day_of_the_week_long'    => self::$dayOfTheWeekLong->format($this->date->format('U'))
        ];
        if ($this->liturgical_year !== null) {
            $returnArr['liturgical_year']    = $this->liturgical_year;
        }
        if ($this->is_vigil_mass !== null) {
            $returnArr['is_vigil_mass']       = $this->is_vigil_mass;
        }
        if ($this->is_vigil_for !== null) {
            $returnArr['is_vigil_for']       = $this->is_vigil_for;
        }
        if ($this->has_vigil_mass !== null) {
            $returnArr['has_vigil_mass']      = $this->has_vigil_mass;
        }
        if ($this->has_vesper_i !== null) {
            $returnArr['has_vesper_i']        = $this->has_vesper_i;
        }
        if ($this->has_vesper_ii !== null) {
            $returnArr['has_vesper_ii']       = $this->has_vesper_ii;
        }
        if ($this->psalter_week !== null) {
            $returnArr['psalter_week']       = $this->psalter_week;
        }
        if ($this->liturgical_season !== null) {
            $returnArr['liturgical_season']  = $this->liturgical_season;
            $returnArr['liturgical_season_lcl'] = LitSeason::i18n($this->liturgical_season, self::$locale);
        }
        return $returnArr;
    }

    /**
     * Sets the locale for this Festivity class, affecting the translations of
     * common liturgical texts and the formatting of dates.
     *
     * @param string $locale A valid locale string.
     * @return void
     */
    public static function setLocale(string $locale): void
    {
        if (LitLocale::isValid($locale)) {
            self::$locale               = $locale;
            self::$LitGrade             = new LitGrade($locale);
            self::$LitCommon            = new LitCommon($locale);
            self::$dayOfTheWeekShort    = \IntlDateFormatter::create($locale, \IntlDateFormatter::MEDIUM, \IntlDateFormatter::NONE, 'UTC', \IntlDateFormatter::GREGORIAN, "EEE");
            self::$dayOfTheWeekLong     = \IntlDateFormatter::create($locale, \IntlDateFormatter::FULL, \IntlDateFormatter::NONE, 'UTC', \IntlDateFormatter::GREGORIAN, "EEEE");
            self::$monthShort           = \IntlDateFormatter::create($locale, \IntlDateFormatter::MEDIUM, \IntlDateFormatter::NONE, 'UTC', \IntlDateFormatter::GREGORIAN, "MMM");
            self::$monthLong            = \IntlDateFormatter::create($locale, \IntlDateFormatter::MEDIUM, \IntlDateFormatter::NONE, 'UTC', \IntlDateFormatter::GREGORIAN, "MMMM");
        }
    }
/**
 * The following functions might be somehow useful
 * Leaving them commented for the time being since we aren't actually using them
 *
    public static function isAdventSeason( Festivity $festivity ) {
        return $festivity->liturgical_season !== null && $festivity->liturgical_season === LitSeason::ADVENT;
    }

    public static function isChristmasSeason( Festivity $festivity ) {
        return $festivity->liturgical_season !== null && $festivity->liturgical_season === LitSeason::CHRISTMAS;
    }

    public static function isLentSeason( Festivity $festivity ) {
        return $festivity->liturgical_season !== null && $festivity->liturgical_season === LitSeason::LENT;
    }

    public static function isEasterTriduum( Festivity $festivity ) {
        return $festivity->liturgical_season !== null && $festivity->liturgical_season === LitSeason::EASTER_TRIDUUM;
    }

    public static function isEasterSeason( Festivity $festivity ) {
        return $festivity->liturgical_season !== null && $festivity->liturgical_season === LitSeason::EASTER;
    }

    public static function isOrdinaryTime( Festivity $festivity ) {
        return $festivity->liturgical_season !== null && $festivity->liturgical_season === LitSeason::ORDINARY_TIME;
    }
 */
}
