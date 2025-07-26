<?php

namespace LiturgicalCalendar\Api\Models\Calendar;

final class ReadingsFestive extends ReadingsAbstract
{
    private const REQUIRED_PROPS = ['first_reading', 'second_reading', 'responsorial_psalm', 'alleluia_verse', 'gospel'];

    public readonly string $second_reading;

    protected function __construct(string $first_reading, string $second_reading, string $responsorial_psalm, string $alleluia_verse, string $gospel)
    {
        parent::__construct($first_reading, $responsorial_psalm, $alleluia_verse, $gospel);
        $this->second_reading = $second_reading;
    }

    /**
     * @param \stdClass $data
     * @return static
     */
    protected static function fromObjectInternal(\stdClass $data): static
    {
        static::validateRequiredProps($data, self::REQUIRED_PROPS);

        return new static(
            $data->first_reading,
            $data->second_reading,
            $data->responsorial_psalm,
            $data->alleluia_verse,
            $data->gospel
        );
    }

    /**
     * Creates an instance of ReadingsFestive from an associative array.
     *
     * The array should have the following keys:
     * - first_reading (string): The first reading for a festive day
     * - second_reading (string): The second reading for a festive day
     * - responsorial_psalm (string): The responsorial psalm for a festive day
     * - alleluia_verse (string): The alleluia verse for a festive day
     * - gospel (string): The gospel for a festive day
     *
     * @param array{first_reading:string,second_reading:string,responsorial_psalm:string,alleluia_verse:string,gospel:string} $data
     * @return static
     */
    protected static function fromArrayInternal(array $data): static
    {
        static::validateRequiredKeys($data, self::REQUIRED_PROPS);

        if (reset($data) instanceof \stdClass) {
            throw new \InvalidArgumentException('Please use fromObject instead.');
        }
        return new static(
            $data['first_reading'],
            $data['second_reading'],
            $data['responsorial_psalm'],
            $data['alleluia_verse'],
            $data['gospel']
        );
    }
}
