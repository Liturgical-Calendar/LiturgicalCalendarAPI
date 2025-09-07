<?php

namespace LiturgicalCalendar\Api\Models\Lectionary;

final class ReadingsFerial extends ReadingsAbstract
{
    private const REQUIRED_PROPS = ['first_reading', 'responsorial_psalm', 'gospel_acclamation', 'gospel'];

    /**
     * @param \stdClass&object{first_reading:string,responsorial_psalm:string,gospel_acclamation:string,gospel:string} $data
     * @return static
     */
    protected static function fromObjectInternal(\stdClass $data): static
    {
        static::validateRequiredProps($data, static::REQUIRED_PROPS);

        return new static(
            $data->first_reading,
            $data->responsorial_psalm,
            $data->gospel_acclamation,
            $data->gospel
        );
    }

    /**
     * Creates an instance of this class from an associative array.
     *
     * The array should have the following keys:
     * - first_reading (string): The first reading for a ferial day
     * - responsorial_psalm (string): The responsorial psalm for a ferial day
     * - gospel_acclamation (string): The alleluia verse for a ferial day
     * - gospel (string): The gospel for a ferial day
     *
     * @param array{first_reading:string,responsorial_psalm:string,gospel_acclamation:string,gospel:string} $data
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
            $data['gospel_acclamation'],
            $data['gospel']
        );
    }

    /**
     * {@inheritDoc}
     *
     * Returns an associative array containing the properties of this object,
     * with the following keys:
     * - first_reading (string): The first reading for a ferial day
     * - responsorial_psalm (string): The responsorial psalm for a ferial day
     * - gospel_acclamation (string): The alleluia verse for a ferial day
     * - gospel (string): The gospel for a ferial day
     * @return array{first_reading:string,responsorial_psalm:string,gospel_acclamation:string,gospel:string}
     */
    public function jsonSerialize(): array
    {
        return [
            'first_reading'      => $this->first_reading,
            'responsorial_psalm' => $this->responsorial_psalm,
            'gospel_acclamation' => $this->gospel_acclamation,
            'gospel'             => $this->gospel
        ];
    }
}
