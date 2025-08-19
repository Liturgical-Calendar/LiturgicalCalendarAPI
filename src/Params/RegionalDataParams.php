<?php

namespace LiturgicalCalendar\Api\Params;

use LiturgicalCalendar\Api\Enum\LitLocale;
use LiturgicalCalendar\Api\Enum\PathCategory;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use LiturgicalCalendar\Api\Models\RegionalData\DiocesanData\DiocesanData;
use LiturgicalCalendar\Api\Models\RegionalData\NationalData\NationalData;
use LiturgicalCalendar\Api\Models\RegionalData\WiderRegionData\WiderRegionData;

/**
 * Class RegionalDataParams
 *
 * This class is responsible for handling the parameters provided to the RegionalData class.
 *
 * The class is initialized with a set of parameters passed in from the API request. These parameters
 * are used to determine which calendar data to retrieve or update or delete.
 */
class RegionalDataParams implements ParamsInterface
{
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
     * @param array{category:PathCategory,key:string,i18n?:string,i18nRequest?:string,locale?:string,payload?:DiocesanData|NationalData|WiderRegionData} $params
     */
    public function __construct(array $params)
    {
        $this->setParams($params);
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
     *      category:PathCategory,
     *      key: string,
     *      i18n?: string,
     *      i18nRequest?: string,
     *      locale?: string,
     *      payload?: NationalData|DiocesanData|WiderRegionData
     * } $params The parameters to validate and set.
     *
     */
    public function setParams(array $params): void
    {
        if (false === array_key_exists('category', $params) || false === array_key_exists('key', $params)) {
            $description = 'Expected params `category` and `key` but either one or both not present.';
            throw new ValidationException($description);
        }

        if (array_key_exists('i18nRequest', $params)) {
            $this->i18nRequest = $params['i18nRequest'];
        }

        $this->category = $params['category'];
        $this->key      = $params['key'];

        if (array_key_exists('payload', $params)) {
            $this->payload = $params['payload'];
        }

        if (array_key_exists('locale', $params)) {
            $locale = \Locale::canonicalize($params['locale']);
            if ($locale && LitLocale::isValid($locale)) {
                $this->locale = $locale;
            }
        }
    }
}
