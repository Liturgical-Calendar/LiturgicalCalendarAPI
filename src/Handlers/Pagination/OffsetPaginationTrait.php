<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Handlers\Pagination;

use LiturgicalCalendar\Api\Http\Exception\ValidationException;

/**
 * Shared `limit` / `offset` query-param parsing for offset-paginated
 * handlers.
 *
 * Used by `AccessRequestHandler::listOwnRequests()` and
 * `AccessRequestAdminHandler::listRequests()` (issue #572).
 *
 * `PermissionAdminHandler::parseLimit()` (#623) duplicates the limit-parsing
 * logic but is deliberately left in place — migrating its single private
 * method to this trait is a noise-only refactor. The rule of three says wait
 * for a third pagination site to need this; the trait is here ready when
 * that happens.
 *
 * Both helpers use `ctype_digit` rather than `is_numeric` or `(int)` casts.
 * `ctype_digit` rejects `-1`, `+1`, `1.5`, `1e2`, and any non-ASCII-digit
 * characters; signed integers, decimals, and exponent forms all fail
 * validation. Query params arrive from the wire as strings; leniency hides
 * bugs.
 */
trait OffsetPaginationTrait
{
    /** Default page size when `limit` is absent or empty. */
    private const DEFAULT_LIMIT = 100;

    /** Hard ceiling on `limit`; values above this are 400 errors. */
    private const MAX_LIMIT = 500;

    /**
     * Parse the `limit` query param.
     *
     * Returns DEFAULT_LIMIT when the param is absent or empty; throws
     * ValidationException when present but non-numeric, signed, or out of
     * the [1..MAX_LIMIT] range.
     *
     * @throws ValidationException
     */
    private function parseLimit(mixed $raw): int
    {
        if ($raw === null || $raw === '') {
            return self::DEFAULT_LIMIT;
        }
        if (!is_string($raw) || !ctype_digit($raw)) {
            throw new ValidationException('limit must be a positive integer');
        }
        $limit = (int) $raw;
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new ValidationException(sprintf(
                'limit must be between 1 and %d',
                self::MAX_LIMIT
            ));
        }
        return $limit;
    }

    /**
     * Parse the `offset` query param.
     *
     * Returns 0 when the param is absent or empty; throws
     * ValidationException when present but non-numeric or signed (negatives
     * fail `ctype_digit`).
     *
     * @throws ValidationException
     */
    private function parseOffset(mixed $raw): int
    {
        if ($raw === null || $raw === '') {
            return 0;
        }
        if (!is_string($raw) || !ctype_digit($raw)) {
            throw new ValidationException('offset must be a non-negative integer');
        }
        return (int) $raw;
    }
}
