<?php

namespace LiturgicalCalendar\Api\Models\Lectionary;

/**
 * @phpstan-import-type ReadingsChristmasArray from ReadingsMap
 * @phpstan-import-type ReadingsFestiveArray from ReadingsMap
 */
final class ReadingsChristmas implements \JsonSerializable
{
    public readonly ReadingsFestive $vigil;
    public readonly ReadingsFestive $night;
    public readonly ReadingsFestive $dawn;
    public readonly ReadingsFestive $day;

    private function __construct(ReadingsFestive $vigil, ReadingsFestive $night, ReadingsFestive $dawn, ReadingsFestive $day)
    {
        $this->vigil = $vigil;
        $this->night = $night;
        $this->dawn  = $dawn;
        $this->day   = $day;
    }

    /**
     * @param ReadingsChristmasArray $readings
     */
    public static function fromArray(array $readings): self
    {
        return new self(
            ReadingsFestive::fromArray($readings['vigil']),
            ReadingsFestive::fromArray($readings['night']),
            ReadingsFestive::fromArray($readings['dawn']),
            ReadingsFestive::fromArray($readings['day'])
        );
    }

    /**
     * {@inheritDoc}
     *
     * Returns an associative array containing the properties of this object,
     * with the following keys:
     * - night (ReadingsFestive): The readings for the vigil of Christmas
     * - dawn (ReadingsFestive): The readings for the dawn mass of Christmas
     * - day (ReadingsFestive): The readings for the day mass of Christmas
     * @return array{night:ReadingsFestiveArray,dawn:ReadingsFestiveArray,day:ReadingsFestiveArray}
     */
    public function jsonSerialize(): array
    {
        return [
            'night' => $this->night->jsonSerialize(),
            'dawn'  => $this->dawn->jsonSerialize(),
            'day'   => $this->day->jsonSerialize()
        ];
    }
}
