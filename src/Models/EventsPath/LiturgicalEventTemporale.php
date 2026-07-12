<?php

namespace LiturgicalCalendar\Api\Models\EventsPath;

use LiturgicalCalendar\Api\Enum\LitColor;
use LiturgicalCalendar\Api\Enum\LitCommon;
use LiturgicalCalendar\Api\Enum\LitEventType;
use LiturgicalCalendar\Api\Enum\LitGrade;
use LiturgicalCalendar\Api\Enum\LitMassVariousNeeds;
use LiturgicalCalendar\Api\Models\Calendar\LitCommons;
use LiturgicalCalendar\Api\Models\Decrees\DecreeEventData;
use LiturgicalCalendar\Api\Models\LiturgicalEventData;
use LiturgicalCalendar\Api\Models\PropriumDeSanctisEvent;

/**
 * A Temporale (Proprium de Tempore) catalog entry.
 *
 * Temporale events (Advent Sundays, Easter, Pentecost, …) are computed from Easter, so they carry no stored
 * date — neither `month`/`day` (fixed) nor `strtotime` (relative). This model exists solely to enrich the
 * `/events` catalog with those event keys and their localized names, so consumers can reference temporale
 * anchors — most notably as the `event_key` of a decree's relative `strtotime` (e.g. "Monday after
 * Pentecost"). It is never used for calendar calculation.
 *
 * @phpstan-type TemporaleArray array{
 *     event_key: string,
 *     name: string,
 *     grade: LitGrade|int,
 *     color?: LitColor|LitColor[]|string|string[],
 *     type?: LitEventType|string,
 *     grade_display?: ?string,
 * }
 */
final class LiturgicalEventTemporale extends LiturgicalEventAbstract
{
    /**
     * @param string $event_key
     * @param string $name
     * @param LitColor|LitColor[] $color
     * @param LitGrade $grade
     * @param LitCommons|LitCommon|LitCommon[]|LitMassVariousNeeds|LitMassVariousNeeds[] $common
     * @param string|null $displayGrade
     */
    public function __construct(
        string $event_key,
        string $name,
        LitColor|array $color = LitColor::WHITE,
        LitGrade $grade = LitGrade::WEEKDAY,
        LitCommons|LitCommon|LitMassVariousNeeds|array $common = LitCommon::NONE,
        ?string $displayGrade = null
    ) {
        parent::__construct(
            $event_key,
            $name,
            is_array($color) ? $color : [$color],
            LitEventType::MOBILE,
            $grade,
            $common,
            $displayGrade
        );
    }

    /**
     * Serialize as a catalog entry — no `month`/`day`/`strtotime`, since a temporale event has no stored date.
     *
     * @return array{
     *      event_key: string,
     *      event_idx: int,
     *      name: string,
     *      color: array<'green'|'rose'|'purple'|'red'|'white'>,
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
     * Create a temporale catalog entry from a Proprium de Tempore source array (event_key, grade, type,
     * color) plus its localized name. Temporale events have no common, so `common` defaults to NONE.
     *
     * @param TemporaleArray $arr
     * @return LiturgicalEventTemporale
     * @throws \InvalidArgumentException If required keys are missing or of an invalid type.
     */
    public static function fromArray(array $arr): static
    {
        $requiredProps = ['event_key', 'name', 'grade'];
        $missingKeys   = array_diff($requiredProps, array_keys($arr));
        if (count($missingKeys) > 0) {
            throw new \InvalidArgumentException('Invalid array provided to create LiturgicalEventTemporale, missing required keys: ' . implode(', ', $missingKeys));
        }
        if (false === is_string($arr['name'])) {
            throw new \InvalidArgumentException('Invalid name provided to create LiturgicalEventTemporale');
        }
        if (false === $arr['grade'] instanceof LitGrade && false === is_int($arr['grade'])) {
            throw new \InvalidArgumentException('Invalid grade provided to create LiturgicalEventTemporale');
        }

        $colors = [LitColor::WHITE];
        if (array_key_exists('color', $arr)) {
            if (is_array($arr['color'])) {
                $valueTypes = array_values(array_unique(array_map('gettype', $arr['color'])));
                if (count($valueTypes) === 1 && $valueTypes[0] === 'string') {
                    /** @var string[] $color */
                    $color  = $arr['color'];
                    $colors = static::colorStringArrayToLitColorArray($color);
                } elseif (count($valueTypes) === 1 && $arr['color'][0] instanceof LitColor) {
                    /** @var LitColor[] $colors */
                    $colors = $arr['color'];
                }
            } elseif (is_string($arr['color'])) {
                $colors = [LitColor::from($arr['color'])];
            } elseif ($arr['color'] instanceof LitColor) {
                $colors = [$arr['color']];
            }
        }

        $grade = $arr['grade'] instanceof LitGrade
            ? $arr['grade']
            : ( LitGrade::tryFrom($arr['grade']) ?? LitGrade::WEEKDAY );

        $gradeDisplay = null;
        if (isset($arr['grade_display']) && is_string($arr['grade_display'])) {
            $gradeDisplay = $arr['grade_display'];
        }

        return new self($arr['event_key'], $arr['name'], $colors, $grade, LitCommon::NONE, $gradeDisplay);
    }

    /**
     * @param \stdClass|LiturgicalEventData|DecreeEventData|PropriumDeSanctisEvent $obj
     * @return LiturgicalEventTemporale
     */
    public static function fromObject(\stdClass|LiturgicalEventData|DecreeEventData|PropriumDeSanctisEvent $obj): static
    {
        /** @var TemporaleArray $arr */
        $arr = (array) $obj;
        return static::fromArray($arr);
    }
}
