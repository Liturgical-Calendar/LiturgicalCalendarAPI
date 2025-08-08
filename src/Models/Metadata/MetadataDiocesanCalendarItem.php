<?php

namespace LiturgicalCalendar\Api\Models\Metadata;

use LiturgicalCalendar\Api\Models\AbstractJsonRepresentation;

/**
 * @phpstan-type DiocesanCalendarMetadata \stdClass&object{diocese_id:string,diocese_name:string,nation:string,locales:string[],timezone:string,group?:string}
 * @phpstan-import-type DiocesanCalendarSettings from MetadataDiocesanCalendarSettings
 */
final class MetadataDiocesanCalendarItem extends AbstractJsonRepresentation
{
    public string $calendar_id;

    public string $diocese;

    public string $nation;

    /** @var string[] */
    public array $locales;

    public string $timezone;

    public ?string $group;

    public ?MetadataDiocesanCalendarSettings $settings;

    /**
     * Constructor for DiocesanCalendarMetadataItem.
     *
     * @param string $calendar_id The unique identifier for the Diocesan Calendar.
     * @param string $diocese The diocese name.
     * @param string $nation The nation name.
     * @param string[] $locales The locales supported by the Diocesan Calendar.
     * @param string $timezone The timezone for the Diocesan Calendar.
     * @param string|null $group The group name for the Diocesan Calendar, or null if none.
     * @param MetadataDiocesanCalendarSettings|null $settings The settings for the Diocesan Calendar, or null if none.
     */
    public function __construct(
        string $calendar_id,
        string $diocese,
        string $nation,
        array $locales,
        string $timezone,
        ?string $group = null,
        ?MetadataDiocesanCalendarSettings $settings = null
    ) {
        $this->calendar_id = $calendar_id;
        $this->diocese     = $diocese;
        $this->nation      = $nation;
        $this->locales     = $locales;
        $this->timezone    = $timezone;
        $this->group       = $group;
        $this->settings    = $settings;
    }

    /**
     * {@inheritDoc}
     *
     * Converts the object to an array that can be json encoded,
     * containing the following keys:
     * - calendar_id: The unique identifier for the Diocesan Calendar.
     * - diocese: The diocese name.
     * - nation: The nation name.
     * - locales: The locales supported by the Diocesan Calendar.
     * - timezone: The timezone for the Diocesan Calendar.
     * - group: The group name for the Diocesan Calendar, if applicable.
     * - settings: The settings for the Diocesan Calendar, if applicable.
     *
     * @return array{calendar_id:string,diocese:string,nation:string,locales:string[],timezone:string,group?:string,settings?:array{epiphany?:string,ascension?:string,corpus_christi?:string,eternal_high_priest?:bool}} The associative array containing the Diocesan Calendar's metadata.
     */
    public function jsonSerialize(): array
    {
        $retArr = [
            'calendar_id' => $this->calendar_id,
            'diocese'     => $this->diocese,
            'nation'      => $this->nation,
            'locales'     => $this->locales,
            'timezone'    => $this->timezone
        ];
        if (null !== $this->group) {
            $retArr['group'] = $this->group;
        }
        if (null !== $this->settings) {
            $retArr['settings'] = $this->settings->jsonSerialize();
        }
        return $retArr;
    }

    /**
     * Creates an instance of DiocesanCalendarMetadataItem from an associative array.
     *
     * The array should have the following keys:
     * - calendar_id (string): The unique identifier for the Diocesan Calendar.
     * - diocese (string): The diocese name.
     * - nation (string): The nation name.
     * - locales (string[]): The locales supported by the Diocesan Calendar.
     * - timezone (string): The timezone for the Diocesan Calendar.
     *
     * The array may also have the following optional keys:
     * - group (string|null): The group name for the Diocesan Calendar, if applicable.
     * - settings (string[]|null): The settings for the Diocesan Calendar, if applicable.
     *
     * @param array{calendar_id:string,diocese:string,nation:string,locales:string[],timezone:string,group?:string,settings?:array{epiphany?:string,ascension?:string,corpus_christi?:string}} $data
     * @return static
     */
    protected static function fromArrayInternal(array $data): static
    {
        if (array_key_exists('settings', $data)) {
            $data['settings'] = MetadataDiocesanCalendarSettings::fromArray($data['settings']);
        }
        return new static(
            $data['calendar_id'] ?? $data['diocese_id'], // in the calendar source file, the calendar_id is called diocese_id
            $data['diocese'],
            $data['nation'],
            $data['locales'],
            $data['timezone'],
            $data['group'] ?? null,
            $data['settings'] ?? null
        );
    }

    /**
     * Creates an instance of DiocesanCalendarMetadataItem from a stdClass object.
     *
     * The object should have the following properties:
     * - calendar_id (string): The unique identifier for the Diocesan Calendar.
     * - diocese (string): The diocese name.
     * - nation (string): The nation name.
     * - locales (string[]): The locales supported by the Diocesan Calendar.
     * - timezone (string): The timezone for the Diocesan Calendar.
     *
     * The object may also have the following optional properties:
     * - group (string|null): The group name for the Diocesan Calendar, if applicable.
     * - settings (string[]|null): The settings for the Diocesan Calendar, if applicable.
     *
     * @param \stdClass&object{calendar_id:string,diocese:string,nation:string,locales:string[],timezone:string,group?:string,settings?:DiocesanCalendarSettings} $data
     * @return static
     */
    protected static function fromObjectInternal(\stdClass $data): static
    {
        $settings = null;
        if (property_exists($data, 'settings')) {
            $settings = MetadataDiocesanCalendarSettings::fromObject($data->settings);
        }
        return new static(
            $data->calendar_id ?? $data->diocese_id, // in the calendar source file, the calendar_id is called diocese_id
            $data->diocese,
            $data->nation,
            $data->locales,
            $data->timezone,
            $data->group ?? null,
            $settings
        );
    }
}
