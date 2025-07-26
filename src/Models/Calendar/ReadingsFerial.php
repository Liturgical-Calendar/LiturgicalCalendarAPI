<?php

namespace LiturgicalCalendar\Api\Models\Calendar;

final class ReadingsFerial extends ReadingsAbstract
{
    private const REQUIRED_PROPS = ['first_reading', 'responsorial_psalm', 'alleluia_verse', 'gospel'];

    /**
     * @param \stdClass $data
     * @return static
     */
    protected static function fromObjectInternal(\stdClass $data): static
    {
        static::validateRequiredProps($data, self::REQUIRED_PROPS);

        return new static(
            $data->first_reading,
            $data->responsorial_psalm,
            $data->alleluia_verse,
            $data->gospel
        );
    }

    /**
     * Creates an instance of this class from an associative array.
     *
     * The array should have the following keys:
     * - first_reading (string): The first reading for a ferial day
     * - responsorial_psalm (string): The responsorial psalm for a ferial day
     * - alleluia_verse (string): The alleluia verse for a ferial day
     * - gospel (string): The gospel for a ferial day
     *
     * @param array{first_reading:string,responsorial_psalm:string,alleluia_verse:string,gospel:string} $data
     * @return static
     */
    protected static function fromArrayInternal(array $data): static
    {
        if (reset($data) instanceof \stdClass) {
            throw new \InvalidArgumentException('Please use fromObject instead.');
        }

        static::validateRequiredKeys($data, self::REQUIRED_PROPS);

        return new static(
            $data['first_reading'],
            $data['responsorial_psalm'],
            $data['alleluia_verse'],
            $data['gospel']
        );
    }
}
