<?php

namespace LiturgicalCalendar\Api\Models\RegionalData\NationalData;

use LiturgicalCalendar\Api\Enum\CalEventAction;
use LiturgicalCalendar\Api\Models\LiturgicalEventMetadata;

final class LitCalItemSetPropertyNameMetadata extends LiturgicalEventMetadata
{
    public readonly CalEventAction $action;

    public readonly string $property;

    private function __construct(int $since_year, ?int $until_year = null)
    {
        parent::__construct($since_year, $until_year ?? null);

        $this->action   = CalEventAction::SetProperty;
        $this->property = 'name';
    }

    /**
     * Create a new instance of the class from an object containing the required properties.
     *
     * @param \stdClass&object{since_year:int,until_year?:int} $data The object containing the required properties.
     *
     * @return static A new instance of the class.
     *
     * @throws \ValueError If the object does not contain the required properties.
     */
    protected static function fromObjectInternal(\stdClass $data): static
    {
        if (false === property_exists($data, 'since_year') || false === property_exists($data, 'property') || $data->property !== 'name') {
            throw new \ValueError('`since_year` and `property` parameters are required, and `property` must have a value of `name`');
        }

        return new static(
            $data->since_year,
            $data->until_year ?? null
        );
    }

    /**
     * Creates an instance of the class from an associative array.
     *
     * The array must have the following keys:
     * - since_year (int): The year since when the metadata is applied.
     * - property (string): The property to be set. Must have a value of 'name'.
     *
     * @param array{since_year:int,until_year?:int} $data
     *     The associative array containing the properties of the class.
     *
     * @return static
     *     A new instance of the class.
     *
     * @throws \ValueError
     *     If the required keys are not present in the array or have invalid values.
     */
    protected static function fromArrayInternal(array $data): static
    {
        if (false === isset($data['since_year']) || false === isset($data['property']) || $data['property'] !== 'name') {
            throw new \ValueError('`since_year` and `property` parameters are required, and `property` must have a value of `name`');
        }

        return new static(
            $data['since_year'],
            isset($data['until_year']) && is_int($data['until_year']) ? $data['until_year'] : null
        );
    }
}
