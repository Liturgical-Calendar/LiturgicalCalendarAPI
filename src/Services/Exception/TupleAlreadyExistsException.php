<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\Exception;

/**
 * `OpenFgaClient::writeTuple()` throws this when OpenFGA reports the tuple
 * being written already exists.
 *
 * Callers managing idempotent grant flows (e.g.
 * `AccessRequestAdminHandler::approveRequest()`, manual re-approval after a
 * partial first attempt) treat this as a benign no-op — the desired state is
 * already in place. Genuine FGA failures stay as the base
 * `OpenFgaApiException` and remain fatal.
 *
 * Detection is best-effort and version-tolerant: we match on OpenFGA's
 * documented error code (`cannot_allow_duplicate_tuple`) plus message
 * substrings ("already exists", "duplicate") that earlier OpenFGA versions
 * return under the more generic `write_failed_due_to_invalid_input` code.
 */
final class TupleAlreadyExistsException extends OpenFgaApiException
{
}
