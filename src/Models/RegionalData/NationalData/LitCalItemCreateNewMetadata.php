<?php

namespace LiturgicalCalendar\Api\Models\RegionalData\NationalData;

use LiturgicalCalendar\Api\Enum\CalEventAction;
use LiturgicalCalendar\Api\Models\RegionalData\LiturgicalEventMetadata;

final class LitCalItemCreateNewMetadata extends LiturgicalEventMetadata
{
    public readonly CalEventAction $action;

    public function __construct(int $since_year, ?int $until_year = null)
    {
        parent::__construct($since_year, $until_year ?? null);
        $this->action = CalEventAction::CreateNew;
    }
}
