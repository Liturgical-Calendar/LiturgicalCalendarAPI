<?php

namespace LiturgicalCalendar\Api\Params;

use LiturgicalCalendar\Api\Enum\LitLocale;
use LiturgicalCalendar\Api\Enum\Route;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
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
 * @phpstan-import-type MetadataCalendarsObject from \LiturgicalCalendar\Api\Models\Metadata\MetadataCalendars
 */
class EventsParams implements ParamsInterface
{
    public int $Year;
    public string $Locale;
    public string $baseLocale;
    public bool $EternalHighPriest   = false;
    public ?string $NationalCalendar = null;
    public ?string $DiocesanCalendar = null;

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
    public function __construct($params = [])
    {
        /** @var \stdClass&object{litcal_metadata:MetadataCalendarsObject} $calendarsMetadataObj */
        $calendarsMetadataObj    = Utilities::jsonUrlToObject(Route::CALENDARS->path());
        $this->calendarsMetadata = MetadataCalendars::fromObject($calendarsMetadataObj->litcal_metadata);

        // We need at least a default value for the current year and for the locale
        //   (which we already took from the request headers)
        $this->Year = (int) date('Y');
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
        if (count($params) === 0) {
            // If no parameters are provided, we can just return
            return;
        }
        foreach ($params as $key => $value) {
            if (in_array($key, self::ALLOWED_PARAMS)) {
                switch ($key) {
                    case 'locale':
                        $locale = \Locale::canonicalize($value);
                        if (null === $locale) {
                            throw new ValidationException('Invalid locale string: ' . $value);
                        }

                        $this->Locale = LitLocale::isValid($locale) ? $locale : LitLocale::LATIN;
                        $baseLocale   = \Locale::getPrimaryLanguage($this->Locale);
                        if (null === $baseLocale) {
                            $description = '“The evil spirit had bound his tongue, and together with his tongue had fettered his soul.” — St. John Chrysostom, Homily 32 on Matthew';
                            throw new ValidationException($description);
                        }
                        $this->baseLocale = $baseLocale;
                        break;
                    case 'national_calendar':
                        if (false === $this->isValidNationalCalendar($value)) {
                            $description = "Unknown value `$value` for nation parameter, supported national calendars are: ["
                                . implode(',', $this->calendarsMetadata->national_calendars_keys) . ']';
                            throw new ValidationException($description);
                        }
                        if ($value === 'VA') {
                            $this->Locale                  = LitLocale::LATIN;
                            $this->baseLocale              = LitLocale::LATIN_PRIMARY_LANGUAGE;
                            $this->EternalHighPriest       = false;
                            $params['eternal_high_priest'] = false;
                            $params['locale']              = LitLocale::LATIN;
                        } else {
                            $this->NationalCalendar = strtoupper($value);
                        }
                        break;
                    case 'diocesan_calendar':
                        if (false === $this->isValidDiocesanCalendar($value)) {
                            $description = "unknown value `$value` for diocese parameter, supported diocesan calendars are: ["
                                . implode(',', $this->calendarsMetadata->diocesan_calendars_keys) . ']';
                            throw new ValidationException($description);
                        }
                        $this->DiocesanCalendar = $value;
                        break;
                    case 'eternal_high_priest':
                        $filteredBoolValue = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                        if (null === $filteredBoolValue) {
                            $description = "Invalid value `$value` for eternal_high_priest parameter, must be boolean";
                            throw new ValidationException($description);
                        }
                        $this->EternalHighPriest = $filteredBoolValue;
                        break;
                }
            }
        }
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
