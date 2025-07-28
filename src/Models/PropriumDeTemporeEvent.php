<?php

namespace LiturgicalCalendar\Api\Models;

use LiturgicalCalendar\Api\DateTime;
use LiturgicalCalendar\Api\Enum\LitColor;
use LiturgicalCalendar\Api\Enum\LitEventType;
use LiturgicalCalendar\Api\Enum\LitGrade;
use LiturgicalCalendar\Api\Models\Calendar\ReadingsEasterVigil;
use LiturgicalCalendar\Api\Models\Calendar\ReadingsFerial;
use LiturgicalCalendar\Api\Models\Calendar\ReadingsFestive;
use LiturgicalCalendar\Api\Models\Calendar\ReadingsPalmSunday;

final class PropriumDeTemporeEvent extends AbstractJsonSrcData
{
    private const REQUIRED_PROPS = [
        'event_key',
        'grade',
        'type',
        'color',
        'readings'
    ];

    public readonly string $event_key;
    public private(set) string $name;
    public readonly LitGrade $grade;
    public readonly LitEventType $type;
    /** @var LitColor[] $color */
    public readonly array $color;
    public readonly ReadingsEasterVigil|ReadingsPalmSunday|ReadingsFestive|ReadingsFerial $readings;
    public private(set) DateTime $date;

    /**
     * Constructor for the PropriumDeTemporeEvent class.
     *
     * @param string $event_key The key of the event.
     * @param LitGrade $grade The grade of the event.
     * @param LitEventType $type The type of the event.
     * @param LitColor[] $color The color of the event.
     * @param ReadingsEasterVigil|ReadingsPalmSunday|ReadingsFestive|ReadingsFerial $readings The readings for the event.
     */
    public function __construct(
        string $event_key,
        LitGrade $grade,
        LitEventType $type,
        array $color,
        ReadingsEasterVigil|ReadingsPalmSunday|ReadingsFestive|ReadingsFerial $readings
    ) {
        $this->event_key = $event_key;
        $this->grade     = $grade;
        $this->type      = $type;
        $this->color     = $color;
        $this->readings  = $readings;
    }

    /**
     * Creates an instance of PropriumDeTemporeEvent from an associative array.
     *
     * @param array{event_key:string,grade:int,type:int,color:string[],readings:array{first_reading:string,responsorial_psalm:string,second_reading?:string,alleluia_verse:string,gospel:string,palm_gospel?:string,responsorial_psalm_2?:string}} $data
     * @return static
     */
    protected static function fromArrayInternal(array $data): static
    {
        static::validateRequiredKeys($data, static::REQUIRED_PROPS);

        if (array_key_exists('palm_gospel', $data['readings'])) {
            if ($data['grade'] !== LitGrade::HIGHER_SOLEMNITY->value) {
                throw new \InvalidArgumentException('Palm Sunday is a higher solemnity, should have grade 7!');
            }
            $readings = ReadingsPalmSunday::fromArray($data['readings']);
        } elseif (array_key_exists('responsorial_psalm_2', $data['readings'])) {
            if ($data['grade'] !== LitGrade::HIGHER_SOLEMNITY->value) {
                throw new \InvalidArgumentException('Easter is a higher solemnity, should have grade 7!');
            }
            $readings = ReadingsEasterVigil::fromArray($data['readings']);
        } elseif (array_key_exists('second_reading', $data['readings'])) {
            if ($data['grade'] <= LitGrade::FEAST->value) {
                throw new \InvalidArgumentException('Events with a second reading should have grade 5 (Feast of the Lord) or higher!');
            }
            $readings = ReadingsFestive::fromArray($data['readings']);
        } else {
            if ($data['grade'] > LitGrade::FEAST->value) {
                throw new \InvalidArgumentException('Events higher than Feasts (grade 4) should have a second reading!');
            }
            $readings = ReadingsFerial::fromArray($data['readings']);
        }

        return new static(
            $data['event_key'],
            LitGrade::from($data['grade']),
            LitEventType::from($data['type']),
            array_map(fn (string $color): LitColor => LitColor::from($color), $data['color']),
            $readings
        );
    }

    /**
     * Creates an instance of PropriumDeTemporeEvent from a stdClass object.
     *
     * If the input is an array, an InvalidArgumentException is thrown.
     * The stdClass object must have the following properties:
     * - event_key (string): The key of the event.
     * - grade (int): The liturgical grade of the event.
     * - color (array): The liturgical colors for the event.
     * - readings (stdClass): The readings for the event, which may contain:
     *   -> palm_gospel (string, optional): The gospel for Palm Sunday.
     *   -> responsorial_psalm_2 (string, optional): The second responsorial psalm for Easter.
     *   -> first_reading, responsorial_psalm, alleluia_verse, gospel (string): Common readings.
     *
     * @param \stdClass $data The stdClass object or array containing event data.
     * @return static The newly created instance(s).
     */
    protected static function fromObjectInternal(\stdClass $data): static
    {
        static::validateRequiredProps($data, static::REQUIRED_PROPS);

        if (property_exists($data->readings, 'palm_gospel')) {
            if ($data->grade !== LitGrade::HIGHER_SOLEMNITY->value) {
                throw new \InvalidArgumentException('Palm Sunday is a higher solemnity, should have grade 7!');
            }
            $readings = ReadingsPalmSunday::fromObject($data->readings);
        } elseif (property_exists($data->readings, 'responsorial_psalm_2')) {
            if ($data->grade !== LitGrade::HIGHER_SOLEMNITY->value) {
                throw new \InvalidArgumentException('Easter is a higher solemnity, should have grade 7!');
            }
            $readings = ReadingsEasterVigil::fromObject($data->readings);
        } elseif (property_exists($data->readings, 'second_reading')) {
            if ($data->grade <= LitGrade::FEAST->value) {
                throw new \InvalidArgumentException('Events with a second reading should have grade 5 (Feast of the Lord) or higher!');
            }
            $readings = ReadingsFestive::fromObject($data->readings);
        } else {
            if ($data->grade > LitGrade::FEAST->value) {
                throw new \InvalidArgumentException('Events higher than Feasts (grade 4) should have a second reading!');
            }
            $readings = ReadingsFerial::fromObject($data->readings);
        }

        return new static(
            $data->event_key,
            LitGrade::from($data->grade),
            LitEventType::from($data->type),
            array_map(fn (string $color): LitColor => LitColor::from($color), $data->color),
            $readings
        );
    }

    /**
     * Sets the name of the PropriumDeTemporeEvent.
     *
     * @param string $name The name to set for the event.
     */
    public function setName(string $name): void
    {
        $this->name = $name;
    }

    /**
     * Sets the date for the PropriumDeTemporeEvent.
     *
     * @param DateTime $date The date to set for the event.
     * @return void
     */
    public function setDate(DateTime $date): void
    {
        $this->date = $date;
    }
}
