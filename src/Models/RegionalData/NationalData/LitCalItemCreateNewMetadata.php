<?php

namespace LiturgicalCalendar\Api\Models\RegionalData\NationalData;

use LiturgicalCalendar\Api\Enum\CalEventAction;
use LiturgicalCalendar\Api\Models\RegionalData\LiturgicalEventMetadata;
use stdClass;

final class LitCalItemCreateNewMetadata extends LiturgicalEventMetadata
{
    public readonly CalEventAction $action;

    private function __construct(int $since_year, ?int $until_year = null)
    {
        parent::__construct($since_year, $until_year ?? null);
        $this->action = CalEventAction::CreateNew;
    }

    /**
     * Creates an instance from a StdClass object.
     *
     * @param stdClass $data The StdClass object to create an instance from.
     * It must have the following properties:
     * - since_year (int): The year since when the liturgical event was added.
     * - until_year (int|null): The year until when the liturgical event was added.
     *
     * @return static A new instance created from the given data.
     */
    protected static function fromObjectInternal(stdClass $data): static
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
