<?php

namespace LiturgicalCalendar\Api\Models\RegionalData;

use LiturgicalCalendar\Api\Models\AbstractJsonSrcData;

abstract class LiturgicalEventMetadata extends AbstractJsonSrcData
{
    public readonly ?int $since_year;

    public readonly ?int $until_year;

    public function __construct(int $since_year, ?int $until_year = null)
    {

        if (false === is_int($since_year)) {
            throw new \ValueError('$since_year parameter must be an integer');
        }

        if ($since_year < 1800) {
            throw new \ValueError('$since_year parameter must represent a year from the 19th century or later');
        }

        if ($until_year !== null) {
            if (false === is_int($until_year)) {
                throw new \ValueError('$until_year parameter must be an integer');
            }
            if ($until_year <= $since_year) {
                throw new \ValueError('$until_year parameter must be greater than $since_year parameter');
            }
        }

        $this->since_year = $since_year;
        $this->until_year = $until_year;
    }

    protected static function fromArrayInternal(array $data): static
    {
        if (false === array_key_exists('since_year', $data)) {
            throw new \ValueError('`since_year` parameter is required');
        }
        return new static(
            $data['since_year'],
            $data['until_year'] ?? null
        );
    }

    protected static function fromObjectInternal(\stdClass $data): static
    {
        if (false === property_exists($data, 'since_year')) {
            throw new \ValueError('`since_year` parameter is required');
        }
        return new static(
            $data->since_year,
            $data->until_year ?? null
        );
    }
}
