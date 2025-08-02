<?php

namespace LiturgicalCalendar\Api\Models\Lectionary;

/**
 * Lectionary readings for liturgical events that have both a day and an evening mass.
 *
 * Example: Easter day
 *
 * @phpstan-import-type ReadingsFestiveArray from ReadingsMap
 * @phpstan-import-type ReadingsWithEveningArray from ReadingsMap
 */
final class ReadingsWithEvening implements \JsonSerializable
{
    public readonly ReadingsFestive $day;
    public readonly ReadingsFestive $evening;

    private function __construct(ReadingsFestive $day, ReadingsFestive $evening)
    {
        $this->day     = $day;
        $this->evening = $evening;
    }

    /**
     * @phpstan-param ReadingsWithEveningArray $readings
     */
    public static function fromArray(array $readings): self
    {
        return new self(
            ReadingsFestive::fromArray($readings['day']),
            ReadingsFestive::fromArray($readings['evening'])
        );
    }

    /**
     * {@inheritDoc}
     *
     * Returns an associative array containing the properties of this object,
     * with the following keys:
     * - day (ReadingsFestive): The readings for the day mass
     * - evening (ReadingsFestive): The readings for the evening mass
     * @return array{day:ReadingsFestiveArray,evening:ReadingsFestiveArray}
     */
    public function jsonSerialize(): array
    {
        return [
            'day'     => $this->day->jsonSerialize(),
            'evening' => $this->evening->jsonSerialize()
        ];
    }
}
