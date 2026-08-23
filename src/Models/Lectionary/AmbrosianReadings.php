<?php

namespace LiturgicalCalendar\Api\Models\Lectionary;

use LiturgicalCalendar\Api\Enum\LitGrade;

/**
 * Factory for the Ambrosian empty-readings placeholder (Plan 7 / Task 2).
 *
 * There is currently no Ambrosian lectionary data source in this codebase, but the `LitCal.json`
 * response schema requires every `LiturgicalEvent` to carry a `readings` property that validates
 * against `CommonDef.json#/definitions/Readings`. Until a real Ambrosian lectionary is wired in,
 * Ambrosian events (both sanctorale and temporale) are given a placeholder so that calendar
 * generation and schema validation both succeed. The placeholder's shape is derived from the
 * event's grade via {@see self::forGrade()}: festive (5-field) from FEAST upward, ferial
 * (4-field) below FEAST — callers that need a specific shape regardless of grade can still reach
 * {@see self::empty()} or {@see self::emptyFestive()} directly.
 *
 * `ReadingsFerial` is deliberately reused rather than inventing a new shape: it is the simplest
 * of the `ReadingsAbstract` subclasses accepted by `CommonDef.json#/definitions/Readings`
 * (only `first_reading`, `responsorial_psalm`, `gospel_acclamation`, and `gospel`, all plain
 * strings, `additionalProperties: false`), so populating all four required fields with empty
 * strings is sufficient to be schema-valid without implying any actual liturgical content.
 */
final class AmbrosianReadings
{
    private function __construct()
    {
        // Not instantiable; use self::empty() to obtain the placeholder.
    }

    /**
     * Builds a schema-valid, content-empty `ReadingsFerial` instance to serve as the Ambrosian
     * readings placeholder.
     *
     * @return ReadingsFerial A `ReadingsAbstract` instance with all required fields set to `""`.
     */
    public static function empty(): ReadingsFerial
    {
        return ReadingsFerial::fromArray([
            'first_reading'      => '',
            'responsorial_psalm' => '',
            'gospel_acclamation' => '',
            'gospel'             => '',
        ]);
    }

    /**
     * Builds a schema-valid, content-empty `ReadingsFestive` instance to serve as the Ambrosian
     * readings placeholder for festive (5-field) events.
     *
     * @return ReadingsFestive A `ReadingsAbstract` instance with all required fields set to `""`.
     */
    public static function emptyFestive(): ReadingsFestive
    {
        return ReadingsFestive::fromArray([
            'first_reading'      => '',
            'responsorial_psalm' => '',
            'second_reading'     => '',
            'gospel_acclamation' => '',
            'gospel'             => '',
        ]);
    }

    /**
     * Selects the empty-readings placeholder that matches a liturgical grade.
     *
     * Festive (5-field) celebrations from FEAST upward carry a second reading; anything below
     * FEAST uses the ferial (4-field) shape. This keeps the Ambrosian diocesan overlay from
     * stamping a festive shape onto a memorial, which is what the blanket `emptyFestive()` call
     * it replaces used to do.
     *
     * @param LitGrade $grade The liturgical grade of the event being stamped.
     * @return ReadingsFerial|ReadingsFestive The placeholder matching the grade.
     */
    public static function forGrade(LitGrade $grade): ReadingsFerial|ReadingsFestive
    {
        return $grade->value >= LitGrade::FEAST->value
            ? self::emptyFestive()
            : self::empty();
    }
}
