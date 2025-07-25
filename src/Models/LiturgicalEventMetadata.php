<?php

namespace LiturgicalCalendar\Api\Models;

use LiturgicalCalendar\Api\Models\AbstractJsonSrcData;

abstract class LiturgicalEventMetadata extends AbstractJsonSrcData
{
    public readonly ?int $since_year;

    public readonly ?int $until_year;

    protected function __construct(int $since_year, ?int $until_year = null)
    {
        if ($since_year < 1800) {
            throw new \ValueError('$since_year parameter must represent a year from the 19th century or later');
        }

        if ($until_year !== null && $until_year <= $since_year) {
            throw new \ValueError('$until_year parameter must be greater than $since_year parameter');
        }

        $this->since_year = $since_year;
        $this->until_year = $until_year;
    }

    abstract protected static function fromArrayInternal(array $data): static;

    abstract protected static function fromObjectInternal(\stdClass $data): static;
}
