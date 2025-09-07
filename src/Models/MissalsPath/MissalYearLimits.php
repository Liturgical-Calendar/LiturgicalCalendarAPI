<?php

namespace LiturgicalCalendar\Api\Models\MissalsPath;

use LiturgicalCalendar\Api\Models\AbstractJsonRepresentation;

final class MissalYearLimits extends AbstractJsonRepresentation
{
    public readonly int $since_year;
    public readonly ?int $until_year;

    private function __construct(int $since_year, ?int $until_year)
    {
        $this->since_year = $since_year;
        $this->until_year = $until_year;
    }

    /**
     * {@inheritDoc}
     *
     * Returns an associative array of two elements, 'since_year' and
     * optionally 'until_year', representing the year limits for the
     * Missal.
     *
     * @return array{
     *     since_year: int,
     *     until_year?: int
     * }
     */
    public function jsonSerialize(): array
    {
        $yearLimits = [
            'since_year' => $this->since_year
        ];

        if (null !== $this->until_year) {
            $yearLimits['until_year'] = $this->until_year;
        }
        return $yearLimits;
    }

    /**
     * Creates an instance from an associative array.
     *
     * The array must have the following key:
     * - since_year (int): The year since when the Missal is applicable.
     *
     * Optional keys:
     * - until_year (int|null): The year until when the Missal is applicable.
     *
     * @param array{since_year:int,until_year?:int} $data The associative array containing the properties of the class.
     * @return static A new instance of the MissalYearLimits class.
     */
    protected static function fromArrayInternal(array $data): static
    {
        return new static(
            $data['since_year'],
            isset($data['until_year']) ? $data['until_year'] : null
        );
    }

    /**
     * Creates an instance from an object containing the required properties.
     *
     * The object must have the following property:
     * - since_year (int): The year since when the Missal is applicable.
     *
     * The object may have the following optional property:
     * - until_year (int|null): The year until when the Missal is applicable.
     *
     * @param \stdClass&object{since_year:int,until_year?:int} $object The object containing the properties of the class.
     * @return static A new instance of the MissalYearLimits class.
     */
    protected static function fromObjectInternal(\stdClass $object): static
    {
        return new static(
            $object->since_year,
            isset($object->until_year) ? $object->until_year : null
        );
    }
}
