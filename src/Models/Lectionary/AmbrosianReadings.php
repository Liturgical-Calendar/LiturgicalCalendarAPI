<?php

namespace LiturgicalCalendar\Api\Models\Lectionary;

/**
 * Factory for the Ambrosian empty-readings placeholder (Plan 7 / Task 2).
 *
 * There is currently no Ambrosian lectionary data source in this codebase, but the `LitCal.json`
 * response schema requires every `LiturgicalEvent` to carry a `readings` property that validates
 * against `CommonDef.json#/definitions/Readings`. Until a real Ambrosian lectionary is wired in,
 * Ambrosian events (both sanctorale and temporale) are given the placeholder produced by
 * {@see self::empty()} so that calendar generation and schema validation both succeed.
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
}
