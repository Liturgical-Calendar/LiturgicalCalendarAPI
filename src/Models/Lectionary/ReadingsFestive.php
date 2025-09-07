<?php

namespace LiturgicalCalendar\Api\Models\Lectionary;

final class ReadingsFestive extends ReadingsAbstract
{
    private const REQUIRED_PROPS = ['first_reading', 'second_reading', 'responsorial_psalm', 'gospel_acclamation', 'gospel'];

    public readonly string $second_reading;

    protected function __construct(string $first_reading, string $responsorial_psalm, string $second_reading, string $gospel_acclamation, string $gospel)
    {
        parent::__construct($first_reading, $responsorial_psalm, $gospel_acclamation, $gospel);
        $this->second_reading = $second_reading;
    }

    /**
     * @param \stdClass&object{first_reading:string,second_reading:string,responsorial_psalm:string,gospel_acclamation:string,gospel:string} $data
     * @return static
     */
    protected static function fromObjectInternal(\stdClass $data): static
    {
        static::validateRequiredProps($data, static::REQUIRED_PROPS);

        return new static(
            $data->first_reading,
            $data->responsorial_psalm,
            $data->second_reading,
            $data->gospel_acclamation,
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
     * - gospel_acclamation (string): The alleluia verse for a festive day
     * - gospel (string): The gospel for a festive day
     *
     * @param array{first_reading:string,second_reading:string,responsorial_psalm:string,gospel_acclamation:string,gospel:string} $data
     * @return static
     */
    protected static function fromArrayInternal(array $data): static
    {
        static::validateRequiredKeys($data, static::REQUIRED_PROPS);

        if (reset($data) instanceof \stdClass) {
            throw new \InvalidArgumentException('Please use fromObject instead.');
        }
        return new static(
            $data['first_reading'],
            $data['responsorial_psalm'],
            $data['second_reading'],
            $data['gospel_acclamation'],
            $data['gospel']
        );
    }

    /**
     * Reduces the current festive readings to ferial readings.
     *
     * This method creates a new `ReadingsFerial` object using the properties
     * of the current festive readings, excluding the second reading,
     * which is however appended as a second option for the first reading.
     *
     * This is useful for Feasts of the Lord that fall on weekdays.
     *
     * @return ReadingsFerial An instance containing ferial readings.
     */
    public function reduceToFerial(): ReadingsFerial
    {
        return new ReadingsFerial(
            $this->first_reading . '|' . $this->second_reading,
            $this->responsorial_psalm,
            $this->gospel_acclamation,
            $this->gospel
        );
    }

    /**
     * {@inheritDoc}
     *
     * Returns an associative array containing the properties of this object,
     * with the following keys:
     * - first_reading (string): The first reading for a festive day
     * - second_reading (string): The second reading for a festive day
     * - responsorial_psalm (string): The responsorial psalm for a festive day
     * - gospel_acclamation (string): The alleluia verse for a festive day
     * - gospel (string): The gospel for a festive day
     * @return array{first_reading:string,second_reading:string,responsorial_psalm:string,gospel_acclamation:string,gospel:string}
     */
    public function jsonSerialize(): array
    {
        return [
            'first_reading'      => $this->first_reading,
            'responsorial_psalm' => $this->responsorial_psalm,
            'second_reading'     => $this->second_reading,
            'gospel_acclamation' => $this->gospel_acclamation,
            'gospel'             => $this->gospel
        ];
    }
}
