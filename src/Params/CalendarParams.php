<?php

namespace Johnrdorazio\LitCal\Params;

use Johnrdorazio\LitCal\Enum\CalendarType;
use Johnrdorazio\LitCal\Enum\Epiphany;
use Johnrdorazio\LitCal\Enum\Ascension;
use Johnrdorazio\LitCal\Enum\CorpusChristi;
use Johnrdorazio\LitCal\Enum\LitLocale;
use Johnrdorazio\LitCal\Enum\ReturnType;
use Johnrdorazio\LitCal\Enum\Route;
use Johnrdorazio\LitCal\Enum\StatusCode;
use Johnrdorazio\LitCal\Paths\Calendar;

class CalendarParams
{
    private ?object $calendars;
    public int $Year;
    public string $CalendarType          = CalendarType::LITURGICAL;
    public string $Epiphany              = Epiphany::JAN6;
    public string $Ascension             = Ascension::THURSDAY;
    public string $CorpusChristi         = CorpusChristi::THURSDAY;
    public bool $EternalHighPriest       = false;
    public ?string $ReturnType           = null;
    public ?string $Locale               = null;
    public ?string $NationalCalendar     = null;
    public ?string $DiocesanCalendar     = null;

    public const ALLOWED_PARAMS  = [
        "YEAR",
        "CALENDARTYPE",
        "EPIPHANY",
        "ASCENSION",
        "CORPUSCHRISTI",
        "ETERNALHIGHPRIEST",
        "LOCALE",
        "RETURNTYPE",
        "NATIONALCALENDAR",
        "DIOCESANCALENDAR"
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

    public function __construct(array $DATA)
    {
        // set a default value for the Year parameter, defaulting to current year
        $this->Year = (int)date("Y");

        $calendarsRoute = (defined('API_BASE_PATH') ? API_BASE_PATH : 'https://litcal.johnromanodorazio.com/api/dev') . Route::CALENDARS->value;
        $metadataRaw = file_get_contents($calendarsRoute);
        if ($metadataRaw) {
            $metadata = json_decode($metadataRaw);
            if (JSON_ERROR_NONE === json_last_error() && property_exists($metadata, 'LitCalMetadata')) {
                $this->calendars = $metadata->LitCalMetadata;
            }
        }

        // set a default value for language starting from Accept-Language header
        // a LOCALE parameter can then override this value
        if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
            $value = \Locale::acceptFromHttp($_SERVER['HTTP_ACCEPT_LANGUAGE']);
            $this->Locale = LitLocale::isValid($value) ? $value : LitLocale::LATIN;
        } else {
            $this->Locale = LitLocale::LATIN;
        }

        $this->setData($DATA);
    }

    public function setData(array $DATA = [])
    {
        foreach ($DATA as $key => $value) {
            $key = strtoupper($key);
            if (in_array($key, self::ALLOWED_PARAMS)) {
                if ($key !== 'YEAR' && $key !== 'ETERNALHIGHPRIEST') {
                    // all other parameters expect a string value
                    if (gettype($value) !== 'string') {
                        $description = "Expected value of type String for parameter `{$key}`, instead found type " . gettype($value);
                        Calendar::produceErrorResponse(StatusCode::BAD_REQUEST, $description);
                    }
                    $value = filter_var($value, FILTER_SANITIZE_SPECIAL_CHARS);
                }
                switch ($key) {
                    case "YEAR":
                        $this->enforceYearValidity($value);
                        break;
                    case "EPIPHANY":
                        if (Epiphany::isValid(strtoupper($value))) {
                            $this->Epiphany = strtoupper($value);
                        } else {
                            $description = "Invalid value `{$value}` for parameter `EPIPHANY`, valid values are: " . implode(', ', Epiphany::$values);
                            Calendar::produceErrorResponse(StatusCode::BAD_REQUEST, $description);
                        }
                        break;
                    case "ASCENSION":
                        if (Ascension::isValid(strtoupper($value))) {
                            $this->Ascension = strtoupper($value);
                        } else {
                            $description = "Invalid value `{$value}` for parameter `ASCENSION`, valid values are: " . implode(', ', Ascension::$values);
                            Calendar::produceErrorResponse(StatusCode::BAD_REQUEST, $description);
                        }
                        break;
                    case "CORPUSCHRISTI":
                        if (CorpusChristi::isValid(strtoupper($value))) {
                            $this->CorpusChristi = strtoupper($value);
                        } else {
                            $description = "Invalid value `{$value}` for parameter `CORPUSCHRISTI`, valid values are: " . implode(', ', CorpusChristi::$values);
                            Calendar::produceErrorResponse(StatusCode::BAD_REQUEST, $description);
                        }
                        break;
                    case "LOCALE":
                        if ($value !== 'LA' && $value !== 'la') {
                            $value = \Locale::canonicalize($value);
                        }
                        if (LitLocale::isValid($value)) {
                            $this->Locale = $value;
                        } else {
                            $description = "Invalid value `{$value}` for parameter `LOCALE`, valid values are: LA, " . implode(', ', LitLocale::$AllAvailableLocales);
                            Calendar::produceErrorResponse(StatusCode::BAD_REQUEST, $description);
                        }
                        break;
                    case "RETURNTYPE":
                        if (ReturnType::isValid(strtoupper($value))) {
                            $this->ReturnType = strtoupper($value);
                        } else {
                            $description = "Invalid value `{$value}` for parameter `RETURNTYPE`, valid values are: " . implode(', ', ReturnType::$values);
                            Calendar::produceErrorResponse(StatusCode::BAD_REQUEST, $description);
                        }
                        break;
                    case "NATIONALCALENDAR":
                        if (property_exists($this->calendars->NationalCalendars, $value)) {
                            $this->NationalCalendar = $value;
                        } else {
                            $validVals = array_keys(get_object_vars($this->calendars->NationalCalendars));
                            $description = "Invalid value `{$value}` for parameter `NATIONALCALENDAR`, valid values are: " . implode(', ', $validVals);
                            Calendar::produceErrorResponse(StatusCode::BAD_REQUEST, $description);
                        }
                        break;
                    case "DIOCESANCALENDAR":
                        if (property_exists($this->calendars->DiocesanCalendars, $value)) {
                            $this->DiocesanCalendar = $value;
                        } else {
                            $validVals = array_keys(get_object_vars($this->calendars->DiocesanCalendars));
                            $description = "Invalid value `{$value}` for parameter `DIOCESANCALENDAR`, valid values are: " . implode(', ', $validVals);
                            Calendar::produceErrorResponse(StatusCode::BAD_REQUEST, $description);
                        }
                        break;
                    case "CALENDARTYPE":
                        if (CalendarType::isValid(strtoupper($value))) {
                            $this->CalendarType = strtoupper($value);
                        } else {
                            $description = "Invalid value `{$value}` for parameter `CALENDARTYPE`, valid values are: " . implode(', ', CalendarType::$values);
                            Calendar::produceErrorResponse(StatusCode::BAD_REQUEST, $description);
                        }
                        break;
                    case "ETERNALHIGHPRIEST":
                        if (gettype($value) !== 'boolean') {
                            $value = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                            if (null === $value) {
                                $description = "Invalid value for parameter `ETERNALHIGHPRIEST`, valid values are `true` and `false`";
                                Calendar::produceErrorResponse(StatusCode::BAD_REQUEST, $description);
                            }
                        }
                        $this->EternalHighPriest = $value;
                        break;
                }
            }
        }
    }

    private function enforceYearValidity(int|string $value)
    {
        if (gettype($value) === 'string') {
            if (is_numeric($value) && ctype_digit($value) && strlen($value) === 4) {
                $this->Year = (int)$value;
            } else {
                $description = 'Year parameter is of type String, but is not a numeric String with 4 digits';
                Calendar::produceErrorResponse(StatusCode::BAD_REQUEST, $description);
            }
        } elseif (gettype($value) === 'integer') {
            $this->Year = $value;
        } else {
            $description = 'Year parameter must be of type Integer or numeric String, instead it was of type ' . gettype($value);
            Calendar::produceErrorResponse(StatusCode::BAD_REQUEST, $description);
        }
        if ($this->Year < self::YEAR_LOWER_LIMIT || $this->Year > self::YEAR_UPPER_LIMIT) {
            $description = 'Year parameter out of bounds, must have a value betwen ' . self::YEAR_LOWER_LIMIT . ' and ' . self::YEAR_UPPER_LIMIT;
            Calendar::produceErrorResponse(StatusCode::BAD_REQUEST, $description);
        }
    }
}
