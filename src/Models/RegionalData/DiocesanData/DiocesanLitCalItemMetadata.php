<?php

namespace LiturgicalCalendar\Api\Models\RegionalData\DiocesanData;

use LiturgicalCalendar\Api\Models\RegionalData\LiturgicalEventMetadata;

final class DiocesanLitCalItemMetadata extends LiturgicalEventMetadata
{
    public function __construct(int $since_year, ?int $until_year = null)
    {
        parent::__construct($since_year, $until_year);
    }
}
