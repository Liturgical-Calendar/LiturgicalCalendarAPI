<?php

namespace Johnrdorazio\LitCal\Params;

use Johnrdorazio\LitCal\Enum\CalendarType;
use Johnrdorazio\LitCal\Enum\Epiphany;
use Johnrdorazio\LitCal\Enum\Ascension;
use Johnrdorazio\LitCal\Enum\CorpusChristi;
use Johnrdorazio\LitCal\Enum\LitLocale;
use Johnrdorazio\LitCal\Enum\ReturnType;

class CalendarParams
{
    public int $Year;
    public string $CalendarType          = CalendarType::LITURGICAL;
    public string $Epiphany              = Epiphany::JAN6;
    public string $Ascension             = Ascension::THURSDAY;
    public string $CorpusChristi         = CorpusChristi::THURSDAY;
    public bool $EternalHighPriest       = false;
    public ?string $Locale               = null;
    public ?string $ReturnType           = null;
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
        //we need at least a default value for the current year
        $this->Year = (int)date("Y");

        $SUPPORTED_NATIONAL_CALENDARS = [ "VATICAN" ];
        $directories = array_map('basename', glob('nations/*', GLOB_ONLYDIR));
        //self::debugWrite(json_encode($directories));
        foreach ($directories as $directory) {
            //self::debugWrite($directory);
            if (file_exists("nations/$directory/$directory.json")) {
                $SUPPORTED_NATIONAL_CALENDARS[] = $directory;
            }
        }

        foreach ($DATA as $key => $value) {
            $key = strtoupper($key);
            if (in_array($key, self::ALLOWED_PARAMS)) {
                switch ($key) {
                    case "YEAR":
                        $this->enforceYearValidity($value);
                        break;
                    case "EPIPHANY":
                        $this->Epiphany         = Epiphany::isValid(strtoupper($value)) ? strtoupper($value) : Epiphany::JAN6;
                        break;
                    case "ASCENSION":
                        $this->Ascension        = Ascension::isValid(strtoupper($value)) ? strtoupper($value) : Ascension::THURSDAY;
                        break;
                    case "CORPUSCHRISTI":
                        $this->CorpusChristi    = CorpusChristi::isValid(strtoupper($value)) ? strtoupper($value) : CorpusChristi::THURSDAY;
                        break;
                    case "LOCALE":
                        $value                  = \Locale::canonicalize($value);
                        $this->Locale           = LitLocale::isValid($value) ? $value : LitLocale::LATIN;
                        break;
                    case "RETURNTYPE":
                        $this->ReturnType       = ReturnType::isValid(strtoupper($value)) ? strtoupper($value) : ReturnType::JSON;
                        break;
                    case "NATIONALCALENDAR":
                        $this->NationalCalendar = in_array(strtoupper($value), $SUPPORTED_NATIONAL_CALENDARS) ? strtoupper($value) : null;
                        break;
                    case "DIOCESANCALENDAR":
                        $this->DiocesanCalendar = strtoupper($value);
                        break;
                    case "CALENDARTYPE":
                        $this->CalendarType     = CalendarType::isValid(strtoupper($value)) ? strtoupper($value) : CalendarType::LITURGICAL;
                        break;
                    case "ETERNALHIGHPRIEST":
                        $this->EternalHighPriest = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                        break;
                }
            }
        }
        if ($this->Locale === null) {
            if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
                $value = \Locale::acceptFromHttp($_SERVER['HTTP_ACCEPT_LANGUAGE']);
                $this->Locale = LitLocale::isValid($value) ? $value : LitLocale::LATIN;
            } else {
                $this->Locale = LitLocale::LATIN;
            }
        }
    }

    private function enforceYearValidity(int|string $value)
    {
        if (gettype($value) === 'string') {
            if (is_numeric($value) && ctype_digit($value) && strlen($value) === 4) {
                $value = (int)$value;
                if ($value >= self::YEAR_LOWER_LIMIT && $value <= self::YEAR_UPPER_LIMIT) {
                    $this->Year = $value;
                }
            }
        } elseif (gettype($value) === 'integer') {
            if ($value >= self::YEAR_LOWER_LIMIT && $value <= self::YEAR_UPPER_LIMIT) {
                $this->Year = $value;
            }
        }
    }
}
