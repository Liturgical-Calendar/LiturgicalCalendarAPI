<?php

namespace LiturgicalCalendar\Api\Models;

class MetadataNationalCalendarItem implements \JsonSerializable
{
    public readonly string $calendar_id;
    /** @var array<string> */
    public readonly array $locales;
    /** @var array<string> */
    public readonly array $missals;
    public readonly ?string $wider_region;
    /** @var array<string>|null */
    public readonly ?array $dioceses;
    public readonly MetadataNationalCalendarSettings $settings;

    /**
     * Constructor for NationalCalendarMetadataItem.
     *
     * @param string $calendar_id The unique identifier for the National Calendar.
     * @param array<string> $locales The locales supported by the National Calendar.
     * @param array<string> $missals The missals supported by the National Calendar.
     * @param string $wider_region The wider region to which the National Calendar belongs.
     * @param array<string> $dioceses The dioceses that use the National Calendar.
     * @param MetadataNationalCalendarSettings $settings The settings for the National Calendar.
     */
    public function __construct(
        string $calendar_id,
        array $locales,
        array $missals,
        ?string $wider_region = null,
        ?array $dioceses = null,
        MetadataNationalCalendarSettings $settings
    ) {
        $this->calendar_id  = $calendar_id;
        $this->locales      = $locales;
        $this->missals      = $missals;
        $this->wider_region = $wider_region;
        $this->dioceses     = $dioceses;
        $this->settings     = $settings;
    }

    /**
     * @inheritDoc
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
     * @return array{calendar_id:string,locales:array<string>,missals:array<string>,settings:array<string>,wider_region?:string,dioceses?:array<string>} The associative array containing the National Calendar's metadata.
     */
    public function jsonSerialize(): array
    {
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
     * - locales (array<string>): The locales supported by the National Calendar.
     * - missals (array<string>): The missals supported by the National Calendar.
     * - settings (array<string>): The settings for the National Calendar, serialized to an array.
     *
     * The array may also have the following optional keys:
     * - wider_region (string|null): The wider region to which the National Calendar belongs, if applicable.
     * - dioceses (array<string>|null): The dioceses that use the National Calendar, if applicable.
     *
     * @param array{
     *      calendar_id: string,
     *      locales: array<string>,
     *      missals: array<string>,
     *      settings: array<string>,
     *      wider_region?: string,
     *      dioceses?: array<string>
     * } $data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['calendar_id'],
            $data['locales'],
            $data['missals'],
            $data['wider_region'] ?? null,
            $data['dioceses'] ?? null,
            MetadataNationalCalendarSettings::fromArray($data['settings'])
        );
    }

    /**
     * Creates an instance of NationalCalendarMetadataItem from a stdClass object.
     *
     * The object should have the following properties:
     * - calendar_id (string): The unique identifier for the National Calendar.
     * - locales (array<string>): The locales supported by the National Calendar.
     * - missals (array<string>): The missals supported by the National Calendar.
     * - settings (object): The settings for the National Calendar, serialized to an object.
     *
     * The object may also have the following optional properties:
     * - wider_region (string|null): The wider region to which the National Calendar belongs, if applicable.
     * - dioceses (array<string>|null): The dioceses that use the National Calendar, if applicable.
     *
     * @param \stdClass $data
     * @return self
     */
    public static function fromObject(\stdClass $data): self
    {
        return new self(
            $data->calendar_id,
            $data->locales,
            $data->missals,
            $data->wider_region ?? null,
            $data->dioceses ?? null,
            MetadataNationalCalendarSettings::fromObject($data->settings)
        );
    }
}
