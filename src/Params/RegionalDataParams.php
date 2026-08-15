<?php

namespace LiturgicalCalendar\Api\Params;

use LiturgicalCalendar\Api\Enum\LitLocale;
use LiturgicalCalendar\Api\Enum\PathCategory;
use LiturgicalCalendar\Api\Enum\Rite;
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

    /**
     * The rite the addressed calendar belongs to, from the optional leading rite
     * segment on `/data` (Roman when absent).
     */
    public Rite $rite           = Rite::ROMAN;
    public ?string $key         = null;
    public ?string $locale      = null;
    public ?string $i18nRequest = null;
    public DiocesanData|NationalData|WiderRegionData $payload;

    /**
     * Raw payload as stdClass for writing to disk.
     *
     * This preserves the original JSON structure from the request body,
     * avoiding serialization issues with PHP model classes that don't
     * implement JsonSerializable. The raw payload has already been
     * validated against the appropriate JSON schema.
     */
    public \stdClass $rawPayload;

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
     * @param array{category:PathCategory,key:string,rite?:Rite,i18n?:string,i18nRequest?:string,locale?:string,payload?:DiocesanData|NationalData|WiderRegionData,rawPayload?:\stdClass} $params
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
     *   We also store the raw payload (stdClass) for writing to disk without serialization issues.
     *
     * @param array{
     *      category:PathCategory,
     *      key: string,
     *      i18n?: string,
     *      i18nRequest?: string,
     *      locale?: string,
     *      payload?: NationalData|DiocesanData|WiderRegionData,
     *      rawPayload?: \stdClass
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

        if (array_key_exists('rite', $params) && $params['rite'] instanceof Rite) {
            $this->rite = $params['rite'];
        }

        $this->validateRiteCompatibility();

        if (array_key_exists('payload', $params)) {
            $this->payload = $params['payload'];

            // Require rawPayload when payload (DTO) is present for write operations
            if (!array_key_exists('rawPayload', $params)) {
                throw new ValidationException('rawPayload is required for write operations');
            }
            $this->rawPayload = $params['rawPayload'];
        }

        if (array_key_exists('locale', $params)) {
            $locale = \Locale::canonicalize($params['locale']);
            if (null === $locale) {
                $description = "Invalid value {$params['locale']} for param `locale`";
                throw new ValidationException($description);
            }
            if (LitLocale::isValid($locale)) {
                $this->locale = $locale;
            } else {
                $description = "Invalid value {$params['locale']} for param `locale`, valid values are: la, la_VA, " . implode(', ', LitLocale::$AllAvailableLocales);
                throw new ValidationException($description);
            }
        }
    }

    /**
     * Reject a category that the requested rite has no tier for.
     *
     * Mirrors {@see \LiturgicalCalendar\Api\Params\EventsParams::validateRiteCompatibility()}.
     * Only the diocesan tier exists under more than one rite: the Ambrosian rite is proper
     * to the Archdiocese of Milan and a handful of neighbouring dioceses rather than to any
     * nation or region, and the source tree has no `rite/ambrosian/calendars/nations` or
     * `.../wider_regions` to read or write.
     *
     * The diocese-belongs-to-this-rite check needs `/calendars` metadata and therefore lives
     * in the handler, alongside the lookup it shares.
     *
     * @throws ValidationException
     */
    public function validateRiteCompatibility(): void
    {
        if ($this->rite === Rite::ROMAN) {
            return;
        }

        if ($this->category === PathCategory::NATION) {
            throw new ValidationException(
                "The {$this->rite->value} rite has no national calendars; request a diocesan calendar of that rite instead."
            );
        }

        if ($this->category === PathCategory::WIDERREGION) {
            throw new ValidationException(
                "The {$this->rite->value} rite has no wider regions; wider regions are a layer over national calendars, which exist only in the Roman rite."
            );
        }
    }
}
