<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\Exception;

/**
 * `OpenFgaClient::deleteTuple()` throws this when OpenFGA reports the tuple
 * being deleted does not exist.
 *
 * Callers managing idempotent revoke flows (e.g.
 * `AccessRequestAdminHandler::revokeRequest()`, manual re-revocation after a
 * partial first attempt) treat this as a benign no-op — the desired state is
 * already in place. Genuine FGA failures stay as the base
 * `OpenFgaApiException` and remain fatal.
 *
 * Detection is best-effort and version-tolerant: we match on OpenFGA's
 * documented error code (`cannot_allow_unknown_tuple_to_be_deleted`) plus
 * message substrings ("not found", "does not exist") that earlier OpenFGA
 * versions return under the more generic `write_failed_due_to_invalid_input`
 * code.
 */
final class TupleNotFoundException extends OpenFgaApiException
{
}
