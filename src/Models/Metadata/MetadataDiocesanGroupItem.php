<?php

namespace LiturgicalCalendar\Api\Models\Metadata;

use LiturgicalCalendar\Api\Models\AbstractJsonRepresentation;

final class MetadataDiocesanGroupItem extends AbstractJsonRepresentation
{
    public string $group_name;

    /** @var string[] */
    public array $dioceses;

    /**
     * Initializes a MetadataDiocesanGroupItem object.
     *
     * @param string $group_name The unique identifier for the group of dioceses.
     * @param string[] $dioceses The dioceses that belong to the group.
     */
    public function __construct(
        string $group_name,
        array $dioceses
    ) {
        $this->group_name = $group_name;
        $this->dioceses   = $dioceses;
    }

    /**
     * {@inheritDoc}
     *
     * Converts the object to an array that can be json encoded,
     * containing the following keys:
     * - group_name: The unique identifier for the group of dioceses.
     * - dioceses: The dioceses that belong to the group.
     *
     * @return array{group_name:string,dioceses:array<string>} The associative array containing the metadata about a group of dioceses.
     */
    public function jsonSerialize(): array
    {
        return [
            'group_name' => $this->group_name,
            'dioceses'   => $this->dioceses
        ];
    }

    /**
     * Creates an instance of MetadataDiocesanGroupItem from an associative array.
     *
     * The array should have the following keys:
     * - group_name (string): The unique identifier for the group of dioceses.
     * - dioceses (array<string>): The dioceses that belong to the group.
     *
     * @param array{
     *      group_name: string,
     *      dioceses: array<string>
     * } $data
     * @return static
     */
    protected static function fromArrayInternal(array $data): static
    {
        return new static(
            $data['group_name'],
            $data['dioceses']
        );
    }

    /**
     * Creates an instance of MetadataDiocesanGroupItem from a stdClass object.
     *
     * The object should have the following properties:
     * - group_name (string): The unique identifier for the group of dioceses.
     * - dioceses (array<string>): The dioceses that belong to the group.
     *
     * @param \stdClass $data
     * @return static
     */
    protected static function fromObjectInternal(\stdClass $data): static
    {
        return new static(
            $data->group_name,
            $data->dioceses
        );
    }
}
