<?php

namespace LiturgicalCalendar\Api\Models\Lectionary;

/**
 * @phpstan-import-type ReadingsFerialArray from ReadingsMap
 */
final class ReadingsSeasonal implements \JsonSerializable
{
    public ReadingsFerial $easter_season;
    public ReadingsFerial $outside_easter_season;

    public function __construct(ReadingsFerial $easter_season, ReadingsFerial $outside_easter_season)
    {
        $this->easter_season         = $easter_season;
        $this->outside_easter_season = $outside_easter_season;
    }

    /**
     * Creates an instance of ReadingsSeasonal from an associative array.
     *
     * The array should have the following keys:
     * - easter_season (array): The readings during the Easter season
     * - outside_easter_season (array): The readings outside the Easter season
     *
     * @param array{easter_season:ReadingsFerialArray,outside_easter_season:ReadingsFerialArray} $readings
     * @return self
     */
    public static function fromArray(array $readings): self
    {
        return new self(
            ReadingsFerial::fromArray($readings['easter_season']),
            ReadingsFerial::fromArray($readings['outside_easter_season'])
        );
    }

    /**
     * {@inheritDoc}
     *
     * Returns an associative array containing the properties of this object,
     * with the following keys:
     * - easter_season (ReadingsFerial): The readings for the Easter season
     * - outside_easter_season (ReadingsFerial): The readings for outside the Easter season
     * @return array{easter_season:ReadingsFerialArray,outside_easter_season:ReadingsFerialArray}
     */
    public function jsonSerialize(): array
    {
        return [
            'easter_season'         => $this->easter_season->jsonSerialize(),
            'outside_easter_season' => $this->outside_easter_season->jsonSerialize()
        ];
    }
}
