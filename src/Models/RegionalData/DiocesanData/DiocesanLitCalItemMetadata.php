<?php

namespace LiturgicalCalendar\Api\Models\RegionalData\DiocesanData;

use LiturgicalCalendar\Api\Models\RegionalData\LiturgicalEventMetadata;

final class DiocesanLitCalItemMetadata extends LiturgicalEventMetadata
{
    private function __construct(int $since_year, ?int $until_year = null)
    {
        parent::__construct($since_year, $until_year);
    }

    /**
     * Creates an instance from an object containing the required properties.
     *
     * The object must have the following properties:
     * - since_year (int): The year since when the liturgical event was added.
     *
     * The object may have the following optional properties:
     * - until_year (int|null): The year until when the liturgical event was added.
     *
     * @param \stdClass $data The object containing the properties of the class.
     * @return static A new instance of the class.
     */
    protected static function fromObjectInternal(\stdClass $data): static
    {
        return new static(
            $data->since_year,
            $data->until_year ?? null
        );
    }

    /**
     * Creates an instance from an associative array.
     *
     * The array must have the following key:
     * - since_year (int): The year since when the liturgical event was added.
     *
     * Optional keys:
     * - until_year (int|null): The year until when the liturgical event was added.
     *
     * @param array{since_year:int,until_year?:int} $data The associative array containing the properties of the class.
     * @return static A new instance of the class.
     */
    protected static function fromArrayInternal(array $data): static
    {
        return new static(
            $data['since_year'],
            $data['until_year'] ?? null
        );
    }
}
