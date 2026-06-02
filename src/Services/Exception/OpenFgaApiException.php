<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\Exception;

use RuntimeException;
use Throwable;

/**
 * Thrown by `OpenFgaClient` when the OpenFGA HTTP API returns a non-2xx
 * status.
 *
 * Carries the raw HTTP status and OpenFGA's structured error code so callers
 * can decide whether to treat the failure as fatal or benign — see the
 * sibling `TupleAlreadyExistsException` and `TupleNotFoundException` for the
 * specific re-throws `writeTuple()` and `deleteTuple()` issue for the
 * recoverable cases. Anything not classified to a subclass should be
 * surfaced to the user; do not catch and ignore the base class.
 */
class OpenFgaApiException extends RuntimeException
{
    /**
     * @param string $message Human-readable summary suitable for logs.
     * @param int $httpStatus HTTP status returned by OpenFGA.
     * @param string|null $errorCode OpenFGA error-code string from the response body, if present. Examples:
     *                               'cannot_allow_duplicate_tuple', 'write_failed_due_to_invalid_input',
     *                               'cannot_allow_unknown_tuple_to_be_deleted'.
     * @param array<string, mixed> $responseBody Decoded JSON body from OpenFGA, if any.
     */
    public function __construct(
        string $message,
        private readonly int $httpStatus,
        private readonly ?string $errorCode = null,
        private readonly array $responseBody = [],
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    /**
     * @return array<string, mixed>
     */
    public function getResponseBody(): array
    {
        return $this->responseBody;
    }
}
