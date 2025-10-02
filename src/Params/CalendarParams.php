<?php

namespace LiturgicalCalendar\Api\Params;

use LiturgicalCalendar\Api\Enum\YearType;
use LiturgicalCalendar\Api\Enum\Epiphany;
use LiturgicalCalendar\Api\Enum\Ascension;
use LiturgicalCalendar\Api\Enum\CorpusChristi;
use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Enum\LitLocale;
use LiturgicalCalendar\Api\Enum\Route;
use LiturgicalCalendar\Api\Http\Enum\ReturnTypeParam;
use LiturgicalCalendar\Api\Http\Exception\ServiceUnavailableException;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use LiturgicalCalendar\Api\Models\Metadata\MetadataCalendars;
use LiturgicalCalendar\Api\Utilities;

/**
 * This class is responsible for handling the parameters provided to the {@see \LiturgicalCalendar\Api\Handlers\CalendarHandler} class.
 *
 * The class is initialized with a set of parameters passed in from the API request.
 * Some parameters come from the path itself, while others come either from the URL query parameters (GET) or from the request body (POST).
 * These parameters are used to determine how the liturgical calendar should be generated.
 *
 * @phpstan-type CalendarParamsData array{
 *     year?: int,
 *     epiphany?: string,
 *     ascension?: string,
 *     corpus_christi?: string,
 *     locale?: string,
 *     return_type?: string,
 *     national_calendar?: string,
 *     diocesan_calendar?: string,
 *     year_type?: string,
 *     eternal_high_priest?: bool
 * }
 * @package LiturgicalCalendar\Api\Params
 */
class CalendarParams implements ParamsInterface
{
    public int $Year;
    public string $Locale;
    public YearType $YearType             = YearType::LITURGICAL;
    public Epiphany $Epiphany             = Epiphany::JAN6;
    public Ascension $Ascension           = Ascension::THURSDAY;
    public CorpusChristi $CorpusChristi   = CorpusChristi::THURSDAY;
    public bool $EternalHighPriest        = false;
    public ?ReturnTypeParam $ReturnType   = null;
    public ?string $NationalCalendar      = null;
    public ?string $DiocesanCalendar      = null;
    private ?MetadataCalendars $calendars = null;

    /** @var ReturnTypeParam[] */
    private array $allowedReturnTypes;

    public const ALLOWED_PARAMS = [
        'year',
        'year_type',
        'epiphany',
        'ascension',
        'corpus_christi',
        'eternal_high_priest',
        'locale',
        'return_type',
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


    /**
     * Constructor for CalendarParams
     *
     * - loads calendars metadata
     * - sets the response status code to 503 Service Unavailable if the API was unable to load calendars metadata
     */
    public function __construct()
    {
        // First of all we need the metadata about calendars that are available on the API,
        // in order to check parameters against those supported by each calendar,
        // whether General Roman, or national, or diocesan
        $this->allowedReturnTypes = ReturnTypeParam::cases();
        $this->Year               = (int) date('Y');
        $this->Locale             = LitLocale::LATIN;

        $calendarsRoute = Route::CALENDARS->path();
        $metadata       = Utilities::jsonUrlToObject($calendarsRoute);

        if (property_exists($metadata, 'litcal_metadata') && $metadata->litcal_metadata instanceof \stdClass) {
            $this->calendars = MetadataCalendars::fromObject($metadata->litcal_metadata);
        } else {
            throw new ServiceUnavailableException('Unable to load calendars metadata');
        }
    }

    /**
     * Sets the allowed 'return_type' parameter values for the calendar.
     *
     * @param ReturnTypeParam[] $allowedReturnTypes An array of allowed 'return_type' parameter values.
     */
    public function setAllowedReturnTypes(array $allowedReturnTypes): void
    {
        $this->allowedReturnTypes = $allowedReturnTypes;
    }

    /**
     * Sets the parameters for the Calendar class using the provided associative array of values.
     *
     * The array keys can be any of the following:
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
     * @param CalendarParamsData $params an associative array of parameter keys to values
     */
    public function setParams(array $params): void
    {
        if (count($params) === 0) {
            // If no parameters are provided, we can just return
            return;
        }
        foreach ($params as $key => $value) {
            if (in_array($key, self::ALLOWED_PARAMS)) {
                if ($key !== 'year' && $key !== 'eternal_high_priest') {
                    // all other parameters expect a string value
                    $value = $this->validateStringValue($key, $value);
                }
                switch ($key) {
                    case 'year':
                        /** @var int $value */
                        $this->validateYearParam($value);
                        break;
                    case 'epiphany':
                        /** @var string $value */
                        $this->validateEpiphanyParam($value);
                        break;
                    case 'ascension':
                        /** @var string $value */
                        $this->validateAscensionParam($value);
                        break;
                    case 'corpus_christi':
                        /** @var string $value */
                        $this->validateCorpusChristiParam($value);
                        break;
                    case 'locale':
                        /** @var string $value */
                        $this->validateLocaleParam($value);
                        break;
                    case 'return_type':
                        /** @var string $value */
                        $this->validateReturnTypeParam($value);
                        break;
                    case 'national_calendar':
                        /** @var string $value */
                        $this->validateNationalCalendarParam($value);
                        break;
                    case 'diocesan_calendar':
                        /** @var string $value */
                        $this->validateDiocesanCalendarParam($value);
                        break;
                    case 'year_type':
                        /** @var string $value */
                        $this->validateYearTypeParam($value);
                        break;
                    case 'eternal_high_priest':
                        /** @var bool $value */
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
     * between {@see \LiturgicalCalendar\Api\Params\CalendarParams::YEAR_LOWER_LIMIT} and {@see \LiturgicalCalendar\Api\Params\CalendarParams::YEAR_UPPER_LIMIT}.
     * If the year parameter is invalid, a 400 Bad Request error will be produced.
     *
     * @param int|string $value the value of the year parameter
     *
     * @return void
     */
    private function validateYearParam(int|string $value): void
    {
        if (gettype($value) === 'string') {
            if (is_numeric($value) && ctype_digit($value) && strlen($value) === 4) {
                $this->Year = (int) $value;
            } else {
                throw new ValidationException('Year parameter is of type String, but is not a numeric String with 4 digits');
            }
        } else {
            $this->Year = $value;
        }

        if ($this->Year < self::YEAR_LOWER_LIMIT || $this->Year > self::YEAR_UPPER_LIMIT) {
            throw new ValidationException('Parameter `year` out of bounds, must have a value betwen ' . self::YEAR_LOWER_LIMIT . ' and ' . self::YEAR_UPPER_LIMIT);
        }
    }

    /**
     * Validate the epiphany parameter.
     *
     * @param string $value a string indicating whether Epiphany should be calculated on Jan 6th or on the Sunday between Jan 2nd and Jan 8th.
     *
     * Produces a 400 Bad Request error if the value is not one of the valid values in {@see \LiturgicalCalendar\Api\Enum\Epiphany}::values().
     */
    private function validateEpiphanyParam(string $value): void
    {
        if (false === Epiphany::isValid($value)) {
            throw new ValidationException("Invalid value `{$value}` for parameter `epiphany`, valid values are: " . implode(', ', Epiphany::values()));
        }
        $this->Epiphany = Epiphany::from($value);
    }

    /**
     * Validate the ascension parameter.
     *
     * @param string $value a string indicating whether Ascension should be calculated on a Thursday or on a Sunday.
     *
     * Produces a 400 Bad Request error if the value is not one of the valid values in {@see \LiturgicalCalendar\Api\Enum\Ascension}::values().
     */
    private function validateAscensionParam(string $value): void
    {
        if (false === Ascension::isValid($value)) {
            throw new ValidationException("Invalid value `{$value}` for parameter `ascension`, valid values are: " . implode(', ', Ascension::values()));
        }
        $this->Ascension = Ascension::from($value);
    }

    /**
     * Validate the corpus_christi parameter.
     *
     * @param string $value a string indicating whether Corpus Christi should be calculated on a Sunday or on a Thursday.
     *
     * @throws ValidationException if the value is not one of the valid values in {@see \LiturgicalCalendar\Api\Enum\CorpusChristi}::values().
     */
    private function validateCorpusChristiParam(string $value): void
    {
        if (false === CorpusChristi::isValid($value)) {
            throw new ValidationException("Invalid value `{$value}` for parameter `corpus_christi`, valid values are: " . implode(', ', CorpusChristi::values()));
        }
        $this->CorpusChristi = CorpusChristi::from($value);
    }

    /**
     * Validate the locale parameter.
     *
     * @param string $value a valid locale string that can be used to set the language of the response
     *
     * @throws ValidationException
     */
    private function validateLocaleParam(string $value): void
    {
        $normalized        = self::normalizeLocale($value);
        $primaryLanguage   = \Locale::getPrimaryLanguage($normalized);
        $region            = \Locale::getRegion($normalized);
        $languageAndRegion = $primaryLanguage . ( $region ? '_' . $region : '' );

        if (LitLocale::isValid($normalized)) {
            $this->Locale = $normalized;
            return;
        }

        if ($languageAndRegion !== '' && LitLocale::isValid($languageAndRegion)) {
            $this->Locale = $languageAndRegion;
            return;
        }
        throw new ValidationException("Invalid value `{$normalized}` for parameter `locale`, valid values are: la, la_VA, " . implode(', ', LitLocale::$AllAvailableLocales));
    }

    private static function hasRegion(string $locale): bool
    {
        $parts = explode('_', $locale);

        // language only
        if (count($parts) === 1) {
            return false;
        }

        // check each subtag for region pattern
        foreach ($parts as $part) {
            if (preg_match('/^[A-Z]{2}$/', $part) || preg_match('/^[0-9]{3}$/', $part)) {
                return true;
            }
        }

        return false;
    }

    private static function normalizeLocale(string $input): string
    {
        $locale = \Locale::canonicalize($input);
        if (null === $locale || '' === $locale) {
            throw new ValidationException('Invalid locale string: ' . $input . '. “If they were scattered abroad into foreign tongues, it was because their intention was profane. But now, by the distribution of tongues, the impiety is dissolved and the unity of the Spirit is restored.”
— St. Gregory of Nazianzus, Oration 41 (On Pentecost), §11');
        }

        if (!self::hasRegion($locale)) {
            $locale = self::maximizeLocale($locale);
        }

        return $locale;
    }

    private static function maximizeLocale(string $language): string
    {
        /** @var array<string,string>|null $likely */
        static $likely = null;

        if ($likely === null) {
            /** @var array{supplemental:array{likelySubtags:array<string,string>}} $data */
            $data   = Utilities::jsonFileToArray(JsonData::FOLDER->path() . '/likelySubtags.json');
            $likely = $data['supplemental']['likelySubtags'];
        }

        // Try direct hit (e.g. "en")
        if (isset($likely[$language])) {
            return \Locale::canonicalize($likely[$language]) ?? $language;
        }

        // Otherwise just return the original base language
        return $language;
    }

    /**
     * Validate the return_type parameter.
     *
     * @param string $value a string indicating the desired MIME type of the Response.
     *
     * Produces a 400 Bad Request error if the value is not one of the valid values in {@see \LiturgicalCalendar\Api\Http\Enum\ReturnType}::values().
     */
    private function validateReturnTypeParam(string $value): void
    {
        $allowedReturnTypeValues = array_column($this->allowedReturnTypes, 'value');
        if (false === ReturnTypeParam::isValid($value) || false === in_array($value, $allowedReturnTypeValues, true)) {
            throw new ValidationException("Invalid value `{$value}` for parameter `return_type`, valid values are: " . implode(', ', $allowedReturnTypeValues));
        }
        $this->ReturnType = ReturnTypeParam::from($value);
    }

    /**
     * Validate the national_calendar parameter.
     *
     * @param string $value a valid national calendar key as listed in {@see \LiturgicalCalendar\Api\Params\CalendarParams::$calendars}::$national_calendars_keys
     *
     * Produces a 400 Bad Request error if the value of the national_calendar parameter is invalid
     */
    private function validateNationalCalendarParam(string $value): void
    {
        if (null === $this->calendars) {
            throw new ServiceUnavailableException('Calendars metadata is not initialized.');
        }

        if (
            false === in_array($value, $this->calendars->national_calendars_keys)
            && false === ( $value === 'VA' )
        ) {
            $validVals = implode(', ', $this->calendars->national_calendars_keys);
            throw new ValidationException("Invalid value `{$value}` for parameter `national_calendar`, valid values are: " . $validVals);
        }
        $this->NationalCalendar = $value;
    }

    /**
     * Validate the diocesan_calendar parameter.
     *
     * @param string $value a valid diocesan calendar key as listed in {@see \LiturgicalCalendar\Api\Params\CalendarParams::$calendars}::$diocesan_calendars_keys
     *
     * Produces a 400 Bad Request error if the value of the diocesan_calendar parameter is invalid
     */
    private function validateDiocesanCalendarParam(string $value): void
    {
        if (null === $this->calendars) {
            throw new ServiceUnavailableException('Calendars metadata is not initialized.');
        }

        if (false === in_array($value, $this->calendars->diocesan_calendars_keys)) {
            throw new ValidationException("Invalid Diocesan calendar `{$value}`, valid diocesan calendars are: "
                . implode(', ', $this->calendars->diocesan_calendars_keys));
        }
        $this->DiocesanCalendar = $value;
    }

    /**
     * Validate the year_type parameter.
     *
     * @param string $value a string indicating whether the calendar should be calculated for a civil or liturgical year.
     *
     * Produces a 400 Bad Request error if the value is not one of the valid values in {@see \LiturgicalCalendar\Api\Enum\YearType}::values().
     */
    private function validateYearTypeParam(string $value): void
    {
        if (false === YearType::isValid($value)) {
            throw new ValidationException("Invalid value `{$value}` for parameter `year_type`, valid values are: " . implode(', ', YearType::values()));
        }
        $this->YearType = YearType::from($value);
    }

    /**
     * Validate the eternal_high_priest parameter.
     *
     * @param mixed $value a boolean value, or a value that can be interpreted as a boolean
     *
     * Produces a 400 Bad Request error if the value is not a boolean
     */
    private function validateEternalHighPriestParam(mixed $value): void
    {
        if (gettype($value) !== 'boolean') {
            $value = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if (null === $value) {
                throw new ValidationException('Invalid value for parameter `eternal_high_priest`, valid values are boolean `true` and `false`');
            }
        }
        /** @var bool $value */
        $this->EternalHighPriest = $value;
    }

    /**
     * Validates parameter values that are expected to be strings.
     * Produces a 400 Bad Request error if the value is not a string
     */
    private function validateStringValue(string $key, mixed $value): string
    {
        if (gettype($value) !== 'string') {
            throw new ValidationException("Expected value of type String for parameter `{$key}`, instead found type " . gettype($value));
        }

        $filteredValue = filter_var($value, FILTER_SANITIZE_SPECIAL_CHARS);
        if (!$filteredValue) {
            throw new ValidationException("Could not correctly sanitize the value for parameter `{$key}`");
        }

        /** @var string */
        return $filteredValue;
    }

    /**
     * Initialize the CalendarParams object from the path parameters of the request.
     * Expected path parameters are:
     * 1) nation or diocese or year: a string indicating whether a national or diocesan calendar is requested, or an integer indicating the year for which the General Roman calendar should be calculated
     * 2) (when 1 is a string) a string indicating the national or diocesan calendar to produce
     * 3) (when 1 is a string) an integer indicating the year for which the national or diocesan calendar should be calculated
     * @param string[] $requestPathParams
     */
    public function initParamsFromRequestPath(array $requestPathParams): void
    {
        $numPathParts = count($requestPathParams);
        if ($numPathParts > 0) {
            $params = [];
            if ($numPathParts === 1) {
                if (
                    false === is_numeric($requestPathParams[0])
                    || false === ctype_digit($requestPathParams[0])
                ) {
                    throw new ValidationException('path parameter expected to represent Year value but did not have type Integer or numeric String');
                } else {
                    $params['year'] = (int) $requestPathParams[0];
                }
            } elseif ($numPathParts > 3) {
                throw new ValidationException('Expected at least one and at most three path parameters, instead found ' . $numPathParts);
            } else {
                if (false === in_array($requestPathParams[0], ['nation', 'diocese'])) {
                    throw new ValidationException("Invalid value `{$requestPathParams[0]}` for path parameter in position 1,"
                        . ' the first parameter should have a value of either `nation` or `diocese`');
                } else {
                    if ($requestPathParams[0] === 'nation') {
                        $params['national_calendar'] = (string) $requestPathParams[1];
                    } elseif ($requestPathParams[0] === 'diocese') {
                        $params['diocesan_calendar'] = (string) $requestPathParams[1];
                    }
                }
                if ($numPathParts === 3) {
                    if (
                        false === in_array(gettype($requestPathParams[2]), ['string', 'integer'])
                        || (
                            gettype($requestPathParams[2]) === 'string'
                            && (
                                false === is_numeric($requestPathParams[2])
                                || false === ctype_digit($requestPathParams[2])
                            )
                        )
                    ) {
                        throw new ValidationException('path parameter expected to represent Year value but did not have type Integer or numeric String: found type ' . gettype($requestPathParams[2]));
                    } else {
                        $params['year'] = (int) $requestPathParams[2];
                    }
                }
            }
            if (count($params)) {
                $this->setParams($params);
            }
        }
    }

    /*private static function debugWrite(string $string)
    {
        file_put_contents("debug.log", $string . PHP_EOL, FILE_APPEND);
    }*/
}
