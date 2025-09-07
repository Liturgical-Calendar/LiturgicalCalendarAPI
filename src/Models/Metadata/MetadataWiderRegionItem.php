<?php

namespace LiturgicalCalendar\Api\Models\Metadata;

use LiturgicalCalendar\Api\Models\AbstractJsonRepresentation;

final class MetadataWiderRegionItem extends AbstractJsonRepresentation
{
    public string $name;

    /** @var string[] */
    public array $locales;

    public string $api_path;

    /**
     * Initializes a MetadataWiderRegionItem object.
     *
     * @param string $name The name of the wider region.
     * @param string[] $locales The locales supported by the wider region.
     * @param string $api_path The API path for accessing the wider region data.
     */
    public function __construct(
        string $name,
        array $locales,
        string $api_path
    ) {
        $this->name     = $name;
        $this->locales  = $locales;
        $this->api_path = $api_path;
    }

    /**
     * Converts the MetadataWiderRegionItem object to an associative array
     * for JSON serialization.
     *
     * The array contains the following keys:
     * - name: The name of the wider region.
     * - locales: An array of locales supported by the wider region.
     * - api_path: The API path for accessing the wider region data.
     *
     * @return array{name:string,locales:string[],api_path:string} The associative array representation of the object.
     */
    public function jsonSerialize(): array
    {
        return [
            'name'     => $this->name,
            'locales'  => $this->locales,
            'api_path' => $this->api_path
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
     * @param array{name:string,locales:string[],api_path:string} $data
     * @return static
     */
    protected static function fromArrayInternal(array $data): static
    {
        return new static(
            $data['name'],
            $data['locales'],
            $data['api_path']
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
     * @param \stdClass&object{name:string,locales:string[],api_path:string} $data
     * @return static
     */
    protected static function fromObjectInternal(\stdClass $data): static
    {
        return new static(
            $data->name,
            $data->locales,
            $data->api_path
        );
    }
}
