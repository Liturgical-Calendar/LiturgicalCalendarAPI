<?php

namespace LiturgicalCalendar\Api\Models\RegionalData\NationalData;

use LiturgicalCalendar\Api\Enum\LitGrade;
use LiturgicalCalendar\Api\Models\RegionalData\LiturgicalEventData;

final class LitCalItemSetPropertyGrade extends LiturgicalEventData
{
    public readonly LitGrade $grade;

    private function __construct(string $event_key, LitGrade $grade)
    {
        parent::__construct($event_key);
        $this->grade = $grade;
    }

    protected static function fromObjectInternal(\stdClass $data): static
    {
        if (false === property_exists($data, 'event_key') || false === property_exists($data, 'grade')) {
            throw new \ValueError('`$liturgical_event->event_key` and `$liturgical_event->grade` properties are required for a `$metadata->action` of `setProperty` and when the property is `grade`');
        }
        return new static($data->event_key, LitGrade::from($data->grade));
    }

    protected static function fromArrayInternal(array $data): static
    {
        if (false === array_key_exists('event_key', $data) || false === array_key_exists('grade', $data)) {
            throw new \ValueError('`liturgical_event->event_key` and `liturgical_event->grade` parameters are required for a `metadata->action` of `setProperty` and when the property is `grade`');
        }
        return new static($data['event_key'], LitGrade::from($data['grade']));
    }
}
