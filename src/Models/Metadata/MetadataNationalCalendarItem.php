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
 *      wider_regions?:string[],
 *      wider_region?:string,
 *      dioceses?:string[]
 * }
 *
 * @phpstan-type NationalCalendarMetadataArray array{
 *      calendar_id:string,
 *      locales:string[],
 *      missals:string[],
 *      settings:NationalCalendarSettingsArray,
 *      wider_regions?:string[],
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

    /** @var list<string> The wider regions the nation declares, most general first. */
    public array $wider_regions;

    /** @var string[]|null */
    public ?array $dioceses;

    public ?MetadataNationalCalendarSettings $settings;


    /**
     * Constructor for NationalCalendarMetadataItem.
     *
     * @param string $calendar_id The unique identifier for the National Calendar.
     * @param string[] $locales The locales supported by the National Calendar.
     * @param string[] $missals The missals supported by the National Calendar.
     * @param list<string> $wider_regions The wider regions to which the National Calendar belongs, most general first.
     * @param string[] $dioceses The dioceses that use the National Calendar.
     * @param MetadataNationalCalendarSettings $settings The settings for the National Calendar.
     */
    public function __construct(
        string $calendar_id,
        array $locales,
        array $missals,
        ?MetadataNationalCalendarSettings $settings,
        array $wider_regions = [],
        ?array $dioceses = null
    ) {
        $this->calendar_id   = $calendar_id;
        $this->locales       = $locales;
        $this->missals       = $missals;
        $this->settings      = $settings;
        $this->wider_regions = array_values($wider_regions);
        $this->dioceses      = $dioceses;
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
     * - wider_regions: The wider regions to which the National Calendar belongs.
     * - wider_region: Deprecated single form, present only when there is exactly one wider region.
     * - dioceses: The dioceses that use the National Calendar, if applicable.
     *
     * @return array{calendar_id:string,locales:string[],missals:string[],settings:array{epiphany:string,ascension:string,corpus_christi:string,eternal_high_priest:bool},wider_regions:string[],wider_region?:string,dioceses?:string[]} The associative array containing the National Calendar's metadata.
     */
    public function jsonSerialize(): array
    {
        // Whenever we are serializing the data, we expect settings to exist under metadata
        if (false === $this->settings instanceof MetadataNationalCalendarSettings) {
            throw new \RuntimeException('settings must be an instance of MetadataNationalCalendarSettings for serialization purposes');
        }

        $retArr                  = [
            'calendar_id' => $this->calendar_id,
            'locales'     => $this->locales,
            'missals'     => $this->missals,
            'settings'    => $this->settings->jsonSerialize()
        ];
        $retArr['wider_regions'] = $this->wider_regions;
        // Deprecated (#1005): the single form is kept for clients written for one region, but only when the nation
        // has exactly one, so such a client never sees a list truncated to its first element.
        if (count($this->wider_regions) === 1) {
            $retArr['wider_region'] = $this->wider_regions[0];
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
     * - wider_regions (string[]): The wider regions to which the National Calendar belongs, if applicable.
     * - wider_region (string|null): Deprecated single form, read as a one-element list when `wider_regions` is absent.
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
            self::widerRegionsFrom($data['wider_regions'] ?? null, $data['wider_region'] ?? null),
            $data['dioceses'] ?? null
        );
    }

    /**
     * @return list<string>
     */
    private static function widerRegionsFrom(mixed $list, mixed $legacy): array
    {
        if (is_array($list)) {
            return array_values(array_filter($list, 'is_string'));
        }

        return is_string($legacy) && $legacy !== '' ? [$legacy] : [];
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
     * - wider_regions (string[]): The wider regions to which the National Calendar belongs, if applicable.
     * - wider_region (string|null): Deprecated single form, read as a one-element list when `wider_regions` is absent.
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
            self::widerRegionsFrom($data->wider_regions ?? null, $data->wider_region ?? null),
            $data->dioceses ?? null
        );
    }
}
