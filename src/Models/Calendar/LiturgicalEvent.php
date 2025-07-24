<?php

namespace LiturgicalCalendar\Api\Models\Calendar;

use LiturgicalCalendar\Api\DateTime;
use LiturgicalCalendar\Api\Enum\LitColor;
use LiturgicalCalendar\Api\Enum\LitEventType;
use LiturgicalCalendar\Api\Enum\LitLocale;
use LiturgicalCalendar\Api\Enum\LitGrade;
use LiturgicalCalendar\Api\Enum\LitCommon;
use LiturgicalCalendar\Api\Enum\LitSeason;
use LiturgicalCalendar\Api\Enum\LitMassVariousNeeds;
use LiturgicalCalendar\Api\Models\RegionalData\NationalData\LitCalItemCreateNewFixed;
use LiturgicalCalendar\Api\Models\RegionalData\NationalData\LitCalItemCreateNewMobile;
use LiturgicalCalendar\Api\Models\RegionalData\DiocesanData\LitCalItemCreateNewFixed as DiocesanLitCalItemCreateNewFixed;
use LiturgicalCalendar\Api\Models\RegionalData\DiocesanData\LitCalItemCreateNewMobile as DiocesanLitCalItemCreateNewMobile;

final class LiturgicalEvent implements \JsonSerializable
{
    public int $event_idx;

    /** The following properties are generally passed in the constructor */
    public string $event_key;
    public string $name;
    public DateTime $date;
    /** @var LitColor[] */
    public array $color = [];
    public LitEventType $type;
    public LitGrade $grade;
    public ?string $grade_display;
    /** @var LitCommons|LitMassVariousNeeds[] */
    public LitCommons|array $common;  //["Proper"] or one or more Commons

    /** The following properties are set externally, but may be optional and therefore may remain null */
    public ?int $psalter_week            = null;
    public ?bool $is_vigil_mass          = null;
    public ?bool $has_vigil_mass         = null;
    public ?bool $has_vesper_i           = null;
    public ?bool $has_vesper_ii          = null;
    public ?string $is_vigil_for         = null;
    public ?string $liturgical_year      = null;
    public ?LitSeason $liturgical_season = null;

    /** The following properties are set based on properties passed in the constructor or on other properties */
    private string $grade_lcl;
    /** @var string[] */
    private array $color_lcl;
    private string $grade_abbr;
    private string $common_lcl;

    private static string $locale = LitLocale::LATIN;
    private static \IntlDateFormatter $dayOfTheWeekShort;
    private static \IntlDateFormatter $dayOfTheWeekLong;
    private static \IntlDateFormatter $monthShort;
    private static \IntlDateFormatter $monthLong;
    private static int $internal_index = 0;

    /**
     * @param string $name
     * @param DateTime $date
     * @param LitColor|LitColor[] $color
     * @param LitEventType $type
     * @param LitGrade $grade
     * @param LitCommons|LitCommon|LitCommon[]|LitMassVariousNeeds|LitMassVariousNeeds[] $common
     * @param string|null $displayGrade
     */
    public function __construct(
        string $name,
        DateTime $date,
        LitColor|array $color = LitColor::GREEN,
        LitEventType $type = LitEventType::FIXED,
        LitGrade $grade = LitGrade::WEEKDAY,
        LitCommons|LitCommon|LitMassVariousNeeds|array $common = LitCommon::NONE,
        ?string $displayGrade = null
    ) {
        $litMassVariousNeedsArray = false;
        if (is_array($common)) {
            $valueTypes = array_values(array_unique(array_map(fn($value) => gettype($value), $common)));
            if (count($valueTypes) > 1) {
                throw new \InvalidArgumentException('Incoherent liturgical common value types provided to create LiturgicalEvent: found multiple types ' . implode(', ', $valueTypes));
            }
            $litMassVariousNeedsArray = $common[0] instanceof LitMassVariousNeeds;
        }
        $this->event_idx     = self::$internal_index++;
        $this->name          = $name;
        $this->date          = $date; //DateTime object
        $this->color         = is_array($color) ? $color : [$color];
        $this->color_lcl     = array_map(fn($item) => LitColor::i18n($item, self::$locale), $this->color);
        $this->type          = $type;
        $this->grade         = $grade;
        $this->grade_lcl     = LitGrade::i18n($this->grade, self::$locale, false, false);
        $this->grade_abbr    = LitGrade::i18n($this->grade, self::$locale, false, true);
        $this->grade_display = $this->grade === LitGrade::HIGHER_SOLEMNITY ? '' : $displayGrade;
        $commons             = $common instanceof LitCommons || $common instanceof LitMassVariousNeeds || $litMassVariousNeedsArray
                                ? $common
                                : (is_array($common) ? LitCommons::create($common) : LitCommons::create([$common]));
        if ($commons instanceof LitCommons) {
            /** @var LitCommons $commons */
            $this->common_lcl = $commons->fullTranslate(self::$locale);
            $this->common     = $commons;
        } elseif ($commons instanceof LitMassVariousNeeds) {
            /** @var LitMassVariousNeeds $commons */
            $this->common_lcl = $commons->fullTranslate(self::$locale === LitLocale::LATIN || self::$locale === LitLocale::LATIN_PRIMARY_LANGUAGE);
            $this->common     = [$commons];
        } elseif ($litMassVariousNeedsArray) {
            /** @var LitMassVariousNeeds[] $commons */
            $commonsLcl       = array_map(fn($item) => $item->fullTranslate(self::$locale === LitLocale::LATIN || self::$locale === LitLocale::LATIN_PRIMARY_LANGUAGE), $commons);
            $this->common_lcl = implode('; ' . _('or') . ' ', $commonsLcl);
            $this->common     = $commons;
        } else {
            $this->common_lcl = '???';
            $this->common     = LitCommons::create([LitCommon::NONE]);
        }
        //LiturgicalEvent::debugWrite( "*** LiturgicalEvent.php *** common vartype = " . gettype( $common ) );
        //LiturgicalEvent::debugWrite( "*** LiturgicalEvent.php *** common vartype is array, value = " . implode( ', ', $common ) );
    }

    /**
     * Set the abbreviation for the grade of this liturgical event.
     *
     * @param string $abbreviation The abbreviation for the grade of this liturgical event.
     * @return void
     */
    public function setGradeAbbreviation(string $abbreviation): void
    {
        $this->grade_abbr = $abbreviation;
    }

    /**
     * Sets the localized grade for this liturgical event.
     *
     * @param string $grade_lcl The localized name of the grade for the liturgical event.
     * @return void
     */
    public function setGradeLocalization(string $grade_lcl): void
    {
        $this->grade_lcl = $grade_lcl;
    }

    /*
    private static function debugWrite( string $string ) {
        file_put_contents( "debug.log", $string . PHP_EOL, FILE_APPEND );
    }
    */

    /**
     * This function is used to finalize the output of the object for serialization as a JSON string.
     * It returns an associative array with the following keys:
     * - event_key: a unique key for the liturgical event
     * - event_idx: the index of the event in the array of festivities
     * - name: the name of the liturgical event
     * - date: a PHP timestamp (seconds since the Unix Epoch) for the date of the liturgical event
     * - color: the liturgical color of the liturgical event
     * - color_lcl: the color of the liturgical event, translated according to the current locale
     * - type: the type of the liturgical event (mobile or fixed)
     * - grade: the grade of the liturgical event (0=weekday, 1=commemoration, 2=optional memorial, 3=memorial, 4=feast, 5=feast of the Lord, 6=solemnity, 7=higher solemnity)
     * - grade_lcl: the grade of the liturgical event, translated according to the current locale
     * - grade_abbr: the abbreviated grade of the liturgical event, translated according to the current locale
     * - grade_display: a nullable string which, when not null, takes precedence over `grade_lcl` or `grade_abbr` for how the liturgical grade should be displayed
     * - common: an array of common prayers associated with the liturgical event
     * - common_lcl: an array of common prayers associated with the liturgical event, translated according to the current locale
     * - day_of_the_week_iso8601: the day of the week of the liturgical event, in the ISO 8601 format (1 for Monday, 7 for Sunday)
     * - month: the month of the liturgical event, in the ISO 8601 format (1 for January, 12 for December)
     * - day: the day of the month of the liturgical event
     * - year: the year of the liturgical event
     * - month_short: the short month name for the liturgical event, translated according to the current locale
     * - month_long: the long month name for the liturgical event, translated according to the current locale
     * - day_of_the_week_short: the short day of the week name for the liturgical event, translated according to the current locale
     * - day_of_the_week_long: the long day of the week name for the liturgical event, translated according to the current locale
     * - liturgical_year: the liturgical year of the liturgical event, if applicable
     * - is_vigil_mass: a boolean indicating whether the liturgical event is a vigil mass, if applicable
     * - is_vigil_for: the liturgical event that the current liturgical event is a vigil for, if applicable
     * - has_vigil_mass: a boolean indicating whether the liturgical event has a vigil mass, if applicable
     * - has_vesper_i: a boolean indicating whether the liturgical event has a first vespers, if applicable
     * - has_vesper_ii: a boolean indicating whether the liturgical event has a second vespers, if applicable
     * - psalter_week: the psalter week of the liturgical event, if applicable
     * - liturgical_season: the liturgical season of the liturgical event, if applicable
     * - liturgical_season_lcl: the liturgical season of the liturgical event, translated according to the current locale
     * @return array{
     *      event_key: string,
     *      event_idx: int,
     *      name: string,
     *      date: int,
     *      color: array<'green'|'pink'|'purple'|'red'|'white'>,
     *      color_lcl: array<string>,
     *      type: 'fixed'|'mobile',
     *      grade: -1|0|1|2|3|4|5|6|7,
     *      grade_lcl: string,
     *      grade_abbr: string,
     *      grade_display: ?string,
     *      common: array<string>,
     *      common_lcl: string,
     *      day_of_the_week_iso8601: int,
     *      month: int,
     *      day: int,
     *      year: int,
     *      month_short: string|false,
     *      month_long: string|false,
     *      day_of_the_week_short: string|false,
     *      day_of_the_week_long: string|false,
     *      liturgical_year?: ?string,
     *      is_vigil_mass?: ?bool,
     *      is_vigil_for?: ?string,
     *      has_vigil_mass?: ?bool,
     *      has_vesper_i?: ?bool,
     *      has_vesper_ii?: ?bool,
     *      psalter_week?: ?int,
     *      liturgical_season?: ?string,
     *      liturgical_season_lcl?: string
     * }
     */
    public function jsonSerialize(): array
    {
        $returnArr = [
            'event_key'               => $this->event_key,
            'event_idx'               => $this->event_idx,
            'name'                    => $this->name,
            //serialize the DateTime   object as a PHP timestamp (seconds since the Unix Epoch)
            'date'                    => (int) $this->date->format('U'),
            'color'                   => array_map(fn ($color) => $color->value, $this->color),
            'color_lcl'               => $this->color_lcl,
            'type'                    => $this->type->value,
            'grade'                   => $this->grade->value,
            'grade_lcl'               => $this->grade_lcl,
            'grade_abbr'              => $this->grade_abbr,
            'grade_display'           => $this->grade_display,
            'common'                  => $this->common instanceof LitCommons
                                        ? $this->common->jsonSerialize()
                                        : array_map(fn (LitMassVariousNeeds $litMassVariousNeeds) => $litMassVariousNeeds->value, $this->common),
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
            $returnArr['liturgical_year'] = $this->liturgical_year;
        }

        if ($this->is_vigil_mass !== null) {
            $returnArr['is_vigil_mass'] = $this->is_vigil_mass;
        }

        if ($this->is_vigil_for !== null) {
            $returnArr['is_vigil_for'] = $this->is_vigil_for;
        }

        if ($this->has_vigil_mass !== null) {
            $returnArr['has_vigil_mass'] = $this->has_vigil_mass;
        }

        if ($this->has_vesper_i !== null) {
            $returnArr['has_vesper_i'] = $this->has_vesper_i;
        }

        if ($this->has_vesper_ii !== null) {
            $returnArr['has_vesper_ii'] = $this->has_vesper_ii;
        }

        if ($this->psalter_week !== null) {
            $returnArr['psalter_week'] = $this->psalter_week;
        }

        if ($this->liturgical_season !== null) {
            $returnArr['liturgical_season']     = $this->liturgical_season->value;
            $returnArr['liturgical_season_lcl'] = LitSeason::i18n($this->liturgical_season, self::$locale);
        }

        return $returnArr;
    }

    /**
     * Sets the locale for this LiturgicalEvent class, affecting the translations of
     * common liturgical texts and the formatting of dates.
     *
     * @param string $locale A valid locale string.
     * @return void
     */
    public static function setLocale(string $locale): void
    {
        if (LitLocale::isValid($locale)) {
            self::$locale            = $locale;
            self::$dayOfTheWeekShort = \IntlDateFormatter::create($locale, \IntlDateFormatter::MEDIUM, \IntlDateFormatter::NONE, 'UTC', \IntlDateFormatter::GREGORIAN, 'EEE');
            self::$dayOfTheWeekLong  = \IntlDateFormatter::create($locale, \IntlDateFormatter::FULL, \IntlDateFormatter::NONE, 'UTC', \IntlDateFormatter::GREGORIAN, 'EEEE');
            self::$monthShort        = \IntlDateFormatter::create($locale, \IntlDateFormatter::MEDIUM, \IntlDateFormatter::NONE, 'UTC', \IntlDateFormatter::GREGORIAN, 'MMM');
            self::$monthLong         = \IntlDateFormatter::create($locale, \IntlDateFormatter::MEDIUM, \IntlDateFormatter::NONE, 'UTC', \IntlDateFormatter::GREGORIAN, 'MMMM');
        }
    }

    /**
     * Creates a new LiturgicalEvent object from an object containing the required properties.
     *
     * The provided object must have the following properties:
     * - name: The name of the liturgical event, as a string.
     * - date: The date of the liturgical event, as a DateTime object or as an integer representing the Unix timestamp.
     * - grade: The grade of the liturgical event, as a LitGrade object or as an integer.
     *
     * Optional properties are:
     * - color: The liturgical color of the liturgical event, as an array of strings or LitColor cases, or as a single string or single LitColor case.
     *   If not provided, defaults to LitColor::GREEN.
     * - common: The liturgical common of the liturgical event, as an array of strings or LitCommon cases, or as a single string or single LitCommon case.
     *   If not provided, defaults to LitCommon::NONE.
     * - type: The type of the liturgical event, as a LitEventType object or as a string.
     *   If not provided, defaults to LitEventType::FIXED.
     * - grade_display: The grade display of the liturgical event, as a string. If not provided, defaults to null.
     *
     * @param \stdClass|LitCalItemCreateNewFixed|LitCalItemCreateNewMobile|DiocesanLitCalItemCreateNewFixed|DiocesanLitCalItemCreateNewMobile $obj
     * @return LiturgicalEvent A new LiturgicalEvent object.
     * @throws \InvalidArgumentException If the provided object does not contain the required properties or if the properties have invalid types.
     */
    public static function fromObject(\stdClass|LitCalItemCreateNewFixed|LitCalItemCreateNewMobile|DiocesanLitCalItemCreateNewFixed|DiocesanLitCalItemCreateNewMobile $obj): LiturgicalEvent
    {
        $requiredProps = ['name', 'date', 'grade'];
        $rowProps      = get_object_vars($obj);
        $arrayDiffKeys = array_keys(array_diff_key(array_flip($requiredProps), $rowProps));

        if (count($arrayDiffKeys) > 0) {
            throw new \InvalidArgumentException('Invalid object provided to create LiturgicalEvent, missing required keys: ' . implode(', ', $arrayDiffKeys));
        }

        if (!isset($obj->name) || !isset($obj->date) || !isset($obj->grade)) {
            throw new \InvalidArgumentException('Invalid object provided to create LiturgicalEvent');
        }

        if (false === is_string($obj->name)) {
            throw new \InvalidArgumentException('Invalid name provided to create LiturgicalEvent');
        }

        if (false === $obj->date instanceof DateTime && false === is_int($obj->date)) {
            throw new \InvalidArgumentException('Invalid date provided to create LiturgicalEvent');
        }

        if (false === $obj->grade instanceof LitGrade && false === is_int($obj->grade)) {
            throw new \InvalidArgumentException('Invalid grade provided to create LiturgicalEvent');
        }

        // When we read data from a JSON file, $obj will be an instance of stdClass,
        // and we need to cast the values to types that will be accepted by the LiturgicalEvent constructor
        if ($obj instanceof \stdClass) {
            if (is_int($obj->date)) {
                $obj->date = new DateTime()->setTimestamp($obj->date)->setTimezone(new \DateTimeZone('UTC'));
            }

            if (property_exists($obj, 'color')) {
                if (is_array($obj->color)) {
                    $valueTypes = array_values(array_unique(array_map(fn($value) => gettype($value), $obj->color)));
                    if (count($valueTypes) > 1) {
                        throw new \InvalidArgumentException('Incoherent color value types provided to create LiturgicalEvent: found multiple types ' . implode(', ', $valueTypes));
                    }
                    if ($valueTypes[0] === 'string') {
                        $obj->color = array_map(fn($value) => LitColor::from($value), $obj->color);
                    } elseif (false === $obj->color[0] instanceof LitColor) {
                        throw new \InvalidArgumentException('Invalid color value types provided to create LiturgicalEvent. Expected type string or LitColor, found ' . $valueTypes[0]);
                    }
                } elseif (is_string($obj->color)) {
                    $obj->color = LitColor::from($obj->color);
                } elseif ($obj->color instanceof LitColor) {
                    // if it's already an instance of LitColor, we don't need to do anything,
                    // however this should probably never be the case with a stdClass object?
                } else {
                    throw new \InvalidArgumentException('Invalid color value type provided to create LiturgicalEvent');
                }
            } else {
                // We ensure a default value
                $obj->color = LitColor::GREEN;
            }

            if (property_exists($obj, 'type')) {
                if (false === $obj->type instanceof LitEventType && false === is_string($obj->type)) {
                    throw new \InvalidArgumentException('Invalid type provided to create LiturgicalEvent');
                }
                if (is_string($obj->type)) {
                    $obj->type = LitEventType::from($obj->type);
                }
            } else {
                // We ensure a default value
                $obj->type = LitEventType::FIXED;
            }

            if (is_int($obj->grade)) {
                $obj->grade = LitGrade::tryFrom($obj->grade) ?? LitGrade::WEEKDAY;
            }

            if (property_exists($obj, 'common')) {
                $commons = self::transformCommons($obj->common);
            } else {
                // We ensure a default value
                $commons = LitCommons::create([]);
            }
        } else {
            $commons = $obj->common;
        }

        if (false === isset($obj->date)) {
            throw new \Exception('Invalid object provided to create LiturgicalEvent: missing date. ' . var_export($obj, true));
        }

        if (false === $obj->date instanceof DateTime) {
            throw new \Exception('Invalid object provided to create LiturgicalEvent: date is not an instance of DateTime. ' . var_export($obj, true));
        }

        if (false === $obj->grade instanceof LitGrade) {
            throw new \Exception('Invalid object provided to create LiturgicalEvent: grade is not an instance of LitGrade');
        }

        return new self(
            $obj->name,
            $obj->date,
            $obj->color,
            $obj->type,
            $obj->grade,
            $commons,
            $obj->grade_display ?? null
        );
    }

    /**
     * Create a new LiturgicalEvent object from an associative array.
     *
     * The array must contain the following keys:
     * - name: The name of the liturgical event, as a string.
     * - date: The date of the liturgical event, as a DateTime object or as an integer representing the Unix timestamp.
     * - grade: The grade of the liturgical event, as a LitGrade object or as an integer.
     *
     * Optional keys are:
     * - color: The liturgical color of the liturgical event, as an array of strings or LitColor cases, or as a single string or single LitColor case.
     *   If not provided, defaults to LitColor::GREEN.
     * - type: The type of the liturgical event, as a LitEventType object or as a string.
     *   If not provided, defaults to LitEventType::FIXED.
     * - common: The liturgical common of the liturgical event, as an array of strings or LitCommon cases, or as a single string or single LitCommon case.
     *   If not provided, defaults to LitCommon::NONE.
     * - grade_display: The grade display of the liturgical event, as a string. If not provided, defaults to null.
     *
     * @param array{
     *     name: string,
     *     date: DateTime|integer,
     *     grade: LitGrade|integer,
     *     color?: LitColor|LitColor[]|string|string[],
     *     type?: LitEventType|string,
     *     common?: LitCommon|LitCommon[]|string|string[],
     *     grade_display?: string
     * } $arr The associative array containing the required properties.
     * @return LiturgicalEvent A new LiturgicalEvent object.
     * @throws \InvalidArgumentException If the provided array does not contain the required properties or if the properties have invalid types.
     */
    public static function fromArray(array $arr): LiturgicalEvent
    {
        if (!isset($arr['name']) || !isset($arr['date']) || !isset($arr['grade'])) {
            throw new \InvalidArgumentException('Invalid array provided to create LiturgicalEvent');
        }

        if (false === is_string($arr['name'])) {
            throw new \InvalidArgumentException('Invalid name provided to create LiturgicalEvent');
        }

        if (false === $arr['date'] instanceof DateTime && false === is_int($arr['date'])) {
            throw new \InvalidArgumentException('Invalid date provided to create LiturgicalEvent');
        }

        if (false === $arr['grade'] instanceof LitGrade && false === is_int($arr['grade'])) {
            throw new \InvalidArgumentException('Invalid grade provided to create LiturgicalEvent');
        }

        if (is_int($arr['date'])) {
            $arr['date'] = new DateTime()->setTimestamp($arr['date'])->setTimezone(new \DateTimeZone('UTC'));
        }

        $colors = LitColor::GREEN;
        if (array_key_exists('color', $arr)) {
            if (is_array($arr['color'])) {
                $valueTypes = array_values(array_unique(array_map(fn($value) => gettype($value), $arr['color'])));
                if (count($valueTypes) > 1) {
                    throw new \InvalidArgumentException('Incoherent color value types provided to create LiturgicalEvent: found multiple types ' . implode(', ', $valueTypes));
                }
                if ($valueTypes[0] === 'string') {
                    /** @var string[] */
                    $colorStrArr = $arr['color'];
                    /** @var LitColor[] */
                    $colors = array_map(
                        static function (string $value): LitColor {
                            return LitColor::from($value);
                        },
                        $colorStrArr
                    );
                } elseif (false === $arr['color'][0] instanceof LitColor) {
                    throw new \InvalidArgumentException('Invalid color value types provided to create LiturgicalEvent. Expected type string or LitColor, found ' . $valueTypes[0]);
                }
            } elseif (false === $arr['color'] instanceof LitColor && false === is_string($arr['color'])) {
                throw new \InvalidArgumentException('Invalid color value type provided to create LiturgicalEvent');
            } elseif (is_string($arr['color'])) {
                $colors = LitColor::from($arr['color']);
            }
        }

        if (array_key_exists('type', $arr)) {
            if (false === $arr['type'] instanceof LitEventType && false === is_string($arr['type'])) {
                throw new \InvalidArgumentException('Invalid type provided to create LiturgicalEvent');
            }
            if (is_string($arr['type'])) {
                $arr['type'] = LitEventType::from($arr['type']);
            }
        } else {
            $arr['type'] = LitEventType::FIXED;
        }

        if (is_int($arr['grade'])) {
            $arr['grade'] = LitGrade::tryFrom($arr['grade']) ?? LitGrade::WEEKDAY;
        }

        if (array_key_exists('common', $arr)) {
            $commons = self::transformCommons($arr['common']);
        } else {
            $commons = LitCommons::create([LitCommon::NONE]);
        }

        return new self(
            $arr['name'],
            $arr['date'],
            $colors,
            $arr['type'],
            $arr['grade'],
            $commons,
            $arr['grade_display'] ?? null
        );
    }

    /**
     * @param array<LitMassVariousNeeds|LitCommon|string>|LitCommons $common
     * @return LitCommons|array<LitMassVariousNeeds>
     */
    private static function transformCommons(array|LitCommons $common): LitCommons|array
    {
        if ($common instanceof LitCommons) {
            return $common;
        }

        if (count($common) === 0) {
            return LitCommons::create([LitCommon::NONE]);
        }

        $valueTypes = array_values(array_unique(array_map(fn($value) => gettype($value), $common)));

        if (count($valueTypes) > 1) {
            throw new \InvalidArgumentException('Incoherent liturgical common value types provided to create LiturgicalEvent: found multiple types ' . implode(', ', $valueTypes));
        }

        if ($valueTypes[0] === 'string') {
            return LitCommons::create($common) ?? array_values(array_map(fn(string $value) => LitMassVariousNeeds::from($value), $common));
        }

        if ($common[0] instanceof LitCommon) {
            return LitCommons::create($common);
        }

        if ($common[0] instanceof LitMassVariousNeeds) {
            return $common;
        }

        throw new \InvalidArgumentException('Invalid common value type provided to create LiturgicalEvent: expected an array of string, of LitCommon cases, or of LitMassVariousNeeds cases');
    }


/**
 * The following functions might be somehow useful
 * Leaving them commented for the time being since we aren't actually using them
 *
    public static function isAdventSeason( LiturgicalEvent $litEvent ) {
        return $litEvent->liturgical_season !== null && $litEvent->liturgical_season === LitSeason::ADVENT;
    }

    public static function isChristmasSeason( LiturgicalEvent $litEvent ) {
        return $litEvent->liturgical_season !== null && $litEvent->liturgical_season === LitSeason::CHRISTMAS;
    }

    public static function isLentSeason( LiturgicalEvent $litEvent ) {
        return $litEvent->liturgical_season !== null && $litEvent->liturgical_season === LitSeason::LENT;
    }

    public static function isEasterTriduum( LiturgicalEvent $litEvent ) {
        return $litEvent->liturgical_season !== null && $litEvent->liturgical_season === LitSeason::EASTER_TRIDUUM;
    }

    public static function isEasterSeason( LiturgicalEvent $litEvent ) {
        return $litEvent->liturgical_season !== null && $litEvent->liturgical_season === LitSeason::EASTER;
    }

    public static function isOrdinaryTime( LiturgicalEvent $litEvent ) {
        return $litEvent->liturgical_season !== null && $litEvent->liturgical_season === LitSeason::ORDINARY_TIME;
    }
 */
}
