<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\Outbox;

use LiturgicalCalendar\Api\Services\Exception\OpenFgaApiException;
use LiturgicalCalendar\Api\Services\Exception\TupleAlreadyExistsException;
use LiturgicalCalendar\Api\Services\Exception\TupleNotFoundException;

/**
 * Stateless classifier that decides what to do with an exception raised
 * by an OpenFGA call inside OutboxProcessor.
 *
 * The mapping is canonical — every branch in OutboxProcessor consults
 * this. New OpenFGA error codes get a new test in OutboxClassifierTest
 * and a new arm in classify().
 */
final class OutboxClassifier
{
    /**
     * Error codes we recognize as admin-input bugs (no retry).
     *
     * Validation errors, type/relation lookups failing, auth failures —
     * retrying these 9 more times wastes work and pollutes metrics.
     *
     * @var list<string>
     */
    private const TERMINAL_CODES = [
        'validation_error',
        'invalid_input_format',
        'type_not_found',
        'relation_not_found',
        'auth_failure',
        'unauthenticated',
    ];

    private function __construct()
    {
    }

    public static function classify(\Throwable $e): OutboxDisposition
    {
        if ($e instanceof TupleAlreadyExistsException || $e instanceof TupleNotFoundException) {
            return OutboxDisposition::BENIGN_SUCCESS;
        }

        if ($e instanceof OpenFgaApiException) {
            $code = $e->getErrorCode();
            if ($code !== null && in_array($code, self::TERMINAL_CODES, true)) {
                return OutboxDisposition::TERMINAL;
            }
            return OutboxDisposition::RETRY;
        }

        return OutboxDisposition::RETRY;
    }
}
