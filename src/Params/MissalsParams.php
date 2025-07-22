<?php

namespace LiturgicalCalendar\Api\Params;

use LiturgicalCalendar\Api\Enum\LitLocale;
use LiturgicalCalendar\Api\Enum\StatusCode;
use LiturgicalCalendar\Api\Paths\MissalsPath;

/**
 * Class MissalsParams
 *
 * This class handles the parameters for the Missals API endpoint.
 * It validates and sets the locale, year, region, and whether to include empty entries.
 *
 * @package LiturgicalCalendar\Api\Params
 */
class MissalsParams implements ParamsInterface
{
    public bool $IncludeEmpty                                = false;
    public ?string $Region                                   = null;
    public ?int $Year                                        = null;
    public ?string $Locale                                   = null;
    public ?string $baseLocale                               = null;
    /** @var string[] */ private array $availableLangs       = [];
    /** @var string[] */ private static array $MissalRegions = [];
    /** @var int[]    */ private static array $MissalYears   = [];

    /**
     * Initializes the MissalsParams class.
     *
     * Calls the setParams method to set the parameters provided in the $params array, in any.
     *
     * @param array{
     *      locale?: string,
     *      year?: int,
     *      region?: string,
     *      include_empty?: bool
     * } $params an associative array of parameter keys to values
     */
    public function __construct(array $params = [])
    {
        $this->setParams($params);
    }

    /**
     * Sets the parameters for the Missals API endpoint.
     *
     * The parameters are passed as an associative array with the following keys:
     * - locale: the language in which to retrieve the Missal
     * - year: the year for which to retrieve the Missal
     * - region: the region for which to retrieve the Missal
     * - include_empty: whether to include empty entries in the response
     *
     * All parameters are optional, and default values will be used if they are not provided.
     *
     * If an invalid value is provided for any parameter, an error response will be produced with a status code of 400.
     *
     * @param array{
     *      locale?: string,
     *      year?: int,
     *      region?: string,
     *      include_empty?: bool
     * } $params an associative array of parameter keys to values
     */
    public function setParams(array $params = []): void
    {
        if (count($params) === 0) {
            // If no parameters are provided, we can just return
            return;
        }
        foreach ($params as $key => $value) {
            switch ($key) {
                case 'locale':
                    $value = \Locale::canonicalize($value);
                    if (LitLocale::isValid($value)) {
                        $this->Locale     = $value;
                        $this->baseLocale = \Locale::getPrimaryLanguage($value);
                    } else {
                        $error = "Locale `$value` set in param `locale` is not supported by this server, supported locales are: la, la_VA, "
                            . implode(', ', LitLocale::$AllAvailableLocales);
                        //$this->setLastError(StatusCode::BAD_REQUEST, $error);
                        MissalsPath::produceErrorResponse(StatusCode::BAD_REQUEST, $error);
                    }
                    if (count($this->availableLangs) && false === in_array($this->baseLocale, $this->availableLangs)) {
                        $message = "Locale `$value` ({$this->baseLocale}) set in param `locale` is not a valid locale for the requested Missal, valid locales are: "
                                . implode(', ', $this->availableLangs);
                        //$this->setLastError(StatusCode::BAD_REQUEST, $message);
                        MissalsPath::produceErrorResponse(StatusCode::BAD_REQUEST, $message);
                    }
                    break;
                case 'year':
                    if (gettype($value) === 'string') {
                        $value = intval($value);
                    }
                    if (in_array($value, self::$MissalYears)) {
                        $this->Year = $value;
                    } else {
                        $message = "Invalid value `$value` for param `year`, valid values are: "
                            . implode(', ', self::$MissalYears);
                        //$this->setLastError(StatusCode::BAD_REQUEST, $message);
                        MissalsPath::produceErrorResponse(StatusCode::BAD_REQUEST, $message);
                    }
                    break;
                case 'region':
                    if (in_array($value, self::$MissalRegions)) {
                        $this->Region = $value;
                    } else {
                        $message = "Invalid value `$value` for param `region`, valid values are: "
                            . implode(', ', self::$MissalRegions);
                        //$this->setLastError(StatusCode::BAD_REQUEST, $message);
                        MissalsPath::produceErrorResponse(StatusCode::BAD_REQUEST, $message);
                    }
                    break;
                case 'include_empty':
                    $this->IncludeEmpty = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                    break;
                default:
                    // do nothing
                    break;
            }
        }
    }


    /**
     * Adds a region to the list of valid regions for the requested missal.
     *
     * @param string $region the region to add
     */
    public function addMissalRegion(string $region): void
    {
        if (false === in_array($region, self::$MissalRegions)) {
            self::$MissalRegions[] = $region;
        }
    }

    /**
     * Adds a year to the list of valid years for the requested missal.
     *
     * @param int $year the year to add
     */
    public function addMissalYear(int $year): void
    {
        if (false === in_array($year, self::$MissalYears)) {
            self::$MissalYears[] = $year;
        }
    }

    /**
     * Sets the list of available languages for the requested missal.
     *
     * @param string[] $langs An array of locales, e.g. ['en_US', 'es_ES', 'pt_PT']
     */
    public function setAvailableLangs(array $langs): void
    {
        $this->availableLangs = $langs;
    }
}
