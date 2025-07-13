<?php

namespace LiturgicalCalendar\Api\Models;

class MetadataDiocesanGroupItem implements \JsonSerializable
{
    public readonly string $group_name;
    /** @var string[] */
    public readonly array $dioceses;

    public function __construct(
        string $group_name,
        array $dioceses
    ) {
        $this->group_name = $group_name;
        $this->dioceses   = $dioceses;
    }

    /**
     * @inheritDoc
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

    public static function fromArray(array $data): self
    {
        return new self(
            $data['group_name'],
            $data['dioceses']
        );
    }

    public static function fromObject(\stdClass $data): self
    {
        return new self(
            $data->group_name,
            $data->dioceses
        );
    }
}
