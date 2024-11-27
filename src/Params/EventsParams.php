<?php

namespace LiturgicalCalendar\Api\Params;

use LiturgicalCalendar\Api\Enum\LitLocale;
use LiturgicalCalendar\Api\Enum\Route;

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
 * @author John Roman Odron
 */
class EventsParams
{
    public int $Year;
    public bool $EternalHighPriest            = false;
    public ?string $Locale                    = null;
    public ?string $NationalCalendar          = null;
    public ?string $DiocesanCalendar          = null;
    private array $SupportedNationalCalendars = [];
    private array $SupportedDiocesanCalendars = [];
    private static ?object $calendarsMetadata = null;
    private static string $lastError          = '';

    public const ALLOWED_PARAMS  = [
        "eternal_high_priest",
        "locale",
        "national_calendar",
        "diocesan_calendar"
    ];

    // If we can get more data from 1582 (year of the Gregorian reform) to 1969
    //  perhaps we can lower the limit to the year of the Gregorian reform
    //  public const YEAR_LOWER_LIMIT          = 1583;
    // For now we'll just deal with the Liturgical Calendar from the Editio Typica 1970
    public const YEAR_LOWER_LIMIT          = 1970;

    //The upper limit is determined by the limit of PHP in dealing with DateTime objects
    public const YEAR_UPPER_LIMIT          = 9999;

    /*private static function debugWrite(string $string)
    {
        file_put_contents("debug.log", $string . PHP_EOL, FILE_APPEND);
    }*/

    /**
     * Constructor for EventsParams
     *
     * @param array $DATA
     * 
     * The constructor sets a default value for the Year parameter, defaulting to current year
     * and for the Locale parameter, defaulting to latin.
     * 
     * It also sets the SupportedDiocesanCalendars and SupportedNationalCalendars properties
     * by reading the data from the calendars metadata.
     * 
     * If the $DATA array is not empty, it calls the setData method
     * to apply the values from $DATA to the corresponding properties.
     */
    public function __construct(array $DATA = [])
    {
        //we need at least a default value for the current year and for the locale
        $this->Year = (int)date("Y");
        if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
            $value = \Locale::acceptFromHttp($_SERVER['HTTP_ACCEPT_LANGUAGE']);
            $this->Locale = LitLocale::isValid($value) ? $value : LitLocale::LATIN;
        } else {
            $this->Locale = LitLocale::LATIN;
        }

        if (null === EventsParams::$calendarsMetadata) {
            EventsParams::$calendarsMetadata = json_decode(file_get_contents(API_BASE_PATH . Route::CALENDARS));
        }
        $this->SupportedDiocesanCalendars = EventsParams::$calendarsMetadata->diocesan_calendars_keys;
        $this->SupportedNationalCalendars = EventsPArams::$calendarsMetadata->national_calendars_keys;


        if (count($DATA)) {
            $this->setData($DATA);
        }
    }

    /**
     * Set the parameters for the Events class using the provided associative array of values.
     *
     * The array keys should be one of the following:
     * - year: the year for which to retrieve the events
     * - locale: the language in which to retrieve the events
     * - national_calendar: the national calendar to use for the calculation
     * - diocesan_calendar: the diocesan calendar to use for the calculation
     * - eternal_high_priest: whether to include the eternal high priest in the events
     *
     * All parameters are optional, and default values will be used if they are not provided.
     * @param array $DATA an associative array of values to use for the calculation
     * @return bool true if the parameters were successfully set, or false if an error occurred
     */
    public function setData(array $DATA): bool
    {
        foreach ($DATA as $key => $value) {
            if (in_array($key, self::ALLOWED_PARAMS)) {
                switch ($key) {
                    case "locale":
                        $this->Locale = \Locale::canonicalize($this->Locale);
                        $this->Locale = LitLocale::isValid($value) ? $value : LitLocale::LATIN;
                        break;
                    case "national_calendar":
                        if (false === in_array(strtoupper($value), $this->SupportedNationalCalendars)) {
                            self::$lastError = "uknown value `$value` for nation parameter, supported national calendars are: ["
                                . implode(',', $this->SupportedNationalCalendars) . "]";
                            return false;
                        }
                        $this->NationalCalendar =  strtoupper($value);
                        break;
                    case "diocesan_calendar":
                        if (false === in_array($value, $this->SupportedDiocesanCalendars)) {
                            self::$lastError = "uknown value `$value` for diocese parameter, supported diocesan calendars are: ["
                                . implode(',', $this->SupportedDiocesanCalendars) . "]";
                            return false;
                        }
                        $this->DiocesanCalendar = $value;
                        break;
                    case "eternal_high_priest":
                        $this->EternalHighPriest = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                        break;
                }
            }
        }
        return true;
    }

    /**
     * Retrieves the last error message set by the EventsParams class.
     *
     * @return string The last error message, or an empty string if no error has occurred.
     */
    public static function getLastErrorMessage(): string
    {
        return self::$lastError;
    }
}
