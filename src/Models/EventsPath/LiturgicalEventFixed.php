<?php

namespace LiturgicalCalendar\Api\Models\EventsPath;

use LiturgicalCalendar\Api\Enum\LitColor;
use LiturgicalCalendar\Api\Enum\LitEventType;
use LiturgicalCalendar\Api\Enum\LitGrade;
use LiturgicalCalendar\Api\Enum\LitCommon;
use LiturgicalCalendar\Api\Enum\LitMassVariousNeeds;
use LiturgicalCalendar\Api\Models\Calendar\LitCommons;
use LiturgicalCalendar\Api\Models\Decrees\DecreeEventData;
use LiturgicalCalendar\Api\Models\Decrees\DecreeItemCreateNewFixed;
use LiturgicalCalendar\Api\Models\LiturgicalEventData;
use LiturgicalCalendar\Api\Models\PropriumDeSanctisEvent;
use LiturgicalCalendar\Api\Models\RegionalData\NationalData\LitCalItemCreateNewFixed;
use LiturgicalCalendar\Api\Models\RegionalData\DiocesanData\DiocesanLitCalItemCreateNewFixed;

/**
 * @phpstan-type LiturgicalEventObject \stdClass&object{
 *     event_key: string,
 *     name: string,
 *     month: integer,
 *     day: integer,
 *     grade: LitGrade|integer,
 *     color?: LitColor|LitColor[]|string|string[],
 *     type?: LitEventType|string,
 *     common?: LitCommons|LitCommon[]|LitMassVariousNeeds[]|string[],
 *     grade_display?: string,
 * }
 */
final class LiturgicalEventFixed extends LiturgicalEventAbstract
{
    public int $month;
    public int $day;

    /**
     * @param string $event_key
     * @param string $name
     * @param int $month
     * @param int $day
     * @param LitColor|LitColor[] $color
     * @param LitEventType $type
     * @param LitGrade $grade
     * @param LitCommons|LitCommon|LitCommon[]|LitMassVariousNeeds|LitMassVariousNeeds[] $common
     * @param string|null $displayGrade
     */
    public function __construct(
        string $event_key,
        string $name,
        int $month,
        int $day,
        LitColor|array $color = LitColor::GREEN,
        LitEventType $type = LitEventType::FIXED,
        LitGrade $grade = LitGrade::WEEKDAY,
        LitCommons|LitCommon|LitMassVariousNeeds|array $common = LitCommon::NONE,
        ?string $displayGrade = null
    ) {
        parent::__construct(
            $event_key,
            $name,
            is_array($color) ? $color : [$color],
            $type,
            $grade,
            $common,
            $displayGrade
        );
        $this->month = $month;
        $this->day   = $day;
    }


    /**
     * This function is used to finalize the output of the object for serialization as a JSON string.
     * It returns an associative array with the following keys:
     * - event_key: a unique key for the liturgical event
     * - event_idx: the index of the event in the array of liturgical events
     * - name: the name of the liturgical event
     * - color: the liturgical color of the liturgical event
     * - color_lcl: the color of the liturgical event, translated according to the current locale
     * - type: the type of the liturgical event (mobile or fixed)
     * - grade: the grade of the liturgical event (0=weekday, 1=commemoration, 2=optional memorial, 3=memorial, 4=feast, 5=feast of the Lord, 6=solemnity, 7=higher solemnity)
     * - grade_lcl: the grade of the liturgical event, translated according to the current locale
     * - grade_abbr: the abbreviated grade of the liturgical event, translated according to the current locale
     * - grade_display: a nullable string which, when not null, takes precedence over `grade_lcl` or `grade_abbr` for how the liturgical grade should be displayed
     * - common: an array of common prayers associated with the liturgical event
     * - common_lcl: an array of common prayers associated with the liturgical event, translated according to the current locale
     * - month: the month of the liturgical event, in the ISO 8601 format (1 for January, 12 for December)
     * - day: the day of the month of the liturgical event
     * @return array{
     *      event_key: string,
     *      event_idx: int,
     *      name: string,
     *      month: int,
     *      day: int,
     *      color: array<'green'|'pink'|'purple'|'red'|'white'>,
     *      color_lcl: string[],
     *      type: 'fixed'|'mobile',
     *      grade: -1|0|1|2|3|4|5|6|7,
     *      grade_lcl: string,
     *      grade_abbr: string,
     *      grade_display: ?string,
     *      common: string[],
     *      common_lcl: string,
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'event_key'     => $this->event_key,
            'event_idx'     => $this->event_idx,
            'name'          => $this->name,
            'month'         => $this->month,
            'day'           => $this->day,
            'color'         => array_map(fn ($color) => $color->value, $this->color),
            'color_lcl'     => $this->color_lcl,
            'grade'         => $this->grade->value,
            'grade_lcl'     => $this->grade_lcl,
            'grade_abbr'    => $this->grade_abbr,
            'grade_display' => $this->grade_display,
            'common'        => $this->common instanceof LitCommons
                                            ? $this->common->jsonSerialize()
                                            : array_map(fn (LitMassVariousNeeds $litMassVariousNeeds) => $litMassVariousNeeds->value, $this->common),
            'common_lcl'    => $this->common_lcl,
            'type'          => $this->type->value,
        ];
    }

    /**
     * Creates a new LiturgicalEventFixed object from an object containing the required properties.
     *
     * The provided object must have the following properties:
     * - name: The name of the liturgical event, as a string.
     * - date: The date of the liturgical event, as a DateTime object or as an integer representing the Unix timestamp.
     * - grade: The grade of the liturgical event, as a LitGrade object or as an integer.
     *
     * Optional properties are:
     * - color: The liturgical color of the liturgical event, as an array of strings or LitColor cases, or as a single string or single LitColor case.
     *   If not provided, defaults to LitColor::GREEN.
     * - common: The liturgical common of the liturgical event, as an array of strings or LitCommon cases, or as a single string or single LitCommon case.
     *   If not provided, defaults to LitCommon::NONE.
     * - type: The type of the liturgical event, as a LitEventType object or as a string.
     *   If not provided, defaults to LitEventType::FIXED.
     * - grade_display: The grade display of the liturgical event, as a string. If not provided, defaults to null.
     *
     * @param LiturgicalEventObject|LiturgicalEventData|DecreeEventData|PropriumDeSanctisEvent $obj
     * @return LiturgicalEventFixed A new LiturgicalEventFixed object.
     * @throws \InvalidArgumentException If the provided object does not contain the required properties or if the properties have invalid types.
     */
    public static function fromObject(\stdClass|LiturgicalEventData|DecreeEventData|PropriumDeSanctisEvent $obj): static
    {
        $requiredProps = ['event_key', 'name', 'grade', 'month', 'day'];
        $currentProps  = array_keys(get_object_vars($obj));
        $missingKeys   = array_diff($requiredProps, $currentProps);

        if (count($missingKeys) > 0) {
            throw new \InvalidArgumentException('Invalid object provided to create LiturgicalEventFixed, missing required keys: ' . implode(', ', $missingKeys));
        }

        if (false === is_string($obj->name)) {
            throw new \InvalidArgumentException('Invalid name provided to create LiturgicalEventFixed');
        }

        if (
            false === $obj instanceof \stdClass
            && false === $obj instanceof LitCalItemCreateNewFixed
            && false === $obj instanceof DiocesanLitCalItemCreateNewFixed
            && false === $obj instanceof DecreeItemCreateNewFixed
            && false === $obj instanceof PropriumDeSanctisEvent
        ) {
            throw new \InvalidArgumentException('Invalid type provided to create LiturgicalEventFixed');
        }

        if (false === $obj->grade instanceof LitGrade && false === is_int($obj->grade)) {
            throw new \InvalidArgumentException('Invalid grade provided to create LiturgicalEventFixed');
        }

        // set some default values
        /** @var LitCommons */
        $commons = LitCommons::create([]);
        $colors  = [LitColor::GREEN];
        $type    = LitEventType::FIXED;
        $grade   = LitGrade::WEEKDAY;

        // When we read data from a JSON file, $obj will be an instance of stdClass,
        // and we need to cast the values to types that will be accepted by the LiturgicalEventFixed constructor
        if ($obj instanceof \stdClass) {
            if (property_exists($obj, 'color')) {
                if (is_array($obj->color)) {
                    $valueTypes = array_values(array_unique(array_map(fn($value) => gettype($value), $obj->color)));
                    if (count($valueTypes) > 1) {
                        throw new \InvalidArgumentException('Incoherent color value types provided to create LiturgicalEventFixed: found multiple types ' . implode(', ', $valueTypes));
                    }
                    if ($valueTypes[0] === 'string') {
                        /** @var string[] $color */
                        $color  = $obj->color;
                        $colors = static::colorStringArrayToLitColorArray($color);
                    } elseif (false === $obj->color[0] instanceof LitColor) {
                        throw new \InvalidArgumentException('Invalid color value types provided to create LiturgicalEventFixed. Expected type string or LitColor, found ' . $valueTypes[0]);
                    }
                } elseif (is_string($obj->color)) {
                    $colors = [LitColor::from($obj->color)];
                } elseif ($obj->color instanceof LitColor) {
                    $colors = [$obj->color];
                } else {
                    throw new \InvalidArgumentException('Invalid color value type provided to create LiturgicalEventFixed');
                }
            }

            if (property_exists($obj, 'type')) {
                if (false === $obj->type instanceof LitEventType && false === is_string($obj->type)) {
                    throw new \InvalidArgumentException('Invalid type provided to create LiturgicalEventFixed');
                }
                if (is_string($obj->type)) {
                    $type = LitEventType::from($obj->type);
                }
            }

            if (is_int($obj->grade)) {
                $grade = LitGrade::tryFrom($obj->grade) ?? LitGrade::WEEKDAY;
            }

            if (isset($obj->common)) {
                $commons = self::transformCommons($obj->common);
            } else {
                // We ensure a default value
                /** @var LitCommons */
                $commons = LitCommons::create([]);
            }
        } else {
            if (isset($obj->common)) {
                /** @var LitCommons */
                $commons = $obj->common;
            }
            if (isset($obj->color)) {
                $colors = $obj->color;
            }
            if (isset($obj->type)) {
                $type = $obj->type;
            }
            if (isset($obj->grade)) {
                $grade = $obj->grade;
            }
        }

        if (false === $grade instanceof LitGrade) {
            throw new \Exception('“Examine yourselves to see whether you are living in faith. Test yourselves. Do you not realize that Jesus Christ is in you?—unless, of course, you fail the test.” (1 Corinthians 13:5)');
        }

        return new self(
            $obj->event_key,
            $obj->name,
            $obj->month,
            $obj->day,
            $colors,
            $type,
            $grade,
            $commons,
            $obj->grade_display ?? null
        );
    }

    /**
     * Create a new LiturgicalEventFixed object from an associative array.
     *
     * The array must contain the following keys:
     * - event_key: A unique key for the liturgical event, as a string.
     * - name: The name of the liturgical event, as a string.
     * - month: The month of the liturgical event, as an integer.
     * - day: The day of the liturgical event, as an integer.
     * - grade: The grade of the liturgical event, as a LitGrade object or as an integer.
     *
     * Optional keys are:
     * - color: The liturgical color of the liturgical event, as an array of strings or LitColor cases, or as a single string or single LitColor case.
     *   If not provided, defaults to LitColor::GREEN.
     * - type: The type of the liturgical event, as a LitEventType object or as a string.
     *   If not provided, defaults to LitEventType::FIXED.
     * - common: The liturgical common of the liturgical event, as an array of strings or LitCommon cases, or as a single string or single LitCommon case.
     *   If not provided, defaults to LitCommon::NONE.
     * - grade_display: The grade display of the liturgical event, as a string. If not provided, defaults to null.
     *
     * @param array{
     *     event_key: string,
     *     name: string,
     *     month: integer,
     *     day: integer,
     *     grade: LitGrade|integer,
     *     color?: LitColor|LitColor[]|string|string[],
     *     type?: LitEventType|string,
     *     common?: LitCommons|LitCommon[]|LitMassVariousNeeds[]|string[],
     *     grade_display?: string,
     * } $arr The associative array containing the required properties.
     * @return LiturgicalEventFixed A new LiturgicalEventFixed object.
     * @throws \InvalidArgumentException If the provided array does not contain the required properties or if the properties have invalid types.
     */
    public static function fromArray(array $arr): static
    {
        $requiredProps = ['event_key', 'name', 'grade', 'month', 'day'];
        $currentProps  = array_keys($arr);
        $missingKeys   = array_diff($requiredProps, $currentProps);

        if (count($missingKeys) > 0) {
            throw new \InvalidArgumentException('Invalid array provided to create LiturgicalEventFixed, missing required keys: ' . implode(', ', $missingKeys));
        }

        if (false === is_string($arr['name'])) {
            throw new \InvalidArgumentException('Invalid name provided to create LiturgicalEventFixed');
        }

        if (false === $arr['grade'] instanceof LitGrade && false === is_int($arr['grade'])) {
            throw new \InvalidArgumentException('Invalid grade provided to create LiturgicalEventFixed');
        }

        $colors = LitColor::GREEN;
        if (array_key_exists('color', $arr)) {
            if (is_array($arr['color'])) {
                $valueTypes = array_values(array_unique(array_map(fn($value) => gettype($value), $arr['color'])));
                if (count($valueTypes) > 1) {
                    throw new \InvalidArgumentException('Incoherent color value types provided to create LiturgicalEventFixed: found multiple types ' . implode(', ', $valueTypes));
                }
                if ($valueTypes[0] === 'string') {
                    /** @var string[] */
                    $color  = $arr['color'];
                    $colors = static::colorStringArrayToLitColorArray($color);
                } elseif (false === $arr['color'][0] instanceof LitColor) {
                    throw new \InvalidArgumentException('Invalid color value types provided to create LiturgicalEventFixed. Expected type string or LitColor, found ' . $valueTypes[0]);
                }
            } elseif (false === $arr['color'] instanceof LitColor && false === is_string($arr['color'])) {
                throw new \InvalidArgumentException('Invalid color value type provided to create LiturgicalEventFixed');
            } elseif (is_string($arr['color'])) {
                $colors = LitColor::from($arr['color']);
            }
        }

        if (array_key_exists('type', $arr)) {
            if (false === $arr['type'] instanceof LitEventType && false === is_string($arr['type'])) {
                throw new \InvalidArgumentException('Invalid type provided to create LiturgicalEventFixed');
            }
            if (is_string($arr['type'])) {
                $arr['type'] = LitEventType::from($arr['type']);
            }
        } else {
            $arr['type'] = LitEventType::FIXED;
        }

        if (is_int($arr['grade'])) {
            $arr['grade'] = LitGrade::tryFrom($arr['grade']) ?? LitGrade::WEEKDAY;
        }

        if (array_key_exists('common', $arr)) {
            $commons = self::transformCommons($arr['common']);
        } else {
            /** @var LitCommons $commons */
            $commons = LitCommons::create([LitCommon::NONE]);
        }

        return new self(
            $arr['event_key'],
            $arr['name'],
            $arr['month'],
            $arr['day'],
            $colors,
            $arr['type'],
            $arr['grade'],
            $commons,
            isset($arr['grade_display']) ? $arr['grade_display'] : null
        );
    }
}
