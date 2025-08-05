<?php

namespace LiturgicalCalendar\Api\Models\MissalsPath;

use LiturgicalCalendar\Api\Models\AbstractJsonRepresentation;

final class MissalMetadata extends AbstractJsonRepresentation
{
    public readonly string $missal_id;
    public readonly string $name;
    public readonly string $region;
    /** @var string[] */
    public readonly array $locales;
    public readonly ?string $api_path;
    public readonly int $year_published;

    /**
     * Creates an instance of Missal from the given parameters.
     *
     * @param string $missal_id The identifier of the missal.
     * @param string $name The name of the missal.
     * @param string $region The region of the missal.
     * @param string[] $locales The locales supported by the missal.
     * @param string|null $api_path The API path for the missal.
     * @param int $year_published The year the missal was published.
     */
    private function __construct(
        string $missal_id,
        string $name,
        string $region,
        array $locales,
        ?string $api_path,
        int $year_published
    ) {
        $this->missal_id      = $missal_id;
        $this->name           = $name;
        $this->region         = $region;
        $this->locales        = $locales;
        $this->api_path       = $api_path;
        $this->year_published = $year_published;
    }

    /**
     * Creates an instance of Missal from a stdClass object.
     *
     * The object must have the following properties:
     * - missal_id (string): The identifier of the missal.
     * - name (string): The name of the missal.
     * - region (string): The region of the missal.
     * - locales (array<string>): The locales supported by the missal.
     * - api_path (string): The API path for the missal.
     * - year_published (int): The year the missal was published.
     *
     * @param \stdClass $data The object containing the properties of the Missal.
     * @return static A new instance of Missal initialized with the provided data.
     */
    protected static function fromObjectInternal(\stdClass $data): static
    {
        return new static(
            $data->missal_id,
            $data->name,
            $data->region,
            $data->locales,
            $data->api_path,
            $data->year_published
        );
    }

    /**
     * Creates an instance of Missal from an associative array.
     *
     * The array must have the following keys:
     * - missal_id (string): The identifier of the missal.
     * - name (string): The name of the missal.
     * - region (string): The region of the missal.
     * - locales (array<string>): The locales supported by the missal.
     * - api_path (string): The API path for the missal.
     * - year_published (int): The year the missal was published.
     *
     * @param array{missal_id:string,name:string,region:string,locales:array<string>,api_path:?string,year_published:int} $data
     * @return static
     */
    protected static function fromArrayInternal(array $data): static
    {
        return new static(
            $data['missal_id'],
            $data['name'],
            $data['region'],
            $data['locales'],
            $data['api_path'],
            $data['year_published']
        );
    }

    /**
     * Returns an associative array of properties of the Missal object.
     *
     * The returned array will have the following keys:
     * - missal_id (string): The identifier of the missal.
     * - name (string): The name of the missal.
     * - region (string): The region of the missal.
     * - locales (array<string>): The locales supported by the missal.
     * - api_path (string): The API path for the missal.
     * - year_published (int): The year the missal was published.
     *
     * @return array{missal_id:string,name:string,region:string,locales:string[],api_path:string,year_published:int}
     */
    public function jsonSerialize(): array
    {
        return [
            'missal_id'      => $this->missal_id,
            'name'           => $this->name,
            'region'         => $this->region,
            'locales'        => $this->locales,
            'api_path'       => $this->api_path,
            'year_published' => $this->year_published
        ];
    }
}
