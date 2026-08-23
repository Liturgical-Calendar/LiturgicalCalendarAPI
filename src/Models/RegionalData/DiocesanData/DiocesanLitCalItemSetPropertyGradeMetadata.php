<?php

namespace LiturgicalCalendar\Api\Models\RegionalData\DiocesanData;

use LiturgicalCalendar\Api\Enum\CalEventAction;
use LiturgicalCalendar\Api\Models\LiturgicalEventMetadata;

final class DiocesanLitCalItemSetPropertyGradeMetadata extends LiturgicalEventMetadata
{
    public readonly CalEventAction $action;

    public readonly string $property;

    private function __construct(int $since_year, ?int $until_year = null)
    {
        parent::__construct($since_year, $until_year);
        $this->action   = CalEventAction::SetProperty;
        $this->property = 'grade';
    }

    /**
     * @param \stdClass&object{since_year:int,until_year?:int,property:string} $data
     * @return static
     */
    protected static function fromObjectInternal(\stdClass $data): static
    {
        if (false === property_exists($data, 'since_year') || false === property_exists($data, 'property') || $data->property !== 'grade') {
            throw new \ValueError('`since_year` and `property` are required for an `action` of `setProperty`, and `property` must have a value of `grade`');
        }
        return new static(
            $data->since_year,
            isset($data->until_year) && is_int($data->until_year) ? $data->until_year : null
        );
    }

    /**
     * @param array{since_year:int,until_year?:int,property:string} $data
     * @return static
     */
    protected static function fromArrayInternal(array $data): static
    {
        if (false === isset($data['since_year']) || false === isset($data['property']) || $data['property'] !== 'grade') {
            throw new \ValueError('`since_year` and `property` are required for an `action` of `setProperty`, and `property` must have a value of `grade`');
        }
        return new static(
            $data['since_year'],
            isset($data['until_year']) && is_int($data['until_year']) ? $data['until_year'] : null
        );
    }
}
