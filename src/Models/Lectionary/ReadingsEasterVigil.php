<?php

namespace LiturgicalCalendar\Api\Models\Lectionary;

final class ReadingsEasterVigil extends ReadingsAbstract
{
    private const REQUIRED_PROPS = [
        'first_reading',
        'second_reading',
        'responsorial_psalm',
        'gospel_acclamation',
        'gospel',
        'responsorial_psalm_2',
        'third_reading',
        'responsorial_psalm_3',
        'fourth_reading',
        'responsorial_psalm_4',
        'fifth_reading',
        'responsorial_psalm_5',
        'sixth_reading',
        'responsorial_psalm_6',
        'seventh_reading',
        'responsorial_psalm_7',
        'epistle',
        'responsorial_psalm_epistle',
    ];

    public readonly string $second_reading;
    public readonly string $responsorial_psalm_2;
    public readonly string $third_reading;
    public readonly string $responsorial_psalm_3;
    public readonly string $fourth_reading;
    public readonly string $responsorial_psalm_4;
    public readonly string $fifth_reading;
    public readonly string $responsorial_psalm_5;
    public readonly string $sixth_reading;
    public readonly string $responsorial_psalm_6;
    public readonly string $seventh_reading;
    public readonly string $responsorial_psalm_7;
    public readonly string $epistle;
    public readonly string $responsorial_psalm_epistle;

    private function __construct(
        string $first_reading,
        string $second_reading,
        string $responsorial_psalm,
        string $gospel_acclamation,
        string $gospel,
        string $responsorial_psalm_2,
        string $third_reading,
        string $responsorial_psalm_3,
        string $fourth_reading,
        string $responsorial_psalm_4,
        string $fifth_reading,
        string $responsorial_psalm_5,
        string $sixth_reading,
        string $responsorial_psalm_6,
        string $seventh_reading,
        string $responsorial_psalm_7,
        string $epistle,
        string $responsorial_psalm_epistle
    ) {
        parent::__construct(
            $first_reading,
            $responsorial_psalm,
            $gospel_acclamation,
            $gospel
        );

        $this->second_reading             = $second_reading;
        $this->responsorial_psalm_2       = $responsorial_psalm_2;
        $this->third_reading              = $third_reading;
        $this->responsorial_psalm_3       = $responsorial_psalm_3;
        $this->fourth_reading             = $fourth_reading;
        $this->responsorial_psalm_4       = $responsorial_psalm_4;
        $this->fifth_reading              = $fifth_reading;
        $this->responsorial_psalm_5       = $responsorial_psalm_5;
        $this->sixth_reading              = $sixth_reading;
        $this->responsorial_psalm_6       = $responsorial_psalm_6;
        $this->seventh_reading            = $seventh_reading;
        $this->responsorial_psalm_7       = $responsorial_psalm_7;
        $this->epistle                    = $epistle;
        $this->responsorial_psalm_epistle = $responsorial_psalm_epistle;
    }

    /**
     * @param \stdClass&object{first_reading:string,second_reading:string,responsorial_psalm:string,gospel_acclamation:string,gospel:string,responsorial_psalm_2:string,third_reading:string,responsorial_psalm_3:string,fourth_reading:string,responsorial_psalm_4:string,fifth_reading:string,responsorial_psalm_5:string,sixth_reading:string,responsorial_psalm_6:string,seventh_reading:string,responsorial_psalm_7:string,epistle:string,responsorial_psalm_epistle:string} $data
     * @return static
     */
    protected static function fromObjectInternal(\stdClass $data): static
    {
        static::validateRequiredProps($data, static::REQUIRED_PROPS);

        return new static(
            $data->first_reading,
            $data->second_reading,
            $data->responsorial_psalm,
            $data->gospel_acclamation,
            $data->gospel,
            $data->responsorial_psalm_2,
            $data->third_reading,
            $data->responsorial_psalm_3,
            $data->fourth_reading,
            $data->responsorial_psalm_4,
            $data->fifth_reading,
            $data->responsorial_psalm_5,
            $data->sixth_reading,
            $data->responsorial_psalm_6,
            $data->seventh_reading,
            $data->responsorial_psalm_7,
            $data->epistle,
            $data->responsorial_psalm_epistle
        );
    }

    /**
     * Creates an instance of this class from an array.
     *
     * @param array{first_reading:string,second_reading:string,responsorial_psalm:string,gospel_acclamation:string,gospel:string,responsorial_psalm_2:string,third_reading:string,responsorial_psalm_3:string,fourth_reading:string,responsorial_psalm_4:string,fifth_reading:string,responsorial_psalm_5:string,sixth_reading:string,responsorial_psalm_6:string,seventh_reading:string,responsorial_psalm_7:string,epistle:string,responsorial_psalm_epistle:string} $data
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
            $data['second_reading'],
            $data['responsorial_psalm'],
            $data['gospel_acclamation'],
            $data['gospel'],
            $data['responsorial_psalm_2'],
            $data['third_reading'],
            $data['responsorial_psalm_3'],
            $data['fourth_reading'],
            $data['responsorial_psalm_4'],
            $data['fifth_reading'],
            $data['responsorial_psalm_5'],
            $data['sixth_reading'],
            $data['responsorial_psalm_6'],
            $data['seventh_reading'],
            $data['responsorial_psalm_7'],
            $data['epistle'],
            $data['responsorial_psalm_epistle']
        );
    }

    /**
     * {@inheritDoc}
     *
     * Returns an associative array containing the properties of this object,
     * with the following keys:
     * - first_reading (string): The first reading for the Easter Vigil
     * - responsorial_psalm (string): The responsorial psalm for the Easter Vigil
     * - second_reading (string): The second reading for the Easter Vigil
     * - responsorial_psalm_2 (string): The responsorial psalm for the second reading for the Easter Vigil
     * - third_reading (string): The third reading for the Easter Vigil
     * - responsorial_psalm_3 (string): The responsorial psalm for the third reading for the Easter Vigil
     * - fourth_reading (string): The fourth reading for the Easter Vigil
     * - responsorial_psalm_4 (string): The responsorial psalm for the fourth reading for the Easter Vigil
     * - fifth_reading (string): The fifth reading for the Easter Vigil
     * - responsorial_psalm_5 (string): The responsorial psalm for the fifth reading for the Easter Vigil
     * - sixth_reading (string): The sixth reading for the Easter Vigil
     * - responsorial_psalm_6 (string): The responsorial psalm for the sixth reading for the Easter Vigil
     * - seventh_reading (string): The seventh reading for the Easter Vigil
     * - responsorial_psalm_7 (string): The responsorial psalm for the seventh reading for the Easter Vigil
     * - epistle (string): The epistle for the Easter Vigil
     * - responsorial_psalm_epistle (string): The responsorial psalm for the epistle for the Easter Vigil
     * - gospel_acclamation (string): The alleluia verse for the Easter Vigil
     * - gospel (string): The gospel for the Easter Vigil
     * @return array{first_reading:string,responsorial_psalm:string,second_reading:string,responsorial_psalm_2:string,third_reading:string,responsorial_psalm_3:string,fourth_reading:string,responsorial_psalm_4:string,fifth_reading:string,responsorial_psalm_5:string,sixth_reading:string,responsorial_psalm_6:string,seventh_reading:string,responsorial_psalm_7:string,epistle:string,responsorial_psalm_epistle:string,gospel_acclamation:string,gospel:string}
     */
    public function jsonSerialize(): array
    {
        return [
            'first_reading'              => $this->first_reading,
            'responsorial_psalm'         => $this->responsorial_psalm,
            'second_reading'             => $this->second_reading,
            'responsorial_psalm_2'       => $this->responsorial_psalm_2,
            'third_reading'              => $this->third_reading,
            'responsorial_psalm_3'       => $this->responsorial_psalm_3,
            'fourth_reading'             => $this->fourth_reading,
            'responsorial_psalm_4'       => $this->responsorial_psalm_4,
            'fifth_reading'              => $this->fifth_reading,
            'responsorial_psalm_5'       => $this->responsorial_psalm_5,
            'sixth_reading'              => $this->sixth_reading,
            'responsorial_psalm_6'       => $this->responsorial_psalm_6,
            'seventh_reading'            => $this->seventh_reading,
            'responsorial_psalm_7'       => $this->responsorial_psalm_7,
            'epistle'                    => $this->epistle,
            'responsorial_psalm_epistle' => $this->responsorial_psalm_epistle,
            'gospel_acclamation'         => $this->gospel_acclamation,
            'gospel'                     => $this->gospel
        ];
    }
}
