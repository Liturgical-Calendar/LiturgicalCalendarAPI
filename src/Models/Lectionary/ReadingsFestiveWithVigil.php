<?php

namespace LiturgicalCalendar\Api\Models\Lectionary;

/**
 * @phpstan-import-type ReadingsFestiveWithVigilArray from ReadingsMap
 */
final class ReadingsFestiveWithVigil implements \JsonSerializable
{
    public readonly ReadingsFestive $vigil;
    public readonly ReadingsFestive $day;

    private function __construct(ReadingsFestive $vigil, ReadingsFestive $day)
    {
        $this->vigil = $vigil;
        $this->day   = $day;
    }

    /**
     * @phpstan-param ReadingsFestiveWithVigilArray $readings
     */
    public static function fromArray(array $readings): self
    {
        return new self(
            ReadingsFestive::fromArray($readings['vigil']),
            ReadingsFestive::fromArray($readings['day'])
        );
    }

    public function jsonSerialize(): mixed
    {
        return $this->day->jsonSerialize();
    }
}
