<?php

namespace LiturgicalCalendar\Api\Enum;

/**
 * Why a message was refused, in a form a client can branch on.
 *
 * A code exists where a client would *act* differently; where it would only display the reason, the
 * reason is prose in the frame's `text`. That is why `INVALID_MESSAGE` covers every schema
 * violation — a wrong type, an unknown enum value and an undeclared property all mean "fix the
 * message", and the text says which — while `RETIRED_PROPERTY` (you are half-migrated) and
 * `UNKNOWN_TARGET_ID` (refetch /validations) are separate.
 */
enum ProtocolErrorCode: string
{
    case INVALID_JSON       = 'invalid_json';
    case NOT_AN_OBJECT      = 'not_an_object';
    case MISSING_ACTION     = 'missing_action';
    case UNKNOWN_ACTION     = 'unknown_action';
    case INVALID_REQUEST_ID = 'invalid_request_id';
    case RETIRED_PROPERTY   = 'retired_property';
    case UNKNOWN_TARGET_ID  = 'unknown_target_id';
    case INVALID_MESSAGE    = 'invalid_message';
    case INTERNAL_ERROR     = 'internal_error';

    /**
     * The client declared a protocol version this server does not speak — #806 section F.
     *
     * Separate from `INVALID_MESSAGE` by the rule above, because a client acts on it differently:
     * every other schema violation means "fix this message", while this one means "you are talking
     * to a server of the wrong vintage" and the remedy is to fall back or to stop, not to correct a
     * field. The `hello` frame names the version that would have worked.
     */
    case UNSUPPORTED_PROTOCOL = 'unsupported_protocol';
}
