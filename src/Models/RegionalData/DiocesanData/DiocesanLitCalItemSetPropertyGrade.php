<?php

namespace LiturgicalCalendar\Api\Models\RegionalData\DiocesanData;

use LiturgicalCalendar\Api\Enum\LitGrade;
use LiturgicalCalendar\Api\Models\LiturgicalEventData;

/**
 * A diocesan `setProperty:grade` item: changes the grade of an event that already exists in the
 * calendar, rather than declaring a new one.
 */
final class DiocesanLitCalItemSetPropertyGrade extends LiturgicalEventData
{
    public readonly LitGrade $grade;

    private function __construct(string $event_key, LitGrade $grade)
    {
        if ($grade === LitGrade::HIGHER_SOLEMNITY || $grade === LitGrade::FEAST_LORD) {
            throw new \ValueError('Diocesan events cannot have grade HIGHER_SOLEMNITY or FEAST_LORD');
        }
        parent::__construct($event_key);
        $this->grade = $grade;
    }

    /**
     * @param \stdClass&object{event_key:string,grade:int} $data
     * @return static
     * @throws \ValueError if `event_key` or `grade` is missing.
     */
    protected static function fromObjectInternal(\stdClass $data): static
    {
        if (false === property_exists($data, 'event_key') || false === property_exists($data, 'grade')) {
            throw new \ValueError('`liturgical_event->event_key` and `liturgical_event->grade` are required for a `metadata->action` of `setProperty` when the property is `grade`');
        }
        return new static($data->event_key, LitGrade::from($data->grade));
    }

    /**
     * @param array{event_key:string,grade:int} $data
     * @return static
     * @throws \ValueError if `event_key` or `grade` is missing.
     */
    protected static function fromArrayInternal(array $data): static
    {
        if (false === array_key_exists('event_key', $data) || false === array_key_exists('grade', $data)) {
            throw new \ValueError('`liturgical_event->event_key` and `liturgical_event->grade` are required for a `metadata->action` of `setProperty` when the property is `grade`');
        }
        return new static($data['event_key'], LitGrade::from($data['grade']));
    }
}
