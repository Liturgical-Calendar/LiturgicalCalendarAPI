<?php

namespace Johnrdorazio\LitCal\Params;

use Johnrdorazio\LitCal\Enum\YearType;
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

    public function __construct(array $DATA)
    {
        // set a default value for the Year parameter, defaulting to current year
        $this->Year = (int)date("Y");

        $calendarsRoute = (defined('API_BASE_PATH') ? API_BASE_PATH : "{$_SERVER['REQUEST_SCHEME']}://{$_SERVER['SERVER_NAME']}/api/dev") . Route::CALENDARS->value;
        $metadataRaw = file_get_contents($calendarsRoute);
        if ($metadataRaw) {
            $metadata = json_decode($metadataRaw);
            if (JSON_ERROR_NONE === json_last_error() && property_exists($metadata, 'litcal_metadata')) {
                $this->calendars = $metadata->litcal_metadata;
            } else {
                Calendar::produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, "The API was unable to initialize calendars metadata: " . json_last_error_msg());
            }
        } else {
            Calendar::produceErrorResponse(StatusCode::SERVICE_UNAVAILABLE, "The API was unable to load calendars metadata");
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

    private static function validateStringValue(string $key, mixed $value): string
    {
        if (gettype($value) !== 'string') {
            $description = "Expected value of type String for parameter `{$key}`, instead found type " . gettype($value);
            Calendar::produceErrorResponse(StatusCode::BAD_REQUEST, $description);
        }
        return filter_var($value, FILTER_SANITIZE_SPECIAL_CHARS);
    }

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

    private function validateYearParam(int|string $value)
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
            $description = 'Parameter `year` must be of type Integer or numeric String, instead it was of type ' . gettype($value);
            Calendar::produceErrorResponse(StatusCode::BAD_REQUEST, $description);
        }
        if ($this->Year < self::YEAR_LOWER_LIMIT || $this->Year > self::YEAR_UPPER_LIMIT) {
            $description = 'Parameter `year` out of bounds, must have a value betwen ' . self::YEAR_LOWER_LIMIT . ' and ' . self::YEAR_UPPER_LIMIT;
            Calendar::produceErrorResponse(StatusCode::BAD_REQUEST, $description);
        }
    }

    private function validateEpiphanyParam(string $value)
    {
        if (Epiphany::isValid(strtoupper($value))) {
            $this->Epiphany = strtoupper($value);
        } else {
            $description = "Invalid value `{$value}` for parameter `epiphany`, valid values are: " . implode(', ', Epiphany::$values);
            Calendar::produceErrorResponse(StatusCode::BAD_REQUEST, $description);
        }
    }

    private function validateAscensionParam(string $value)
    {
        if (Ascension::isValid(strtoupper($value))) {
            $this->Ascension = strtoupper($value);
        } else {
            $description = "Invalid value `{$value}` for parameter `ascension`, valid values are: " . implode(', ', Ascension::$values);
            Calendar::produceErrorResponse(StatusCode::BAD_REQUEST, $description);
        }
    }

    private function validateCorpusChristiParam(string $value)
    {
        if (CorpusChristi::isValid(strtoupper($value))) {
            $this->CorpusChristi = strtoupper($value);
        } else {
            $description = "Invalid value `{$value}` for parameter `corpus_christi`, valid values are: " . implode(', ', CorpusChristi::$values);
            Calendar::produceErrorResponse(StatusCode::BAD_REQUEST, $description);
        }
    }

    private function validateLocaleParam(string $value)
    {
        if ($value !== 'LA' && $value !== 'la') {
            $value = \Locale::canonicalize($value);
        }
        if (LitLocale::isValid($value)) {
            $this->Locale = $value;
        } else {
            $description = "Invalid value `{$value}` for parameter `locale`, valid values are: LA, " . implode(', ', LitLocale::$AllAvailableLocales);
            Calendar::produceErrorResponse(StatusCode::BAD_REQUEST, $description);
        }
    }

    private function validateReturnTypeParam(string $value)
    {
        if (ReturnType::isValid(strtoupper($value))) {
            $this->ReturnType = strtoupper($value);
        } else {
            $description = "Invalid value `{$value}` for parameter `return_type`, valid values are: " . implode(', ', ReturnType::$values);
            Calendar::produceErrorResponse(StatusCode::BAD_REQUEST, $description);
        }
    }

    private function validateNationalCalendarParam(string $value)
    {
        if (in_array($value, $this->calendars->national_calendars_keys)) {
            $this->NationalCalendar = $value;
        } else {
            $validVals = implode(', ', $this->calendars->national_calendars);
            $description = "Invalid National calendar `{$value}`, valid national calendars are: $validVals.";
            Calendar::produceErrorResponse(StatusCode::BAD_REQUEST, $description);
        }
    }

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

    private function validateYearTypeParam(string $value)
    {
        if (YearType::isValid(strtoupper($value))) {
            $this->YearType = strtoupper($value);
        } else {
            $description = "Invalid value `{$value}` for parameter `year_type`, valid values are: " . implode(', ', YearType::$values);
            Calendar::produceErrorResponse(StatusCode::BAD_REQUEST, $description);
        }
    }

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
