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
        'color'
    ];

    public readonly string $event_key;
    public private(set) string $name;
    public readonly LitGrade $grade;
    public readonly LitEventType $type;
    /** @var LitColor[] $color */
    public readonly array $color;
    public private(set) DateTime $date;

    /**
     * Constructor for the PropriumDeTemporeEvent class.
     *
     * @param string $event_key The key of the event.
     * @param LitGrade $grade The grade of the event.
     * @param LitEventType $type The type of the event.
     * @param LitColor[] $color The color of the event.
     */
    public function __construct(
        string $event_key,
        LitGrade $grade,
        LitEventType $type,
        array $color
    ) {
        $this->event_key = $event_key;
        $this->grade     = $grade;
        $this->type      = $type;
        $this->color     = $color;
    }

    /**
     * Creates an instance of PropriumDeTemporeEvent from an associative array.
     *
     * @param array{event_key:string,grade:int,type:int,color:string[]} $data
     * @return static
     */
    protected static function fromArrayInternal(array $data): static
    {
        static::validateRequiredKeys($data, static::REQUIRED_PROPS);

        return new static(
            $data['event_key'],
            LitGrade::from($data['grade']),
            LitEventType::from($data['type']),
            array_map(fn (string $color): LitColor => LitColor::from($color), $data['color'])
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
     *
     * @param \stdClass $data The stdClass object or array containing event data.
     * @return static The newly created instance(s).
     */
    protected static function fromObjectInternal(\stdClass $data): static
    {
        static::validateRequiredProps($data, static::REQUIRED_PROPS);

        return new static(
            $data->event_key,
            LitGrade::from($data->grade),
            LitEventType::from($data->type),
            array_map(fn (string $color): LitColor => LitColor::from($color), $data->color)
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
