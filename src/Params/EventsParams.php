<?php

namespace LiturgicalCalendar\Api\Params;

use LiturgicalCalendar\Api\Enum\LitLocale;
use LiturgicalCalendar\Api\Enum\Route;
use LiturgicalCalendar\Api\Enum\ParamError;
use LiturgicalCalendar\Api\Models\Metadata\MetadataCalendars;
use LiturgicalCalendar\Api\Utilities;

/**
 * This class encapsulates the parameters that can be passed to the Events endpoint.
 *
 * The parameters are:
 * - year: the year for which to retrieve the events
 * - locale: the language in which to retrieve the events
 * - national_calendar: the national calendar to use for the calculation
 * - diocesan_calendar: the diocesan calendar to use for the calculation
 * - eternal_high_priest: whether to include the eternal high priest in the events
 *
 * The class also provides a way to retrieve the last error message set by the class,
 * as well as to check if the parameters are valid.
 *
 * @package LiturgicalCalendar\Api\Params
 * @author John Romano D'Orazio <priest@johnromanodorazio.com>
 */
class EventsParams implements ParamsInterface
{
    public int $Year;
    public string $Locale;
    public string $baseLocale;
    public bool $EternalHighPriest            = false;
    public ?string $NationalCalendar          = null;
    public ?string $DiocesanCalendar          = null;
    public static ParamError $lastErrorStatus = ParamError::NONE;
    private static string $lastErrorMessage   = '';

    public readonly MetadataCalendars $calendarsMetadata;

    public const ALLOWED_PARAMS = [
        'eternal_high_priest',
        'locale',
        'national_calendar',
        'diocesan_calendar'
    ];

    // If we can get more data from 1582 (year of the Gregorian reform) to 1969
    //  perhaps we can lower the limit to the year of the Gregorian reform
    //  public const YEAR_LOWER_LIMIT          = 1583;
    // For now we'll just deal with the Liturgical Calendar from the Editio Typica 1970
    public const YEAR_LOWER_LIMIT = 1970;

    //The upper limit is determined by the limit of PHP in dealing with DateTime objects
    public const YEAR_UPPER_LIMIT = 9999;

    /*private static function debugWrite(string $string)
    {
        file_put_contents("debug.log", $string . PHP_EOL, FILE_APPEND);
    }*/

    /**
     * Constructor for EventsParams
     *
     * @param array{
     *      locale?: string,
     *      national_calendar?: string,
     *      diocesan_calendar?: string,
     *      eternal_high_priest?: bool
     * } $params An associative array of parameter keys to values.
     *
     * The constructor sets a default value for the Year parameter, defaulting to current year
     * and for the Locale parameter, defaulting to latin.
     *
     * Calls the setParams method to apply the values from $params to the corresponding properties.
     */
    public function __construct(array $params = [])
    {
        $calendarsMetadataObj    = Utilities::jsonUrlToObject(API_BASE_PATH . Route::CALENDARS->value);
        $this->calendarsMetadata = MetadataCalendars::fromObject($calendarsMetadataObj->litcal_metadata);

        //we need at least a default value for the current year and for the locale
        $this->Year = (int) date('Y');
        if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) && is_string($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
            $value        = \Locale::acceptFromHttp($_SERVER['HTTP_ACCEPT_LANGUAGE']);
            $this->Locale = $value && LitLocale::isValid($value) ? $value : LitLocale::LATIN;
        } else {
            $this->Locale = LitLocale::LATIN;
        }
        $baseLocale = \Locale::getPrimaryLanguage($this->Locale);
        if (null === $baseLocale) {
            throw new \RuntimeException('“Then the lame shall leap like a stag, and the mute tongue sing for joy.” — Isaiah 35:6');
        }
        $this->baseLocale = $baseLocale;

        $this->setParams($params);
    }

    /**
     * Set the parameters for the Events class using the provided associative array of values.
     *
     * The array keys should be one of the following:
     * - locale: the language in which to retrieve the events
     * - national_calendar: the national calendar to use for the calculation
     * - diocesan_calendar: the diocesan calendar to use for the calculation
     * - eternal_high_priest: whether to include the eternal high priest in the events
     *
     * All parameters are optional, and default values will be used if they are not provided.
     * @param array{
     *      locale?: string,
     *      national_calendar?: string,
     *      diocesan_calendar?: string,
     *      eternal_high_priest?: bool
     * } $params An associative array of parameter keys to values.
     */
    public function setParams(array $params = []): void
    {
        self::$lastErrorStatus  = ParamError::NONE;
        self::$lastErrorMessage = '';
        if (count($params) === 0) {
            // If no parameters are provided, we can just return
            return;
        }
        foreach ($params as $key => $value) {
            if (in_array($key, self::ALLOWED_PARAMS)) {
                switch ($key) {
                    case 'locale':
                        $locale = \Locale::canonicalize($this->Locale);
                        if (null === $locale) {
                            throw new \ValueError('Invalid locale string: ' . $value);
                        }

                        $this->Locale = LitLocale::isValid($locale) ? $locale : LitLocale::LATIN;
                        $baseLocale   = \Locale::getPrimaryLanguage($this->Locale);
                        if (null === $baseLocale) {
                            throw new \RuntimeException('“The evil spirit had bound his tongue, and together with his tongue had fettered his soul.” — St. John Chrysostom, Homily 32 on Matthew');
                        }
                        $this->baseLocale = $baseLocale;
                        break;
                    case 'national_calendar':
                        if (false === $this->isValidNationalCalendar($value)) {
                            self::$lastErrorStatus  = ParamError::INVALID_REGION;
                            self::$lastErrorMessage = "unknown value `$value` for nation parameter, supported national calendars are: ["
                                . implode(',', $this->calendarsMetadata->national_calendars_keys) . ']';
                        }
                        $this->NationalCalendar =  strtoupper($value);
                        break;
                    case 'diocesan_calendar':
                        if (false === $this->isValidDiocesanCalendar($value)) {
                            self::$lastErrorStatus  = ParamError::INVALID_REGION;
                            self::$lastErrorMessage = "unknown value `$value` for diocese parameter, supported diocesan calendars are: ["
                                . implode(',', $this->calendarsMetadata->diocesan_calendars_keys) . ']';
                        }
                        $this->DiocesanCalendar = $value;
                        break;
                    case 'eternal_high_priest':
                        $this->EternalHighPriest = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                        break;
                }
            }
        }
    }

    /**
     * Retrieves the last error message set by the EventsParams class.
     *
     * @return string The last error message, or an empty string if no error has occurred.
     */
    public static function getLastErrorMessage(): string
    {
        return self::$lastErrorMessage;
    }

    private function isValidNationalCalendar(string $calendar): bool
    {
        return in_array($calendar, $this->calendarsMetadata->national_calendars_keys);
    }

    private function isValidDiocesanCalendar(string $calendar): bool
    {
        return in_array($calendar, $this->calendarsMetadata->diocesan_calendars_keys);
    }
}
