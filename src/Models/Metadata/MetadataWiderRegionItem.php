<?php

namespace LiturgicalCalendar\Api\Models\Metadata;

use LiturgicalCalendar\Api\Models\AbstractJsonRepresentation;

final class MetadataWiderRegionItem extends AbstractJsonRepresentation
{
    public string $name;

    /** @var string[] */
    public array $locales;

    public string $api_path;

    /** @var list<string> Codes of the nations that have a calendar and declare this region, sorted. */
    public array $national_calendars;

    /** @var list<string> Codes of every nation eligible to join this region (its file's `national_calendars` map), sorted. */
    public array $roster;

    /**
     * Initializes a MetadataWiderRegionItem object.
     *
     * @param string $name The name of the wider region.
     * @param string[] $locales The locales supported by the wider region.
     * @param string $api_path The API path for accessing the wider region data.
     * @param string[] $national_calendars Codes of the nations that have a calendar and declare this region.
     * @param string[] $roster Codes of every nation eligible to join this region.
     */
    public function __construct(
        string $name,
        array $locales,
        string $api_path,
        array $national_calendars = [],
        array $roster = []
    ) {
        $this->name               = $name;
        $this->locales            = $locales;
        $this->api_path           = $api_path;
        $this->national_calendars = array_values($national_calendars);
        $this->roster             = array_values($roster);
    }

    /**
     * Converts the MetadataWiderRegionItem object to an associative array
     * for JSON serialization.
     *
     * The array contains the following keys:
     * - name: The name of the wider region.
     * - locales: An array of locales supported by the wider region.
     * - api_path: The API path for accessing the wider region data.
     * - national_calendars: Codes of the nations that have a calendar and declare this region.
     * - roster: Codes of every nation eligible to join this region.
     *
     * @return array{name:string,locales:string[],api_path:string,national_calendars:string[],roster:string[]} The associative array representation of the object.
     */
    public function jsonSerialize(): array
    {
        return [
            'name'               => $this->name,
            'locales'            => $this->locales,
            'api_path'           => $this->api_path,
            'national_calendars' => $this->national_calendars,
            'roster'             => $this->roster
        ];
    }

    /**
     * Creates an instance of MetadataWiderRegionItem from an associative array.
     *
     * The array must have the following keys:
     * - name (string): The name of the wider region.
     * - locales (string[]): The locales supported by the wider region.
     * - api_path (string): The API path for accessing the wider region data.
     *
     * The array may also have the following optional keys:
     * - national_calendars (string[]): Codes of the nations that have a calendar and declare this region.
     * - roster (string[]): Codes of every nation eligible to join this region.
     *
     * @param array{name:string,locales:string[],api_path:string,national_calendars?:string[],roster?:string[]} $data
     * @return static
     */
    protected static function fromArrayInternal(array $data): static
    {
        return new static(
            $data['name'],
            $data['locales'],
            $data['api_path'],
            $data['national_calendars'] ?? [],
            $data['roster'] ?? []
        );
    }

    /**
     * Creates an instance of MetadataWiderRegionItem from a stdClass object.
     *
     * The object should have the following properties:
     * - name (string): The name of the wider region.
     * - locales (string[]): The locales supported by the wider region.
     * - api_path (string): The API path for accessing the wider region data.
     *
     * The object may also have the following optional properties:
     * - national_calendars (string[]): Codes of the nations that have a calendar and declare this region.
     * - roster (string[]): Codes of every nation eligible to join this region.
     *
     * @param \stdClass&object{name:string,locales:string[],api_path:string,national_calendars?:string[],roster?:string[]} $data
     * @return static
     */
    protected static function fromObjectInternal(\stdClass $data): static
    {
        return new static(
            $data->name,
            $data->locales,
            $data->api_path,
            $data->national_calendars ?? [],
            $data->roster ?? []
        );
    }
}
