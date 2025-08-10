<?php

namespace LiturgicalCalendar\Api\Models\Metadata;

use LiturgicalCalendar\Api\Models\AbstractJsonRepresentation;

/**
 * @phpstan-import-type NationalCalendarSettingsObject from MetadataNationalCalendarSettings
 * @phpstan-import-type NationalCalendarSettingsArray from MetadataNationalCalendarSettings
 *
 * @phpstan-type NationalCalendarMetadataObject \stdClass&object{
 *      calendar_id:string,
 *      locales:string[],
 *      missals:string[],
 *      settings:NationalCalendarSettingsObject,
 *      wider_region?:string,
 *      dioceses?:string[]
 * }
 *
 * @phpstan-type NationalCalendarMetadataArray array{
 *      calendar_id:string,
 *      locales:string[],
 *      missals:string[],
 *      settings:NationalCalendarSettingsArray,
 *      wider_region?:string,
 *      dioceses?:string[]
 * }
 *
 */
final class MetadataNationalCalendarItem extends AbstractJsonRepresentation
{
    public string $calendar_id;

    /** @var string[] */
    public array $locales;

    /** @var string[] */
    public array $missals;

    public ?string $wider_region;

    /** @var string[]|null */
    public ?array $dioceses;

    public ?MetadataNationalCalendarSettings $settings;


    /**
     * Constructor for NationalCalendarMetadataItem.
     *
     * @param string $calendar_id The unique identifier for the National Calendar.
     * @param string[] $locales The locales supported by the National Calendar.
     * @param string[] $missals The missals supported by the National Calendar.
     * @param string $wider_region The wider region to which the National Calendar belongs.
     * @param string[] $dioceses The dioceses that use the National Calendar.
     * @param MetadataNationalCalendarSettings $settings The settings for the National Calendar.
     */
    public function __construct(
        string $calendar_id,
        array $locales,
        array $missals,
        ?MetadataNationalCalendarSettings $settings,
        ?string $wider_region = null,
        ?array $dioceses = null
    ) {
        $this->calendar_id  = $calendar_id;
        $this->locales      = $locales;
        $this->missals      = $missals;
        $this->settings     = $settings;
        $this->wider_region = $wider_region;
        $this->dioceses     = $dioceses;
    }

    /**
     * {@inheritDoc}
     *
     * Converts the object to an array that can be json encoded,
     * containing the following keys:
     * - calendar_id: The unique identifier for the National Calendar.
     * - locales: The locales supported by the National Calendar.
     * - missals: The missals supported by the National Calendar.
     * - settings: The settings for the National Calendar, serialized to an array.
     * - wider_region: The wider region to which the National Calendar belongs, if applicable.
     * - dioceses: The dioceses that use the National Calendar, if applicable.
     *
     * @return array{calendar_id:string,locales:string[],missals:string[],settings:array{epiphany:string,ascension:string,corpus_christi:string,eternal_high_priest:bool},wider_region?:string,dioceses?:string[]} The associative array containing the National Calendar's metadata.
     */
    public function jsonSerialize(): array
    {
        // Whenever we are serializing the data, we expect settings to exist under metadata
        if (false === $this->settings instanceof MetadataNationalCalendarSettings) {
            throw new \RuntimeException('settings must be an instance of MetadataNationalCalendarSettings for serialization purposes');
        }

        $retArr = [
            'calendar_id' => $this->calendar_id,
            'locales'     => $this->locales,
            'missals'     => $this->missals,
            'settings'    => $this->settings->jsonSerialize()
        ];
        if ($this->wider_region !== null) {
            $retArr['wider_region'] = $this->wider_region;
        }
        if ($this->dioceses !== null) {
            $retArr['dioceses'] = $this->dioceses;
        }
        return $retArr;
    }

    /**
     * Creates an instance of NationalCalendarMetadataItem from an associative array.
     *
     * The array should have the following keys:
     * - calendar_id (string): The unique identifier for the National Calendar.
     * - locales (string[]): The locales supported by the National Calendar.
     * - missals (string[]): The missals supported by the National Calendar.
     * - settings (string[]): The settings for the National Calendar, serialized to an array.
     *
     * The array may also have the following optional keys:
     * - wider_region (string|null): The wider region to which the National Calendar belongs, if applicable.
     * - dioceses (string[]|null): The dioceses that use the National Calendar, if applicable.
     *
     * @param NationalCalendarMetadataArray $data
     * @return static
     */
    protected static function fromArrayInternal(array $data): static
    {
        if (array_key_exists('nation', $data)) {
            // in the calendar source file, the calendar_id is called nation
            throw new \RuntimeException('Perhaps you meant to use \LiturgicalCalendar\Api\Models\RegionalData\NationalData\NationalMetadata::fromArray?');
        }

        return new static(
            $data['calendar_id'],
            $data['locales'],
            $data['missals'],
            isset($data['settings']) ? MetadataNationalCalendarSettings::fromArray($data['settings']) : null,
            isset($data['wider_region']) ? $data['wider_region'] : null,
            $data['dioceses'] ?? null
        );
    }

    /**
     * Creates an instance of NationalCalendarMetadataItem from a stdClass object.
     *
     * The object should have the following properties:
     * - calendar_id (string): The unique identifier for the National Calendar.
     * - locales (string[]): The locales supported by the National Calendar.
     * - missals (string[]): The missals supported by the National Calendar.
     * - settings (object): The settings for the National Calendar, serialized to an object.
     *
     * The object may also have the following optional properties:
     * - wider_region (string|null): The wider region to which the National Calendar belongs, if applicable.
     * - dioceses (string[]|null): The dioceses that use the National Calendar, if applicable.
     *
     * @param NationalCalendarMetadataObject $data
     * @return static
     */
    protected static function fromObjectInternal(\stdClass $data): static
    {
        if (property_exists($data, 'nation')) {
            // in the calendar source file, the calendar_id is called nation
            throw new \RuntimeException('Perhaps you meant to use \LiturgicalCalendar\Api\Models\RegionalData\NationalData\NationalMetadata::fromObject?');
        }

        return new static(
            $data->calendar_id,
            $data->locales,
            $data->missals,
            isset($data->settings) ? MetadataNationalCalendarSettings::fromObject($data->settings) : null,
            isset($data->wider_region) ? $data->wider_region : null,
            $data->dioceses ?? null
        );
    }
}
