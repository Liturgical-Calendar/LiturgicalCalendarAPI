<?php

namespace LiturgicalCalendar\Api\Params;

use LiturgicalCalendar\Api\Paths\RegionalDataPath;
use LiturgicalCalendar\Api\Enum\Route;
use LiturgicalCalendar\Api\Enum\StatusCode;
use LiturgicalCalendar\Api\Enum\LitLocale;
use LiturgicalCalendar\Api\Enum\PathCategory;
use LiturgicalCalendar\Api\Enum\RequestMethod;
use LiturgicalCalendar\Api\Models\Metadata\MetadataCalendars;
use LiturgicalCalendar\Api\Models\Metadata\MetadataWiderRegionItem;
use LiturgicalCalendar\Api\Models\RegionalData\DiocesanData\DiocesanData;
use LiturgicalCalendar\Api\Models\RegionalData\NationalData\NationalData;
use LiturgicalCalendar\Api\Models\RegionalData\WiderRegionData\WiderRegionData;
use LiturgicalCalendar\Api\Utilities;

/**
 * Class RegionalDataParams
 *
 * This class is responsible for handling the parameters provided to the RegionalData class.
 *
 * The class is initialized with a set of parameters passed in from the API request. These parameters
 * are used to determine which calendar data to retrieve or update or delete.
 *
 * @package LiturgicalCalendar\Api\Params
 */
class RegionalDataParams implements ParamsInterface
{
    private readonly ?MetadataCalendars $calendars;

    public PathCategory $category;
    public ?string $key         = null;
    public ?string $locale      = null;
    public ?string $i18nRequest = null;
    public DiocesanData|NationalData|WiderRegionData $payload;

    /**
     * Constructor for RegionalDataParams
     *
     * Initializes the RegionalDataParams object by loading calendar metadata
     * from the specified API path. If the metadata is successfully retrieved
     * and parsed, it removes the Vatican calendar from the list of national
     * calendars and assigns the metadata to the $calendars property. If
     * any error occurs during retrieval or parsing, the $calendars property
     * is set to null.
     *
     * Additionally, it initializes the list of available system locales.
     */
    public function __construct()
    {
        $metadata = Utilities::jsonUrlToObject(API_BASE_PATH . Route::CALENDARS->value);
        if (property_exists($metadata, 'litcal_metadata')) {
            // let's remove the Vatican calendar from the list
            array_shift($metadata->litcal_metadata->national_calendars);
            $this->calendars = MetadataCalendars::fromObject($metadata->litcal_metadata);
        } else {
            $this->calendars = null;
        }

        // Initialize the list of available locales
        LitLocale::init();
    }

    /**
     * Validate the parameters provided to the RegionalData class for a National Calendar.
     *
     * The method checks the following, given that the `category` parameter is "NATIONALCALENDAR":
     * - The `key` parameter is a valid nation.
     * - The `locale` parameter is a valid locale for the given nation.
     * - If the request method is PUT, the National Calendar data does not already exist.
     * - If the request method is DELETE, the National Calendar data is not in use by a Diocesan calendar.
     *
     * If any of the checks fail, the method will produce an error response with a 400 status code.
     *
     * @param array{
     *      category: PathCategory,
     *      key: string,
     *      i18n?: string,
     *      payload?: object,
     *      locale?: string
     * } $params The parameters to validate.
     * @return string
     *      The validated nation value.
     */
    private function checkNationalCalendarConditions(array $params): string
    {
        $requiredKeys = ['key', 'category'];
        $givenKeys    = array_keys($params);
        if (count(array_intersect($requiredKeys, $givenKeys)) !== count($requiredKeys)) {
            RegionalDataPath::produceErrorResponse(
                StatusCode::BAD_REQUEST,
                'Missing required parameter(s) `' . implode('`, `', array_diff($requiredKeys, $givenKeys)) . '`'
            );
        }

        if (RegionalDataPath::$Core->getRequestMethod() === RequestMethod::PUT) {
            // Cannot PUT National calendar data if it already exists
            if (in_array($params['key'], $this->calendars->national_calendars_keys)) {
                RegionalDataPath::produceErrorResponse(
                    StatusCode::BAD_REQUEST,
                    'Cannot PUT National Calendar data for nation with ID: ' . $params['key'] . ' since it already exists. Perhaps you meant to use PATCH?'
                );
            }

            $uniqueRegions = array_values(array_unique(array_map(fn (string $locale) => \Locale::getRegion($locale) !== '', LitLocale::$AllAvailableLocales)));
            if (false === in_array($params['key'], $uniqueRegions)) {
                RegionalDataPath::produceErrorResponse(
                    StatusCode::BAD_REQUEST,
                    'Cannot PUT National Calendar data for an invalid nation. Valid nation IDs are: ' . implode(', ', $uniqueRegions) . '.'
                );
            }
        } elseif (RegionalDataPath::$Core->getRequestMethod() === RequestMethod::DELETE) {
            // Cannot DELETE a National calendar data if it is still in use by a Diocesan calendar
            foreach ($this->calendars->diocesan_calendars as $diocesanCalendar) {
                if ($diocesanCalendar->nation === $params['key']) {
                    RegionalDataPath::produceErrorResponse(
                        StatusCode::BAD_REQUEST,
                        'Cannot DELETE National Calendar data while there are Diocesan calendars that depend on it.'
                        . " Currently, {$params['key']} is in use by Diocesan calendar {$diocesanCalendar->calendar_id}."
                    );
                }
            }
        }

        // We must verify the `key` parameter for any request that is not PUT
        $currentNation = null;
        if (RegionalDataPath::$Core->getRequestMethod() !== RequestMethod::PUT) {
            if (false === in_array($params['key'], $this->calendars->national_calendars_keys)) {
                $validVals = implode(', ', $this->calendars->national_calendars_keys);
                RegionalDataPath::produceErrorResponse(
                    StatusCode::BAD_REQUEST,
                    "Invalid value {$params['key']} for param `key`, valid values are: {$validVals}"
                );
            } else {
                $currentNation = array_find($this->calendars->national_calendars, fn ($el) => $el->calendar_id === $params['key']);
                if (null === $currentNation) {
                    RegionalDataPath::produceErrorResponse(
                        StatusCode::BAD_REQUEST,
                        "Invalid value '{$params['key']}' for param `key`, valid values are: "
                            . implode(', ', $this->calendars->national_calendars_keys)
                    );
                }
            }
        }


        // we don't care about locale for DELETE or PUT requests
        if (false === in_array(RegionalDataPath::$Core->getRequestMethod(), [RequestMethod::DELETE, RequestMethod::PUT], true)) {
            $validLangs = $currentNation->locales;
            if (array_key_exists('locale', $params)) {
                $params['locale'] = \Locale::canonicalize($params['locale']);
                if (
                    null !== $this->i18nRequest // short circuit for i18n requests
                    || in_array($params['locale'], $validLangs, true)
                ) {
                    $this->locale = $params['locale'];
                } else {
                    $message = "Invalid value {$params['locale']} for param `locale`, valid values for nation {$params['key']} are: "
                                . implode(', ', $validLangs);
                    RegionalDataPath::produceErrorResponse(StatusCode::BAD_REQUEST, $message);
                }
            } elseif (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
                $value = \Locale::acceptFromHttp($_SERVER['HTTP_ACCEPT_LANGUAGE']);
                if (
                    null !== $this->i18nRequest // short circuit for i18n requests
                    || in_array($value, $validLangs, true)
                ) {
                    $this->locale = $value ?: 'en'; // fallback to English, if accept-language header is invalid or empty
                } else {
                    $message = "Invalid value {$value} for Accept-Language header, valid values for nation {$params['key']} are: "
                                . implode(', ', $validLangs);
                    RegionalDataPath::produceErrorResponse(StatusCode::BAD_REQUEST, $message);
                }
            } else {
                RegionalDataPath::produceErrorResponse(StatusCode::BAD_REQUEST, '`locale` param or `Accept-Language` header required for Wider Region calendar data');
            }
        }

        return $params['key'];
    }

    /**
     * Validate the parameters provided to the RegionalData class for a Diocesan Calendar.
     *
     * The method checks the following, given that the `category` parameter is "DIOCESANCALENDAR":
     * - The `key` parameter is a valid diocesan calendar key.
     * - The `locale` parameter is a valid locale for the given diocesan calendar. ::TODO::
     * - If the request method is PUT, the Diocesan Calendar data does not already exist.
     *
     * If any of the checks fail, the method will produce an error response with a 400 status code.
     *
     * @param array{
     *      category: PathCategory,
     *      key: string,
     *      i18n?: string,
     *      payload?: object,
     *      locale?: string
     * } $params The parameters to validate.
     * @return string
     *      The validated diocesan calendar value.
     */
    private function checkDiocesanCalendarConditions(array $params): string
    {
        $requiredKeys = ['key', 'category'];
        $givenKeys    = array_keys($params);
        if (count(array_intersect($requiredKeys, $givenKeys)) !== count($requiredKeys)) {
            RegionalDataPath::produceErrorResponse(
                StatusCode::BAD_REQUEST,
                'Missing required parameter(s) `' . implode('`, `', array_diff($requiredKeys, $givenKeys)) . '`'
            );
        }

        // For all requests other than PUT, we expect the diocese_id to exist
        if (RegionalDataPath::$Core->getRequestMethod() !== RequestMethod::PUT) {
            if (false === in_array($params['key'], $this->calendars->diocesan_calendars_keys)) {
                $validVals = implode(', ', $this->calendars->diocesan_calendars_keys);
                RegionalDataPath::produceErrorResponse(
                    StatusCode::BAD_REQUEST,
                    "Invalid value {$params['key']} for param `key`, valid values are: {$validVals}"
                );
            }

            // For all requests other than PUT and DELETE, we expect a valid locale parameter
            if (RegionalDataPath::$Core->getRequestMethod() !== RequestMethod::DELETE) {
                $currentDiocese = array_find($this->calendars->diocesan_calendars, fn ($el) => $el->calendar_id === $params['key']);
                $validLangs     = $currentDiocese->locales;
                if (array_key_exists('locale', $params)) {
                    $params['locale'] = \Locale::canonicalize($params['locale']);
                    if (
                        null !== $this->i18nRequest // short circuit for i18n requests
                        || in_array($params['locale'], $validLangs, true)
                    ) {
                        $this->locale = $params['locale'];
                    } else {
                        $message = "Invalid value {$params['locale']} for param `locale`, valid values for {$currentDiocese->diocese} are: "
                                    . implode(', ', $validLangs);
                        RegionalDataPath::produceErrorResponse(StatusCode::BAD_REQUEST, $message);
                    }
                } elseif (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
                    $value = \Locale::acceptFromHttp($_SERVER['HTTP_ACCEPT_LANGUAGE']);
                    if (
                        null !== $this->i18nRequest // short circuit for i18n requests
                        || in_array($value, $validLangs, true) // otherwise check against valid langs
                    ) {
                        $this->locale = $value ?: 'en'; // fallback to 'en', if accept-language header is invalid or empty
                    } else {
                        $message = "Invalid value {$value} for Accept-Language header, valid values for {$currentDiocese->diocese} are: "
                                    . implode(', ', $validLangs);
                        RegionalDataPath::produceErrorResponse(StatusCode::BAD_REQUEST, $message);
                    }
                } else {
                    RegionalDataPath::produceErrorResponse(StatusCode::BAD_REQUEST, '`locale` param or `Accept-Language` header required for Diocesan calendar data when request method is GET, POST, or PATCH');
                }
            }
        } else {
            // For PUT requests, we expect the diocese_id to not exist
            if (in_array($params['key'], $this->calendars->diocesan_calendars_keys)) {
                RegionalDataPath::produceErrorResponse(
                    StatusCode::BAD_REQUEST,
                    "Cannot create diocesan calendar with id: {$params['key']}, since there is already a resource with that id. Perhaps you meant to use PATCH?"
                );
            }
        }

        return $params['key'];
    }

    /**
     * Validate the parameters provided to the RegionalData class for a Wider Region Calendar.
     *
     * The method checks the following, given that the `category` parameter is "WIDERREGIONCALENDAR":
     * - The `key` parameter is a valid wider region calendar key.
     * - The `locale` parameter is a valid locale for the given wider region calendar.
     * - If the request method is PUT, the Wider Region Calendar data does not already exist. ::TODO::
     * - If the request method is DELETE, there are no national calendars that depend on the wider region calendar.
     *
     * If any of the checks fail, the method will produce an error response with a 400 status code.
     *
     * @param array{
     *      category: PathCategory,
     *      key: string,
     *      i18n?: string,
     *      payload?: object,
     *      locale?: string
     * } $params The parameters to validate.
     *
     * @return string
     *      The validated wider region calendar value.
     */
    private function checkWiderRegionCalendarConditions(array $params)
    {
        if (
            false === in_array($params['key'], $this->calendars->wider_regions_keys, true)
            && RegionalDataPath::$Core->getRequestMethod() !== RequestMethod::PUT
        ) {
            $validVals = implode(', ', $this->calendars->wider_regions_keys);
            $message   = "Invalid value {$params['key']} for param `key`, valid values are: {$validVals}";
            RegionalDataPath::produceErrorResponse(StatusCode::NOT_FOUND, $message);
        }

        // The locale parameter can be supplied by the Accept-Language header or by a `locale` property in the payload.
        $currentWiderRegion = array_find($this->calendars->wider_regions, fn (MetadataWiderRegionItem $el) => $el->name === $params['key']);
        $validLangs         = $currentWiderRegion->locales;
        if (array_key_exists('locale', $params)) {
            $params['locale'] = \Locale::canonicalize($params['locale']);
            if (
                RegionalDataPath::$Core->getRequestMethod() === RequestMethod::PUT // short circuit for PUT requests that don't need to validate against existing locales
                || null !== $this->i18nRequest // short circuit for i18n requests
                || in_array($params['locale'], $validLangs, true)
            ) {
                $this->locale = $params['locale'];
            } else {
                $message = "Invalid value {$params['locale']} for param `locale`, valid values for wider region {$currentWiderRegion->name} are: "
                            . implode(', ', $validLangs);
                RegionalDataPath::produceErrorResponse(StatusCode::BAD_REQUEST, $message);
            }
        } elseif (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
            $value = \Locale::acceptFromHttp($_SERVER['HTTP_ACCEPT_LANGUAGE']);
            if (
                RegionalDataPath::$Core->getRequestMethod() === RequestMethod::PUT // short circuit for PUT requests that don't need to validate against existing locales
                || null !== $this->i18nRequest // short circuit for i18n requests
                || in_array($value, $validLangs, true)
            ) {
                $this->locale = $value ?: 'en'; // fallback to 'en', if accept-language header is invalid or empty
            } else {
                $message = "Invalid value {$value} for Accept-Language header, valid values for wider region {$currentWiderRegion->name} are: "
                            . implode(', ', $validLangs);
                RegionalDataPath::produceErrorResponse(StatusCode::BAD_REQUEST, $message);
            }
        } else {
            RegionalDataPath::produceErrorResponse(StatusCode::BAD_REQUEST, '`locale` param or `Accept-Language` header required for Wider Region calendar data');
        }
        // Check the request method: cannot DELETE Wider Region calendar data if there are national calendars that depend on it
        if (RegionalDataPath::$Core->getRequestMethod() === RequestMethod::DELETE) {
            $national_calendars_within_wider_region = array_values(array_filter(
                $this->calendars->national_calendars,
                fn ($el) => $el->wider_region === $params['key']
            ));
            if (count($national_calendars_within_wider_region) > 0) {
                RegionalDataPath::produceErrorResponse(
                    StatusCode::BAD_REQUEST,
                    'Cannot DELETE Wider Region calendar data while there are National calendars that depend on it. '
                    . "Currently {$params['key']} is in use by the National Calendars: " . implode(', ', array_map(fn ($el) => \Locale::getDisplayRegion('-' . $el->calendar_id, 'en'), $national_calendars_within_wider_region))
                );
            }
        }
        return $params['key'];
    }


    /**
     * Validates and sets the parameters for the RegionalData class.
     *
     * The method expects the following keys in the `$params` array:
     * - `category`: one of the values in {@see \LiturgicalCalendar\Api\Params\RegionalDataParams::EXPECTED_CATEGORIES}
     * - `key`: a valid key for the given category
     *
     * The method will produce a 400 error if either of the above keys are missing or invalid.
     *
     * If the request method is GET or POST and the `i18n` property is present in the `$params` array,
     * it will be used to set the `i18nRequest` property (meaning the request is for i18n data, not calendar data).
     *
     * If the request method is PUT or PATCH, we expect the payload to be of type DiocesanData, NationalData, or WiderRegionData,
     *   and if so we set the `payload` property; if not an error is produced.
     *
     * @param array{
     *      category: PathCategory,
     *      key: string,
     *      i18n?: ?string,
     *      locale?: string,
     *      payload?: NationalData|DiocesanData|WiderRegionData
     * } $params The parameters to validate and set.
     *
     */
    public function setParams(array $params): void
    {
        if (false === array_key_exists('category', $params) || false === array_key_exists('key', $params)) {
            RegionalDataPath::produceErrorResponse(
                StatusCode::BAD_REQUEST,
                'Expected params `category` and `key` but either one or both not present.'
            );
        }

        if (in_array(RegionalDataPath::$Core->getRequestMethod(), [RequestMethod::GET, RequestMethod::POST], true)) {
            if (array_key_exists('i18n', $params)) {
                $this->i18nRequest = $params['i18n'];
            }
        }

        $this->category = $params['category'];
        switch ($this->category) {
            case PathCategory::NATION:
                $this->key = $this->checkNationalCalendarConditions($params);
                break;
            case PathCategory::DIOCESE:
                $this->key = $this->checkDiocesanCalendarConditions($params);
                break;
            case PathCategory::WIDERREGION:
                $this->key = $this->checkWiderRegionCalendarConditions($params);
                break;
            default:
                $this->key = null;
        }

        if (in_array(RegionalDataPath::$Core->getRequestMethod(), [RequestMethod::PUT, RequestMethod::PATCH], true)) {
            if (
                false === array_key_exists('payload', $params)
                || ( false === $params['payload'] instanceof DiocesanData && false === $params['payload'] instanceof NationalData && false === $params['payload'] instanceof WiderRegionData )
            ) {
                RegionalDataPath::produceErrorResponse(StatusCode::BAD_REQUEST, 'Cannot create or update Calendar data without a payload');
            } else {
                $this->payload = $params['payload'];
            }
        }
    }
}
