<?php

namespace LiturgicalCalendar\Api\Models\RegionalData\NationalData;

use LiturgicalCalendar\Api\Enum\CalEventAction;
use LiturgicalCalendar\Api\Models\RegionalData\LiturgicalEventMetadata;

final class LitCalItemSetPropertyGradeMetadata extends LiturgicalEventMetadata
{
    public readonly CalEventAction $action;

    public readonly string $property;

    public readonly ?string $url;

    public function __construct(\stdClass $metadata)
    {
        if (false === property_exists($metadata, 'since_year')) {
            throw new \ValueError('`$metadata->since_year` property is required for a `$metadata->action` of `setProperty` and when the property is `grade`: ' . json_encode($metadata));
        }
        parent::__construct($metadata->since_year, $metadata->until_year ?? null);
        $this->action   = CalEventAction::SetProperty;
        $this->property = 'grade';
        if (property_exists($metadata, 'url')) {
            $url       = filter_var($metadata->url, FILTER_SANITIZE_URL);
            $this->url = htmlspecialchars($url, ENT_QUOTES);
        } else {
            $this->url = null;
        }
    }
}
