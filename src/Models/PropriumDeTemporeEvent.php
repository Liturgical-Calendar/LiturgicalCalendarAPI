<?php

namespace LiturgicalCalendar\Api\Models;

use LiturgicalCalendar\Api\DateTime;
use LiturgicalCalendar\Api\Enum\LitColor;
use LiturgicalCalendar\Api\Enum\LitEventType;
use LiturgicalCalendar\Api\Enum\LitGrade;

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
     * Whether the event is "of the Lord" (dominical), if applicable to the source data.
     * Absent/null for source data that does not classify dominical events (e.g. the Roman proprium).
     */
    public readonly ?bool $is_dominical;

    /**
     * Constructor for the PropriumDeTemporeEvent class.
     *
     * @param string $event_key The key of the event.
     * @param LitGrade $grade The grade of the event.
     * @param LitEventType $type The type of the event.
     * @param LitColor[] $color The color of the event.
     * @param bool|null $is_dominical Whether the event is "of the Lord" (dominical), if applicable.
     */
    public function __construct(
        string $event_key,
        LitGrade $grade,
        LitEventType $type,
        array $color,
        ?bool $is_dominical = null
    ) {
        $this->event_key    = $event_key;
        $this->grade        = $grade;
        $this->type         = $type;
        $this->color        = $color;
        $this->is_dominical = $is_dominical;
    }

    /**
     * Creates an instance of PropriumDeTemporeEvent from an associative array.
     *
     * @param array{event_key:string,grade:int,type:int,color:string[],is_dominical?:bool|null} $data
     * @return static
     */
    protected static function fromArrayInternal(array $data): static
    {
        static::validateRequiredKeys($data, static::REQUIRED_PROPS);

        return new static(
            $data['event_key'],
            LitGrade::from($data['grade']),
            LitEventType::from($data['type']),
            array_map(fn (string $color): LitColor => LitColor::from($color), $data['color']),
            $data['is_dominical'] ?? null
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
     * @param \stdClass&object{event_key:string,grade:int,type:int,color:string[],is_dominical?:bool|null} $data The stdClass object or array containing event data.
     * @return static The newly created instance(s).
     */
    protected static function fromObjectInternal(\stdClass $data): static
    {
        static::validateRequiredProps($data, static::REQUIRED_PROPS);

        $is_dominical = null;
        if (property_exists($data, 'is_dominical') && is_bool($data->is_dominical)) {
            $is_dominical = $data->is_dominical;
        }

        return new static(
            $data->event_key,
            LitGrade::from($data->grade),
            LitEventType::from($data->type),
            array_map(fn (string $color): LitColor => LitColor::from($color), $data->color),
            $is_dominical
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
