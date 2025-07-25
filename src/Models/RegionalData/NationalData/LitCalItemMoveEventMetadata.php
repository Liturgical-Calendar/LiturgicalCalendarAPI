<?php

namespace LiturgicalCalendar\Api\Models\RegionalData\NationalData;

use LiturgicalCalendar\Api\Enum\CalEventAction;
use LiturgicalCalendar\Api\Models\LiturgicalEventMetadata;
use stdClass;

final class LitCalItemMoveEventMetadata extends LiturgicalEventMetadata
{
    public readonly CalEventAction $action;

    public readonly string $missal;

    public readonly string $reason;

    /**
     * Constructor for LitCalItemMoveEventMetadata.
     *
     * The authority for moving a liturgical event established in the General Roman Calendar,
     * is a language edition of the Roman Missal published by the local Bishops Conference
     * in coordination with the Dicastery for Divine Worship. Therefore, we include the
     * authority (the language edition of the Roman Missal) in the metadata, along with
     * the reason for the move (which can be simply the event_key of the liturgical event
     * that motivated the move).
     *
     * @param int $since_year The year from which the event is moved
     * @param ?int $until_year The year until which the event is moved
     * @param string $missal The name of the missal
     * @param string $reason The reason for the move
     *
     * @throws \ValueError If the required properties are not set in the metadata object
     */
    private function __construct(int $since_year, ?int $until_year, string $missal, string $reason)
    {
        parent::__construct($since_year, $until_year);
        $this->action = CalEventAction::MoveEvent;
        $this->missal = $missal;
        $this->reason = $reason;
    }

    /**
     * Creates an instance of LitCalItemMoveEventMetadata from a stdClass object.
     *
     * The stdClass object must have the following properties:
     * - since_year (int): the year from which the event is moved
     * - until_year (int|null): the year until which the event is moved
     * - missal (string): the name of the missal
     * - reason (string): the reason for the move
     *
     * @param \stdClass $data The stdClass object containing the properties of the class.
     * @return static The newly created instance.
     * @throws \ValueError if the required properties are not present in the stdClass object or if the properties have invalid types.
     */
    protected static function fromObjectInternal(\stdClass $data): static
    {
        if (false === property_exists($data, 'since_year') || false === property_exists($data, 'missal') || false === property_exists($data, 'reason')) {
            throw new \ValueError('since_year, missal, and reason parameters are required');
        }

        return new static(
            $data->since_year,
            $data->until_year ?? null,
            $data->missal,
            $data->reason
        );
    }

    /**
     * Creates an instance of LitCalItemMoveEventMetadata from an associative array.
     *
     * The array must have the following keys:
     * - since_year (int): The year since when the metadata is applied.
     * - missal (string): The identifier for the language edition of the Roman Missal.
     * - reason (string): The reason for moving the liturgical event.
     *
     * Optional keys:
     * - until_year (int|null): The year until when the metadata is applied.
     *
     * @param array{since_year:int,until_year:int|null,missal:string,reason: string} $data The associative array containing the properties of the class.
     * @return static A new instance of the class.
     * @throws \ValueError If the required keys are not present in the array.
     */
    protected static function fromArrayInternal(array $data): static
    {
        if (false === array_key_exists('since_year', $data) || false === array_key_exists('missal', $data) || false === array_key_exists('reason', $data)) {
            throw new \ValueError('since_year, missal, and reason parameters are required');
        }

        return new static(
            $data['since_year'],
            $data['until_year'] ?? null,
            $data['missal'],
            $data['reason']
        );
    }
}
