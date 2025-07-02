<?php

namespace LiturgicalCalendar\Api\Params;

use LiturgicalCalendar\Api\Router;
use LiturgicalCalendar\Api\Enum\YearType;
use LiturgicalCalendar\Api\Enum\Epiphany;
use LiturgicalCalendar\Api\Enum\Ascension;
use LiturgicalCalendar\Api\Enum\CorpusChristi;
use LiturgicalCalendar\Api\Enum\LitLocale;
use LiturgicalCalendar\Api\Enum\ReturnType;
use LiturgicalCalendar\Api\Enum\Route;
use LiturgicalCalendar\Api\Enum\StatusCode;
use LiturgicalCalendar\Api\Paths\Calendar;

/**
 * Class CalendarParams
 *
 * This class is responsible for handling the parameters provided to the LiturgicalCalendar\Api\PathsCalendar class.
 *
 * The class is initialized with a set of parameters passed in from the API request. These parameters
 * are used to determine which calendar data to retrieve or update or delete.
 *
 * @package LiturgicalCalendar\Api\Params
 */
class CalendarParams
{
    private ?object $calendars;
    public int $Year;
    public string $YearType              = YearType::LITURGICAL;
    public string $Epiphany              = Epiphany::JAN6;
    public string $Ascension             = Ascension::THURSDAY;
    public string $CorpusChristi         = CorpusChristi::THURSDAY;
    public bool $EternalHighPriest       = false;
    public ?string $ReturnType           = null;
    public ?string $Locale               = null;
    public ?string $NationalCalendar     = null;
    public ?string $DiocesanCalendar     = null;

    public const ALLOWED_PARAMS  = [
        "year",
        "year_type",
        "epiphany",
        "ascension",
        "corpus_christi",
        "eternal_high_priest",
        "locale",
        "return_type",
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
     * Validates parameter values that are expected to be strings.
     * Produces a 400 Bad Request error if the value is not a string
     *
     * @param string $key
     * @param mixed $value
     * @return string
     */
    private static function validateStringValue(string $key, mixed $value): string
    {
        if (gettype($value) !== 'string') {
            $description = "Expected value of type String for parameter `{$key}`, instead found type " . gettype($value);
            Calendar::produceErrorResponse(StatusCode::BAD_REQUEST, $description);
        }
        return filter_var($value, FILTER_SANITIZE_SPECIAL_CHARS);
    }

    /**
     * Constructor for CalendarParams
     *
     * Produces a 503 Service Unavailable error if the API was unable to load calendars metadata
     *
     * - Loads calendars metadata
     * - Sets a default value for the Year parameter, defaulting to current year
     * - Sets a default value for language starting from Accept-Language header (a `locale` parameter can then override this value)
     * - Applies the values from $DATA to the corresponding properties
     * @param array $DATA
     */
    public function __construct(array $DATA)
    {
        // API_BASE_PATH should have been defined in index.php
        if (defined('API_BASE_PATH')) {
            $calendarsRoute = API_BASE_PATH . Route::CALENDARS->value;
        } else {
            $calendarsRoute = Router::determineBasePath() . Route::CALENDARS->value;
        }

        if (Router::isLocalhost()) {
            $concurrentServiceWorkers = getenv('PHP_CLI_SERVER_WORKERS');
            if ((int)$concurrentServiceWorkers < 2) {
                $server_name = $_SERVER['SERVER_NAME'] ?? $_SERVER['SERVER_ADDR'];
                Calendar::produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, "The API will be unable to load calendars metadata from {$calendarsRoute}, because there are not enough concurrent service workers. Perhaps set the `PHP_CLI_SERVER_WORKERS` environment variable to a value greater than 1? E.g. `PHP_CLI_SERVER_WORKERS=2 php -S $server_name`.");
            }
        }

        $metadataRaw = file_get_contents($calendarsRoute);
        if ($metadataRaw) {
            $metadata = json_decode($metadataRaw);
            if (JSON_ERROR_NONE === json_last_error() && property_exists($metadata, 'litcal_metadata')) {
                $this->calendars = $metadata->litcal_metadata;
            } else {
                Calendar::produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, "The API was unable to initialize calendars metadata: " . json_last_error_msg());
            }
        } else {
            Calendar::produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, "The API was unable to load calendars metadata from {$calendarsRoute}");
        }

        $this->Year = (int)date("Y");

        if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
            $value = \Locale::acceptFromHttp($_SERVER['HTTP_ACCEPT_LANGUAGE']);
            $this->Locale = LitLocale::isValid($value) ? $value : LitLocale::LATIN;
        } else {
            $this->Locale = LitLocale::LATIN;
        }

        $this->setData($DATA);
    }

    /**
     * Sets the parameters for the Calendar class using the provided associative array of values.
     *
     * The array keys should be one of the following:
     * - year: the year for which to calculate the calendar
     * - epiphany: whether Epiphany should be calculated on January 6th or on the Sunday between January 2nd and January 8th
     * - ascension: whether Ascension should be calculated on Thursday or on Sunday
     * - corpus_christi: whether Corpus Christi should be calculated on Thursday or on Sunday
     * - locale: the language in which to calculate the calendar
     * - return_type: the format in which to return the calendar, either JSON, XML, ICS, or YML
     * - national_calendar: the national calendar to use for the calculation
     * - diocesan_calendar: the diocesan calendar to use for the calculation
     * - year_type: whether to calculate the calendar based on the Civil year or the Liturgical year
     * - eternal_high_priest: whether to include the eternal high priest in the calendar
     *
     * All parameters are optional, and default values will be used if they are not provided.
     * @param array $DATA an associative array of values to use for the calculation
     */
    public function setData(array $DATA = [])
    {
        foreach ($DATA as $key => $value) {
            if (in_array($key, self::ALLOWED_PARAMS)) {
                if ($key !== 'year' && $key !== 'eternal_high_priest') {
                    // all other parameters expect a string value
                    $value = CalendarParams::validateStringValue($key, $value);
                }
                switch ($key) {
                    case "year":
                        $this->validateYearParam($value);
                        break;
                    case "epiphany":
                        $this->validateEpiphanyParam($value);
                        break;
                    case "ascension":
                        $this->validateAscensionParam($value);
                        break;
                    case "corpus_christi":
                        $this->validateCorpusChristiParam($value);
                        break;
                    case "locale":
                        $this->validateLocaleParam($value);
                        break;
                    case "return_type":
                        $this->validateReturnTypeParam($value);
                        break;
                    case "national_calendar":
                        $this->validateNationalCalendarParam($value);
                        break;
                    case "diocesan_calendar":
                        $this->validateDiocesanCalendarParam($value);
                        break;
                    case "year_type":
                        $this->validateYearTypeParam($value);
                        break;
                    case "eternal_high_priest":
                        $this->validateEternalHighPriestParam($value);
                        break;
                }
            }
        }
    }

    /**
     * Validate the year parameter.
     *
     * The year parameter must be a 4 digit numeric string or an integer
     * between {@see CalendarParams::YEAR_LOWER_LIMIT} and {@see CalendarParams::YEAR_UPPER_LIMIT}.
     * If the year parameter is invalid, a 400 Bad Request error will be produced.
     *
     * @param int|string $value the value of the year parameter
     *
     * @return void
     */
    private function validateYearParam(int|string $value)
    {
        if (gettype($value) === 'string') {
            if (is_numeric($value) && ctype_digit($value) && strlen($value) === 4) {
                $this->Year = (int)$value;
            } else {
                $description = 'Year parameter is of type String, but is not a numeric String with 4 digits';
                Calendar::produceErrorResponse(StatusCode::BAD_REQUEST, $description);
            }
        } else {
            $this->Year = $value;
        }

        if ($this->Year < self::YEAR_LOWER_LIMIT || $this->Year > self::YEAR_UPPER_LIMIT) {
            $description = 'Parameter `year` out of bounds, must have a value betwen ' . self::YEAR_LOWER_LIMIT . ' and ' . self::YEAR_UPPER_LIMIT;
            Calendar::produceErrorResponse(StatusCode::BAD_REQUEST, $description);
        }
    }

    /**
     * Validate the epiphany parameter.
     *
     * @param string $value a string indicating whether Epiphany should be calculated on Jan 6th or on the Sunday between Jan 2nd and Jan 8th {@see Epiphany::$values}
     *
     * Produces a 400 Bad Request error if the value is not one of the valid values
     */
    private function validateEpiphanyParam(string $value)
    {
        if (Epiphany::isValid(strtoupper($value))) {
            $this->Epiphany = strtoupper($value);
        } else {
            $description = "Invalid value `{$value}` for parameter `epiphany`, valid values are: " . implode(', ', Epiphany::$values);
            Calendar::produceErrorResponse(StatusCode::BAD_REQUEST, $description);
        }
    }

    /**
     * Validate the ascension parameter.
     *
     * @param string $value one of the values in {@see Ascension::$values}
     *
     * Produces a 400 Bad Request error if the value is not one of the valid values
     */
    private function validateAscensionParam(string $value)
    {
        if (Ascension::isValid(strtoupper($value))) {
            $this->Ascension = strtoupper($value);
        } else {
            $description = "Invalid value `{$value}` for parameter `ascension`, valid values are: " . implode(', ', Ascension::$values);
            Calendar::produceErrorResponse(StatusCode::BAD_REQUEST, $description);
        }
    }

    /**
     * Validate the corpus_christi parameter.
     *
     * @param string $value one of the values in {@see CorpusChristi::$values}
     *
     * Produces a 400 Bad Request error if the value is not one of the valid values
     */
    private function validateCorpusChristiParam(string $value)
    {
        if (CorpusChristi::isValid(strtoupper($value))) {
            $this->CorpusChristi = strtoupper($value);
        } else {
            $description = "Invalid value `{$value}` for parameter `corpus_christi`, valid values are: " . implode(', ', CorpusChristi::$values);
            Calendar::produceErrorResponse(StatusCode::BAD_REQUEST, $description);
        }
    }

    /**
     * Validate the locale parameter.
     *
     * @param string $value a valid locale string that can be used to set the language of the response
     *
     * Produces a 400 Bad Request error if the value is not a valid locale string
     */
    private function validateLocaleParam(string $value)
    {
        $value = \Locale::canonicalize($value);
        if (LitLocale::isValid($value)) {
            $this->Locale = $value;
        } else {
            $description = "Invalid value `{$value}` for parameter `locale`, valid values are: la, la_VA, " . implode(', ', LitLocale::$AllAvailableLocales);
            Calendar::produceErrorResponse(StatusCode::BAD_REQUEST, $description);
        }
    }

    /**
     * Validate the return_type parameter.
     *
     * @param string $value one of the values in {@see ReturnType::$values}
     *
     * Produces a 400 Bad Request error if the value is not one of the valid values
     */
    private function validateReturnTypeParam(string $value)
    {
        if (ReturnType::isValid(strtoupper($value))) {
            $this->ReturnType = strtoupper($value);
        } else {
            $description = "Invalid value `{$value}` for parameter `return_type`, valid values are: " . implode(', ', ReturnType::$values);
            Calendar::produceErrorResponse(StatusCode::BAD_REQUEST, $description);
        }
    }

    /**
     * Validate the national_calendar parameter.
     *
     * @param string $value a valid national calendar key as listed in {@see CalendarParams::$calendars::$national_calendars_keys}
     *
     * Produces a 400 Bad Request error if the value of the national_calendar parameter is invalid
     */
    private function validateNationalCalendarParam(string $value)
    {
        if (in_array($value, $this->calendars->national_calendars_keys)) {
            $this->NationalCalendar = $value;
        } else {
            $validVals = implode(', ', $this->calendars->national_calendars_keys);
            $description = "Invalid National calendar `{$value}`, valid national calendars are: $validVals.";
            Calendar::produceErrorResponse(StatusCode::BAD_REQUEST, $description);
        }
    }

    /**
     * Validate the diocesan_calendar parameter.
     *
     * @param string $value a valid diocesan calendar key as listed in {@see CalendarParams::$calendars::$diocesan_calendars_keys}
     *
     * Produces a 400 Bad Request error if the value of the diocesan_calendar parameter is invalid
     */
    private function validateDiocesanCalendarParam(string $value)
    {
        if (in_array($value, $this->calendars->diocesan_calendars_keys)) {
            $this->DiocesanCalendar = $value;
        } else {
            $description = "Invalid Diocesan calendar `{$value}`, valid diocesan calendars are: "
                . implode(', ', $this->calendars->diocesan_calendars_keys);
            Calendar::produceErrorResponse(StatusCode::BAD_REQUEST, $description);
        }
    }

    /**
     * Validate the year_type parameter.
     *
     * @param string $value one of the values in {@see YearType::$values}
     *
     * Produces a 400 Bad Request error if the value is not one of the valid values
     */
    private function validateYearTypeParam(string $value)
    {
        if (YearType::isValid(strtoupper($value))) {
            $this->YearType = strtoupper($value);
        } else {
            $description = "Invalid value `{$value}` for parameter `year_type`, valid values are: " . implode(', ', YearType::$values);
            Calendar::produceErrorResponse(StatusCode::BAD_REQUEST, $description);
        }
    }

    /**
     * Validate the eternal_high_priest parameter.
     *
     * @param mixed $value a boolean value, or a value that can be interpreted as a boolean
     *
     * Produces a 400 Bad Request error if the value is not a boolean
     */
    private function validateEternalHighPriestParam(mixed $value)
    {
        if (gettype($value) !== 'boolean') {
            $value = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if (null === $value) {
                $description = "Invalid value for parameter `eternal_high_priest`, valid values are boolean `true` and `false`";
                Calendar::produceErrorResponse(StatusCode::BAD_REQUEST, $description);
            }
        }
        $this->EternalHighPriest = $value;
    }
}
