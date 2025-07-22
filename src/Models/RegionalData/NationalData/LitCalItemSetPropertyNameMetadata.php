<?php

namespace LiturgicalCalendar\Api\Models\RegionalData\NationalData;

use LiturgicalCalendar\Api\Enum\CalEventAction;
use LiturgicalCalendar\Api\Models\RegionalData\LiturgicalEventMetadata;

final class LitCalItemSetPropertyNameMetadata extends LiturgicalEventMetadata
{
    public readonly CalEventAction $action;

    public readonly string $property;

    public function __construct(\stdClass $metadata)
    {
        if (false === property_exists($metadata, 'since_year') || false === property_exists($metadata, 'property') || $metadata->property !== 'name') {
            throw new \ValueError('`metadata.since_year` and `metadata.property` parameters are required, and `metadata.property` must have a value of `name`');
        }

        parent::__construct($metadata->since_year, $metadata->until_year ?? null);

        $this->action   = CalEventAction::SetProperty;
        $this->property = 'name';
    }
}
