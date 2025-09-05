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
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use LiturgicalCalendar\Api\LatinUtils;
use LiturgicalCalendar\Api\Models\Decrees\DecreeItemCreateNewFixed;
use LiturgicalCalendar\Api\Models\Decrees\DecreeItemCreateNewMobile;
use LiturgicalCalendar\Api\Models\Lectionary\ReadingsAbstract;
use LiturgicalCalendar\Api\Models\Lectionary\ReadingsChristmas;
use LiturgicalCalendar\Api\Models\Lectionary\ReadingsCommons;
use LiturgicalCalendar\Api\Models\Lectionary\ReadingsFestiveWithVigil;
use LiturgicalCalendar\Api\Models\Lectionary\ReadingsMultipleSchemas;
use LiturgicalCalendar\Api\Models\Lectionary\ReadingsWithEvening;
use LiturgicalCalendar\Api\Models\PropriumDeSanctisEvent;
use LiturgicalCalendar\Api\Models\PropriumDeTemporeEvent;
use LiturgicalCalendar\Api\Models\RegionalData\NationalData\LitCalItemCreateNewFixed;
use LiturgicalCalendar\Api\Models\RegionalData\NationalData\LitCalItemCreateNewMobile;
use LiturgicalCalendar\Api\Models\RegionalData\DiocesanData\DiocesanLitCalItemCreateNewFixed;
use LiturgicalCalendar\Api\Models\RegionalData\DiocesanData\DiocesanLitCalItemCreateNewMobile;

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
    public private(set) ReadingsAbstract|ReadingsMultipleSchemas|ReadingsChristmas|ReadingsWithEvening|ReadingsFestiveWithVigil|ReadingsCommons $readings;

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

    private static string $locale = LitLocale::LATIN_PRIMARY_LANGUAGE;
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
            if (count($common) === 0) {
                $litMassVariousNeedsArray = false;
            } else {
                $uniqueValueTypes = array_values(array_unique(array_map('gettype', $common)));
                if (count($uniqueValueTypes) > 1) {
                    throw new \InvalidArgumentException(
                        'Incoherent liturgical common value types provided to create LiturgicalEvent: found multiple types ' . implode(', ', $uniqueValueTypes)
                    );
                }
                $litMassVariousNeedsArray = $common[0] instanceof LitMassVariousNeeds;
            }
        }

        $this->event_idx     = self::$internal_index++;
        $this->name          = $name;
        $this->date          = $date; //DateTime object
        $this->color         = is_array($color) ? $color : [$color];
        $this->color_lcl     = array_map(
            function (LitColor $item): string {
                return $item->i18n(self::$locale);
            },
            $this->color
        );
        $this->type          = $type;
        $this->grade         = $grade;
        $this->grade_lcl     = $this->grade->i18n(self::$locale, false, false);
        $this->grade_abbr    = $this->grade->i18n(self::$locale, false, true);
        $this->grade_display = $this->grade === LitGrade::HIGHER_SOLEMNITY ? '' : $displayGrade;
        $commons             = $common instanceof LitCommons || $common instanceof LitMassVariousNeeds || $litMassVariousNeedsArray
                                ? $common
                                : ( is_array($common) ? LitCommons::create($common) : LitCommons::create([$common]) );
        if ($commons instanceof LitCommons) {
            $this->common     = $commons;
            $this->common_lcl = $commons->fullTranslate(self::$locale);
        } elseif ($commons instanceof LitMassVariousNeeds) {
            $this->common     = [$commons];
            $this->common_lcl = $commons->fullTranslate(self::$locale === LitLocale::LATIN_PRIMARY_LANGUAGE);
        } elseif ($litMassVariousNeedsArray) {
            /** @var LitMassVariousNeeds[] $commons */
            $this->common = $commons;
            $commonsLcl   = array_map(
                function (LitMassVariousNeeds $item): string {
                    return $item->fullTranslate(self::$locale === LitLocale::LATIN_PRIMARY_LANGUAGE);
                },
                $commons
            );

            /**translators: when there are multiple possible commons, this will be the glue "[; or] From the Common of..." */
            $or               = self::$locale === LitLocale::LATIN_PRIMARY_LANGUAGE ? 'vel' : _('or');
            $this->common_lcl = implode('; ' . $or . ' ', $commonsLcl);
        } else {
            /** @var LitCommons $commons */
            $commons          = LitCommons::create([LitCommon::NONE]);
            $this->common     = $commons;
            $this->common_lcl = '???';
        }
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

    /**
     * Sets the readings for this liturgical event.
     *
     * @param ReadingsAbstract|ReadingsMultipleSchemas|ReadingsChristmas|ReadingsWithEvening|ReadingsFestiveWithVigil|ReadingsCommons $readings The readings for this liturgical event.
     * @return void
     */
    public function setReadings(ReadingsAbstract|ReadingsMultipleSchemas|ReadingsChristmas|ReadingsWithEvening|ReadingsFestiveWithVigil|ReadingsCommons $readings): void
    {
        $this->readings = $readings;
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
     * - event_idx: the index of the event in the array of liturgical events
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
     * - readings: the lectionary readings associated with the liturgical event
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
     *      color: array<'green'|'rose'|'purple'|'red'|'white'>,
     *      color_lcl: string[],
     *      type: 'fixed'|'mobile',
     *      grade: -1|0|1|2|3|4|5|6|7,
     *      grade_lcl: string,
     *      grade_abbr: string,
     *      grade_display: ?string,
     *      common: string[],
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
        if (false === isset($this->readings)) {
            throw new ValidationException('Readings not set for liturgical event `' . $this->name . '` with event_key: ' . $this->event_key);
        }
        $returnArr = [
            'event_key'               => $this->event_key,
            'event_idx'               => $this->event_idx,
            'name'                    => $this->name,
            //serialize the DateTime   object as a PHP timestamp (seconds since the Unix Epoch)
            'color'                   => array_map(fn ($color) => $color->value, $this->color),
            'color_lcl'               => $this->color_lcl,
            'grade'                   => $this->grade->value,
            'grade_lcl'               => $this->grade_lcl,
            'grade_abbr'              => $this->grade_abbr,
            'grade_display'           => $this->grade_display,
            'common'                  => $this->common instanceof LitCommons
                                            ? $this->common->jsonSerialize()
                                            : array_map(fn (LitMassVariousNeeds $litMassVariousNeeds) => $litMassVariousNeeds->value, $this->common),
            'common_lcl'              => $this->common_lcl,
            'type'                    => $this->type->value,
            'date'                    => (int) $this->date->format('U'),
            'year'                    => (int) $this->date->format('Y'),
            'month'                   => (int) $this->date->format('n'), //1 for January, 12 for December
            'month_short'             => LitLocale::$PRIMARY_LANGUAGE === LitLocale::LATIN_PRIMARY_LANGUAGE
                                            ? LatinUtils::LATIN_MONTHS_ABBR[(int) $this->date->format('n')]
                                            : self::$monthShort->format($this->date->format('U')),
            'month_long'              => LitLocale::$PRIMARY_LANGUAGE === LitLocale::LATIN_PRIMARY_LANGUAGE
                                            ? LatinUtils::LATIN_MONTHS[(int) $this->date->format('n')]
                                            : self::$monthLong->format($this->date->format('U')),
            'day'                     => (int) $this->date->format('j'),
            'day_of_the_week_iso8601' => (int) $this->date->format('N'), //1 for Monday, 7 for Sunday
            'day_of_the_week_short'   => LitLocale::$PRIMARY_LANGUAGE === LitLocale::LATIN_PRIMARY_LANGUAGE
                                            ? LatinUtils::LATIN_WEEKDAYS_ABBR[$this->date->format('w')]
                                            : self::$dayOfTheWeekShort->format($this->date->format('U')),
            'day_of_the_week_long'    => LitLocale::$PRIMARY_LANGUAGE === LitLocale::LATIN_PRIMARY_LANGUAGE
                                            ? LatinUtils::LATIN_DAYOFTHEWEEK[$this->date->format('w')]
                                            : self::$dayOfTheWeekLong->format($this->date->format('U')),
            'readings'                => $this->readings->jsonSerialize()
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
            $returnArr['liturgical_season_lcl'] = $this->liturgical_season->i18n(self::$locale);
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
            self::$locale     = $locale;
            $dowShortFormat   = \IntlDateFormatter::create($locale, \IntlDateFormatter::MEDIUM, \IntlDateFormatter::NONE, 'UTC', \IntlDateFormatter::GREGORIAN, 'EEE');
            $dowLongFormat    = \IntlDateFormatter::create($locale, \IntlDateFormatter::FULL, \IntlDateFormatter::NONE, 'UTC', \IntlDateFormatter::GREGORIAN, 'EEEE');
            $monthShortFormat = \IntlDateFormatter::create($locale, \IntlDateFormatter::MEDIUM, \IntlDateFormatter::NONE, 'UTC', \IntlDateFormatter::GREGORIAN, 'MMM');
            $monthLongFormat  = \IntlDateFormatter::create($locale, \IntlDateFormatter::MEDIUM, \IntlDateFormatter::NONE, 'UTC', \IntlDateFormatter::GREGORIAN, 'MMMM');
            if (
                false === $dowShortFormat instanceof \IntlDateFormatter
                || false === $dowLongFormat instanceof \IntlDateFormatter
                || false === $monthShortFormat instanceof \IntlDateFormatter
                || false === $monthLongFormat instanceof \IntlDateFormatter
            ) {
                throw new \InvalidArgumentException('The provided locale could not be used to format the day of the week or the month: ' . $locale . '.');
            }
            self::$dayOfTheWeekShort = $dowShortFormat;
            self::$dayOfTheWeekLong  = $dowLongFormat;
            self::$monthShort        = $monthShortFormat;
            self::$monthLong         = $monthLongFormat;
        } else {
            throw new \InvalidArgumentException('The provided locale is not valid: ' . $locale . '.');
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
     * @param \stdClass|LitCalItemCreateNewFixed|LitCalItemCreateNewMobile|DiocesanLitCalItemCreateNewFixed|DiocesanLitCalItemCreateNewMobile|DecreeItemCreateNewFixed|DecreeItemCreateNewMobile|PropriumDeTemporeEvent|PropriumDeSanctisEvent $obj
     * @return LiturgicalEvent A new LiturgicalEvent object.
     * @throws \InvalidArgumentException If the provided object does not contain the required properties or if the properties have invalid types.
     */
    public static function fromObject(\stdClass|LitCalItemCreateNewFixed|LitCalItemCreateNewMobile|DiocesanLitCalItemCreateNewFixed|DiocesanLitCalItemCreateNewMobile|DecreeItemCreateNewFixed|DecreeItemCreateNewMobile|PropriumDeTemporeEvent|PropriumDeSanctisEvent $obj): LiturgicalEvent
    {
        $requiredProps = ['name', 'date', 'grade'];
        $currentProps  = array_keys(get_object_vars($obj));
        $missingKeys   = array_diff($requiredProps, $currentProps);

        if (count($missingKeys) > 0) {
            throw new \InvalidArgumentException('Invalid object provided to create LiturgicalEvent, missing required keys: ' . implode(', ', $missingKeys));
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
                    $valueTypes = array_values(array_unique(array_map('gettype', $obj->color)));
                    if (count($valueTypes) > 1) {
                        throw new \InvalidArgumentException('Incoherent color value types provided to create LiturgicalEvent: found multiple types ' . implode(', ', $valueTypes));
                    }
                    if ($valueTypes[0] === 'string') {
                        /** @var string[] $colors */
                        $colors = $obj->color;
                        $color  = array_map(
                            function (string $value): LitColor {
                                return LitColor::from($value);
                            },
                            $colors
                        );
                    } else {
                        throw new \InvalidArgumentException('Invalid color value types provided to create LiturgicalEvent. Expected type string or LitColor, found ' . $valueTypes[0]);
                    }
                } elseif (is_string($obj->color)) {
                    $color = [LitColor::from($obj->color)];
                } else {
                    throw new \InvalidArgumentException('Invalid color value type provided to create LiturgicalEvent');
                }
            } else {
                // We ensure a default value
                $color = [LitColor::GREEN];
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

            if (property_exists($obj, 'common') && ( is_array($obj->common) || is_string($obj->common) )) {
                if (is_array($obj->common)) {
                    /** @var array<LitCommon|LitMassVariousNeeds|string> $common */
                    $common = $obj->common;
                } else {
                    $common = [$obj->common];
                }
                $commons = self::transformCommons($common);
            } else {
                // We ensure a default value
                /** @var LitCommons $commons */
                $commons = LitCommons::create([]);
            }

            if (false === isset($obj->grade_display)) {
                $obj->grade_display = null;
            }
        } else {
            if (isset($obj->common)) {
                $commons = $obj->common;
            } else {
                // We ensure a default value
                /** @var LitCommons $commons */
                $commons = LitCommons::create([]);
            }
            if (isset($obj->color)) {
                $color = $obj->color;
            } else {
                // We ensure a default value
                $color = [LitColor::GREEN];
            }
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

        if (false === $obj->type instanceof LitEventType) {
            throw new \Exception('Invalid object provided to create LiturgicalEvent: type is not an instance of LitEventType');
        }

        if (false === self::isValidCommonsConstructorValue($commons)) {
            throw new \Exception('Invalid object provided to create LiturgicalEvent...');
        }

        $grade_display = null;
        if (property_exists($obj, 'grade_display')) {
            if (false === is_string($obj->grade_display) && false === is_null($obj->grade_display)) {
                throw new \Exception('Invalid object provided to create LiturgicalEvent: grade_display is not a string or null');
            }
            $grade_display = $obj->grade_display;
        }

        return new self(
            $obj->name,
            $obj->date,
            $color,
            $obj->type,
            $obj->grade,
            $commons,
            $grade_display
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
     *     common?: LitCommons|LitCommon[]|LitMassVariousNeeds[]|string[],
     *     grade_display?: string|null,
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
                $valueTypes = array_values(array_unique(array_map('gettype', $arr['color'])));
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
            /** @var LitCommons $commons */
            $commons = LitCommons::create([LitCommon::NONE]);
        }

        if (false === self::isValidCommonsConstructorValue($commons)) {
            throw new \Exception('Invalid object provided to create LiturgicalEvent...');
        }

        $grade_display = null;
        if (array_key_exists('grade_display', $arr)) {
            if (false === is_string($arr['grade_display']) && false === is_null($arr['grade_display'])) {
                throw new \Exception('Invalid object provided to create LiturgicalEvent: grade_display is not a string or null');
            }
            $grade_display = $arr['grade_display'];
        }

        return new self(
            $arr['name'],
            $arr['date'],
            $colors,
            $arr['type'],
            $arr['grade'],
            $commons,
            $grade_display
        );
    }

    /**
     * @param LitCommons|array<LitMassVariousNeeds|LitCommon|string> $common
     * @return LitCommons|array<LitMassVariousNeeds>
     */
    private static function transformCommons(LitCommons|array $common): LitCommons|array
    {
        if ($common instanceof LitCommons) {
            return $common;
        }

        if (count($common) === 0) {
            /** @var LitCommons $commons */
            $commons = LitCommons::create([LitCommon::NONE]);
            return $commons;
        }

        $valueTypes = array_values(array_unique(array_map('gettype', $common)));

        if (count($valueTypes) > 1) {
            throw new \InvalidArgumentException('Incoherent liturgical common value types provided to create LiturgicalEvent: found multiple types ' . implode(', ', $valueTypes));
        }

        if ($valueTypes[0] === 'string') {
            /** @var string[] $common */
            return LitCommons::create($common) ?? array_map(
                function (string $value): LitMassVariousNeeds {
                    return LitMassVariousNeeds::from($value);
                },
                $common
            );
        }

        if (self::allInstancesOf($common, LitCommon::class)) {
            /** @var LitCommons $commons */
            $commons = LitCommons::create($common);
            return $commons;
        }

        if (self::allInstancesOf($common, LitMassVariousNeeds::class)) {
            /** @var LitMassVariousNeeds[] $common */
            return $common;
        }

        throw new \InvalidArgumentException('Invalid common value type provided to create LiturgicalEvent: expected an array of string, of LitCommon cases, or of LitMassVariousNeeds cases');
    }

    /**
     * @template T
     * @param array<mixed> $array
     * @param class-string<T> $className
     * @return bool
     */
    private static function allInstancesOf(array $array, string $className): bool
    {
        foreach ($array as $item) {
            if (!$item instanceof $className) {
                return false;
            }
        }
        return true;
    }

    /**
     * Checks if the given value is a valid "Commons" value for construction of a LiturgicalEvent.
     * A valid "Commons" value is one of the following:
     * - An instance of LitCommons
     * - An instance of LitCommon
     * - An instance of LitMassVariousNeeds
     * - An array containing one or more LitCommon and/or LitMassVariousNeeds instances
     * If the value is not valid, an exception is thrown.
     * @param mixed $commons The value to test.
     * @return bool True if the value is valid, otherwise false.
     */
    private static function isValidCommonsConstructorValue(mixed $commons): bool
    {
        $isValid =
            $commons instanceof LitCommons
            || $commons instanceof LitCommon
            || $commons instanceof LitMassVariousNeeds
            || (
                is_array($commons)
                && array_is_list($commons)
                && count($commons) > 0
                && (
                    self::allInstancesOf($commons, LitCommon::class)
                    || self::allInstancesOf($commons, LitMassVariousNeeds::class)
                )
            );

        if (false === $isValid) {
            throw new \Exception('Invalid object provided to create LiturgicalEvent...');
        }
        return $isValid;
    }

    public function getCommonLcl(): string
    {
        return $this->common_lcl;
    }
}
