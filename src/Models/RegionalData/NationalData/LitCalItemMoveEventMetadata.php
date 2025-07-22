<?php

namespace LiturgicalCalendar\Api\Models\RegionalData\NationalData;

use LiturgicalCalendar\Api\Enum\CalEventAction;
use LiturgicalCalendar\Api\Models\RegionalData\LiturgicalEventMetadata;

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
     * @param \stdClass $metadata The metadata object from the JSON data
     *
     * @throws \ValueError If the required properties are not set in the metadata object
     */
    public function __construct(\stdClass $metadata)
    {
        if (false === property_exists($metadata, 'since_year') || false === property_exists($metadata, 'missal') || false === property_exists($metadata, 'reason')) {
            throw new \ValueError('since_year, missal, and reason parameters are required');
        }

        parent::__construct($metadata->since_year, $metadata->until_year = null);
        $this->action = CalEventAction::MoveEvent;
        $this->missal = $metadata->missal;
        $this->reason = $metadata->reason;
    }
}
