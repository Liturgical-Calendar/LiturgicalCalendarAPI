<?php

namespace LiturgicalCalendar\Api\Models\Metadata;

use LiturgicalCalendar\Api\Models\AbstractJsonRepresentation;
use LiturgicalCalendar\Api\Models\RegionalData\WiderRegionId;

final class MetadataWiderRegionItem extends AbstractJsonRepresentation
{
    /** The region's identifier: lowercase kebab-case, e.g. `europe`. */
    public string $id;

    /** The region's label in the language of the request, falling back to English, then to words derived from the id. */
    public string $label;

    /** @deprecated Use $id (#1018) */
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
     * @param string $id The identifier of the wider region.
     * @param string $label The label of the wider region, in the request's language.
     * @param string[] $locales The locales supported by the wider region.
     * @param string $api_path The API path for accessing the wider region data.
     * @param string[] $national_calendars Codes of the nations that have a calendar and declare this region.
     * @param string[] $roster Codes of every nation eligible to join this region.
     */
    public function __construct(
        string $id,
        string $label,
        array $locales,
        string $api_path,
        array $national_calendars = [],
        array $roster = []
    ) {
        $this->id                 = $id;
        $this->label              = $label;
        $this->name               = $id;
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
     * - id: The identifier of the wider region.
     * - label: The label of the wider region in the request's language.
     * - name: Deprecated alias of id.
     * - locales: An array of locales supported by the wider region.
     * - api_path: The API path for accessing the wider region data.
     * - national_calendars: Codes of the nations that have a calendar and declare this region.
     * - roster: Codes of every nation eligible to join this region.
     *
     * @return array{id:string,label:string,name:string,locales:string[],api_path:string,national_calendars:string[],roster:string[]} The associative array representation of the object.
     */
    public function jsonSerialize(): array
    {
        return [
            'id'                 => $this->id,
            'label'              => $this->label,
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
     * - id (string): The identifier of the wider region (`name` is read instead for old input).
     * - label (string, optional): The label; derived from the id when absent.
     * - locales (string[]): The locales supported by the wider region.
     * - api_path (string): The API path for accessing the wider region data.
     *
     * The array may also have the following optional keys:
     * - national_calendars (string[]): Codes of the nations that have a calendar and declare this region.
     * - roster (string[]): Codes of every nation eligible to join this region.
     *
     * @param array{id?:string,label?:string,name?:string,locales:string[],api_path:string,national_calendars?:string[],roster?:string[]} $data
     * @return static
     */
    protected static function fromArrayInternal(array $data): static
    {
        $id = $data['id'] ?? $data['name'] ?? '';
        return new static(
            $id,
            $data['label'] ?? WiderRegionId::humanize($id),
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
     * - id (string): The identifier of the wider region (`name` is read instead for old input).
     * - label (string, optional): The label; derived from the id when absent.
     * - locales (string[]): The locales supported by the wider region.
     * - api_path (string): The API path for accessing the wider region data.
     *
     * The object may also have the following optional properties:
     * - national_calendars (string[]): Codes of the nations that have a calendar and declare this region.
     * - roster (string[]): Codes of every nation eligible to join this region.
     *
     * @param \stdClass&object{id?:string,label?:string,name?:string,locales:string[],api_path:string,national_calendars?:string[],roster?:string[]} $data
     * @return static
     */
    protected static function fromObjectInternal(\stdClass $data): static
    {
        $id = $data->id ?? $data->name ?? '';
        return new static(
            $id,
            $data->label ?? WiderRegionId::humanize($id),
            $data->locales,
            $data->api_path,
            $data->national_calendars ?? [],
            $data->roster ?? []
        );
    }
}
