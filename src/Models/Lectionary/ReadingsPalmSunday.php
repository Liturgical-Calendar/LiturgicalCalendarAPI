<?php

namespace LiturgicalCalendar\Api\Models\Lectionary;

final class ReadingsPalmSunday extends ReadingsAbstract
{
    private const REQUIRED_PROPS = ['first_reading', 'second_reading', 'responsorial_psalm', 'alleluia_verse', 'gospel', 'palm_gospel'];

    public readonly string $second_reading;
    public readonly string $palm_gospel;

    private function __construct(string $first_reading, string $responsorial_psalm, string $second_reading, string $alleluia_verse, string $gospel, string $palm_gospel)
    {
        parent::__construct($first_reading, $responsorial_psalm, $alleluia_verse, $gospel);
        $this->second_reading = $second_reading;
        $this->palm_gospel    = $palm_gospel;
    }

    /**
     * @param \stdClass $data
     * @return static
     */
    protected static function fromObjectInternal(\stdClass $data): static
    {
        static::validateRequiredProps($data, static::REQUIRED_PROPS);

        return new static(
            $data->first_reading,
            $data->responsorial_psalm,
            $data->second_reading,
            $data->alleluia_verse,
            $data->gospel,
            $data->palm_gospel
        );
    }

    /**
     * Creates an instance of ReadingsPalmSunday from an associative array.
     *
     * The array should have the following keys:
     * - first_reading (string): The first reading for Palm Sunday
     * - second_reading (string): The second reading for Palm Sunday
     * - responsorial_psalm (string): The responsorial psalm for Palm Sunday
     * - alleluia_verse (string): The alleluia verse for Palm Sunday
     * - gospel (string): The gospel for Palm Sunday
     * - palm_gospel (string): The gospel for the procession of the palms
     *
     * @param array{first_reading:string,responsorial_psalm:string,second_reading:string,alleluia_verse:string,gospel:string,palm_gospel:string} $data
     * @return static
     */
    protected static function fromArrayInternal(array $data): static
    {
        if (reset($data) instanceof \stdClass) {
            throw new \InvalidArgumentException('Please use fromObject instead.');
        }

        static::validateRequiredKeys($data, static::REQUIRED_PROPS);

        return new static(
            $data['first_reading'],
            $data['responsorial_psalm'],
            $data['second_reading'],
            $data['alleluia_verse'],
            $data['gospel'],
            $data['palm_gospel']
        );
    }

    /**
     * {@inheritDoc}
     *
     * Returns an associative array containing the properties of this object,
     * with the following keys:
     * - palm_gospel (string): The gospel for the procession of the palms
     * - first_reading (string): The first reading for Palm Sunday
     * - second_reading (string): The second reading for Palm Sunday
     * - responsorial_psalm (string): The responsorial psalm for Palm Sunday
     * - alleluia_verse (string): The alleluia verse for Palm Sunday
     * - gospel (string): The gospel for Palm Sunday
     * @return array{palm_gospel:string,first_reading:string,second_reading:string,responsorial_psalm:string,alleluia_verse:string,gospel:string}
     */
    public function jsonSerialize(): array
    {
        return [
            'palm_gospel'        => $this->palm_gospel,
            'first_reading'      => $this->first_reading,
            'responsorial_psalm' => $this->responsorial_psalm,
            'second_reading'     => $this->second_reading,
            'alleluia_verse'     => $this->alleluia_verse,
            'gospel'             => $this->gospel
        ];
    }
}
