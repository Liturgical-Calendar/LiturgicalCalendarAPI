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
     * Whether the event is aliturgical (no Mass celebrated), if applicable to the source data.
     * Absent/null for source data that does not classify aliturgical days (e.g. the Roman proprium).
     */
    public readonly ?bool $is_aliturgical;
    /**
     * Whether the event is a celebration of the Blessed Virgin Mary, if applicable to the source data.
     * Absent/null for source data that does not classify BVM celebrations (e.g. the Roman proprium).
     */
    public readonly ?bool $is_bvm;
    /**
     * First year in which the event is celebrated, if the source data gates it. Null when ungated.
     */
    public readonly ?int $since_year;
    /**
     * Last year in which the event is celebrated, if the source data gates it. Null when ungated.
     */
    public readonly ?int $until_year;

    /**
     * Constructor for the PropriumDeTemporeEvent class.
     *
     * @param string $event_key The key of the event.
     * @param LitGrade $grade The grade of the event.
     * @param LitEventType $type The type of the event.
     * @param LitColor[] $color The color of the event.
     * @param bool|null $is_dominical Whether the event is "of the Lord" (dominical), if applicable.
     * @param bool|null $is_aliturgical Whether the event is aliturgical (no Mass celebrated), if applicable.
     * @param bool|null $is_bvm Whether the event is a celebration of the Blessed Virgin Mary, if applicable.
     * @param int|null $since_year First year in which the event is celebrated, if the source data gates it.
     * @param int|null $until_year Last year in which the event is celebrated, if the source data gates it.
     */
    public function __construct(
        string $event_key,
        LitGrade $grade,
        LitEventType $type,
        array $color,
        ?bool $is_dominical = null,
        ?bool $is_aliturgical = null,
        ?bool $is_bvm = null,
        ?int $since_year = null,
        ?int $until_year = null
    ) {
        $this->event_key      = $event_key;
        $this->grade          = $grade;
        $this->type           = $type;
        $this->color          = $color;
        $this->is_dominical   = $is_dominical;
        $this->is_aliturgical = $is_aliturgical;
        $this->is_bvm         = $is_bvm;
        $this->since_year     = $since_year;
        $this->until_year     = $until_year;
    }

    /**
     * Creates an instance of PropriumDeTemporeEvent from an associative array.
     *
     * @param array{event_key:string,grade:int,type:int,color:string[],is_dominical?:bool|null,is_aliturgical?:bool|null,is_bvm?:bool|null} $data
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
            $data['is_dominical'] ?? null,
            $data['is_aliturgical'] ?? null,
            $data['is_bvm'] ?? null
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
     * @param \stdClass&object{event_key:string,grade:int,type:int,color:string[],is_dominical?:bool|null,is_aliturgical?:bool|null,is_bvm?:bool|null,since_year?:int|null,until_year?:int|null} $data The stdClass object or array containing event data.
     * @return static The newly created instance(s).
     */
    protected static function fromObjectInternal(\stdClass $data): static
    {
        static::validateRequiredProps($data, static::REQUIRED_PROPS);

        $is_dominical = null;
        if (property_exists($data, 'is_dominical') && is_bool($data->is_dominical)) {
            $is_dominical = $data->is_dominical;
        }

        $is_aliturgical = null;
        if (property_exists($data, 'is_aliturgical') && is_bool($data->is_aliturgical)) {
            $is_aliturgical = $data->is_aliturgical;
        }

        $is_bvm = null;
        if (property_exists($data, 'is_bvm') && is_bool($data->is_bvm)) {
            $is_bvm = $data->is_bvm;
        }

        $since_year = null;
        if (property_exists($data, 'since_year') && is_int($data->since_year)) {
            $since_year = $data->since_year;
        }

        $until_year = null;
        if (property_exists($data, 'until_year') && is_int($data->until_year)) {
            $until_year = $data->until_year;
        }

        return new static(
            $data->event_key,
            LitGrade::from($data->grade),
            LitEventType::from($data->type),
            array_map(fn (string $color): LitColor => LitColor::from($color), $data->color),
            $is_dominical,
            $is_aliturgical,
            $is_bvm,
            $since_year,
            $until_year
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
