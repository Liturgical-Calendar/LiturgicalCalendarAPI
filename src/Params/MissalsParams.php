<?php

namespace Johnrdorazio\LitCal\Params;

use Johnrdorazio\LitCal\Enum\LitLocale;
use Johnrdorazio\LitCal\Enum\StatusCode;
use Johnrdorazio\LitCal\Paths\Missals;

class MissalsParams
{
    public ?string $Region              = null;
    public ?string $Year                = null;
    public ?string $Locale              = null;
    public ?string $baseLocale          = null;
    private array $availableLangs       = [];
    private static array $MissalRegions = [];
    private static array $MissalYears   = [];
    // public const int ERROR_NONE         = 0;
    // public const int ERROR              = 1;
    // private static int $last_error      = MissalsParams::ERROR_NONE;
    // private static StatusCode $last_error_status;
    // private static string $last_error_msg;

    public function __construct(?array $DATA = null)
    {
        if (null !== $DATA) {
            $this->setData($DATA);
        }
    }

    public function setData(array $DATA = [])
    {
        if (count($DATA)) {
            foreach ($DATA as $key => $value) {
                $key = strtoupper($key);
                switch ($key) {
                    case 'LOCALE':
                        if ($value !== 'la' && $value !== 'LA') {
                            $value = \Locale::canonicalize($value);
                        } else {
                            $value = strtoupper($value);
                        }
                        if (LitLocale::isValid($value)) {
                            $this->Locale = $value;
                            $this->baseLocale = \Locale::getPrimaryLanguage($value);
                        } else {
                            $error = "Locale `$value` set in param `locale` is not supported by this server, supported locales are: "
                                . implode(', ', LitLocale::$AllAvailableLocales);
                            //$this->setLastError(StatusCode::BAD_REQUEST, $error);
                            Missals::produceErrorResponse(StatusCode::BAD_REQUEST, $error);
                        }
                        if (count($this->availableLangs) && false === in_array($this->baseLocale, $this->availableLangs)) {
                            $message = "Locale `$value` ({$this->baseLocale}) set in param `locale` is not a valid locale for the requested Missal, valid locales are: "
                                    . implode(', ', $this->availableLangs);
                            //$this->setLastError(StatusCode::BAD_REQUEST, $message);
                            Missals::produceErrorResponse(StatusCode::BAD_REQUEST, $message);
                        }
                        break;
                    case 'YEAR':
                        if (in_array($value, self::$MissalYears)) {
                            $this->Year = $value;
                        } else {
                            $message = "Invalid value `$value` for param `year`, valid values are: "
                                . implode(', ', self::$MissalYears);
                            //$this->setLastError(StatusCode::BAD_REQUEST, $message);
                            Missals::produceErrorResponse(StatusCode::BAD_REQUEST, $message);
                        }
                        break;
                    case 'REGION':
                        if (in_array($value, self::$MissalRegions)) {
                            $this->Region = $value;
                        } else {
                            $message = "Invalid value `$value` for param `region`, valid values are: "
                                . implode(', ', self::$MissalRegions);
                            //$this->setLastError(StatusCode::BAD_REQUEST, $message);
                            Missals::produceErrorResponse(StatusCode::BAD_REQUEST, $message);
                        }
                        break;
                }
            }
        }
    }
/**
 * Could consider using these functions to set error, rather than using the Missals class produceErrorMessage
 * Then the Missals class would check, every time it sets params data:
 *      if (self::$params->last_error() !== MissalsParams::ERROR_NONE) {
 *          self::produceErrorMessage(self::$params->last_error_status(), self::$params->last_error_msg());
 *      }
 * I would do this if I could follow the JSON convention of snake case last_error() and last_error_msg()
 * However this triggers codesniffers rule about method casing ...
    private function setLastError(StatusCode $status, string $message): void
    {
        self::$last_error        = self::ERROR;
        self::$last_error_status = $status;
        self::$last_error_msg    = $message;
    }

    public function last_error()
    {
        return self::$last_error;
    }

    public function last_error_msg()
    {
        return self::$last_error_msg;
    }

    public function last_error_status()
    {
        return self::$last_error_status;
    }
 */
    public function setMissalRegion(string $region)
    {
        if (false === in_array($region, self::$MissalRegions)) {
            self::$MissalRegions[] = $region;
        }
    }

    public function setMissalYear(string $year)
    {
        if (false === in_array($year, self::$MissalYears)) {
            self::$MissalYears[] = $year;
        }
    }

    public function setAvailableLangs(array $langs)
    {
        $this->availableLangs = $langs;
    }
}
