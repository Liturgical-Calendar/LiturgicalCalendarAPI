<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Enum;

/**
 * What a schema is for.
 *
 * The distinction is load-bearing and was, until this enum, drawn only by hand. `CommonDef.json`'s
 * `Readings` describes *output*, where a vigil Mass is a liturgical event in its own right — its own
 * `EventKeyVigilMass`, its own `is_vigil_for`, its own flat `readings`. Source data nests a `vigil` key
 * instead, because there the vigil's readings belong to the event that has one, which is why
 * `PropriumDeSanctis.json` defines its own vigil-bearing variants rather than reusing `Readings`
 * wholesale.
 *
 * Nothing wrote that down, so reusing the output definition for a source check read as a one-line
 * change; it would have loosened `LitCal.json` to accept a shape this API cannot emit.
 *
 * {@see \LiturgicalCalendar\Api\Models\ValidationsPath\CheckableItem} requires a `LitSchema`, so
 * classifying every case turns "`/validations` checks source data" from a convention into an assertion.
 */
enum SchemaRole: string
{
    /** Validates data as it is stored under `jsondata/sourcedata/`. */
    case SOURCE = 'source';

    /** Validates a response this API emits. */
    case OUTPUT = 'output';

    /** Validates a request body this API accepts. */
    case PAYLOAD = 'payload';

    /** Validates a WebSocket message or frame. */
    case PROTOCOL = 'protocol';

    /** Holds shared definitions and validates nothing on its own. */
    case LIBRARY = 'library';
}
