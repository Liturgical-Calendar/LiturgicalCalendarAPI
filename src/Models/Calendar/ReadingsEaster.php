<?php

namespace LiturgicalCalendar\Api\Models\Calendar;

final class ReadingsEaster extends ReadingsAbstract
{
    private const REQUIRED_PROPS = [
        'first_reading',
        'second_reading',
        'responsorial_psalm',
        'alleluia_verse',
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
        string $alleluia_verse,
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
            $alleluia_verse,
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
     * @param array{first_reading:string,second_reading:string,responsorial_psalm:string,alleluia_verse:string,gospel:string,responsorial_psalm_2:string,third_reading:string,responsorial_psalm_3:string,fourth_reading:string,responsorial_psalm_4:string,fifth_reading:string,responsorial_psalm_5:string,sixth_reading:string,responsorial_psalm_6:string,seventh_reading:string,responsorial_psalm_7:string,epistle:string,responsorial_psalm_epistle:string} $data
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
            $data['second_reading'],
            $data['responsorial_psalm'],
            $data['alleluia_verse'],
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
}
