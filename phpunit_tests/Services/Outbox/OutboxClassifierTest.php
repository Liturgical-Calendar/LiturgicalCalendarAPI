<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services\Outbox;

use LiturgicalCalendar\Api\Services\Exception\OpenFgaApiException;
use LiturgicalCalendar\Api\Services\Exception\TupleAlreadyExistsException;
use LiturgicalCalendar\Api\Services\Exception\TupleNotFoundException;
use LiturgicalCalendar\Api\Services\Outbox\OutboxClassifier;
use LiturgicalCalendar\Api\Services\Outbox\OutboxDisposition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OutboxClassifier::class)]
final class OutboxClassifierTest extends TestCase
{
    public function testTupleAlreadyExistsIsBenign(): void
    {
        $e = new TupleAlreadyExistsException('already exists', 400, 'cannot_allow_duplicate_tuple');
        self::assertSame(OutboxDisposition::BENIGN_SUCCESS, OutboxClassifier::classify($e));
    }

    public function testTupleNotFoundIsBenign(): void
    {
        $e = new TupleNotFoundException('not found', 400, 'cannot_allow_unknown_tuple_to_be_deleted');
        self::assertSame(OutboxDisposition::BENIGN_SUCCESS, OutboxClassifier::classify($e));
    }

    public function testValidationErrorIsTerminal(): void
    {
        $e = new OpenFgaApiException('invalid input', 400, 'validation_error');
        self::assertSame(OutboxDisposition::TERMINAL, OutboxClassifier::classify($e));
    }

    public function testInvalidInputFormatIsTerminal(): void
    {
        $e = new OpenFgaApiException('bad format', 400, 'invalid_input_format');
        self::assertSame(OutboxDisposition::TERMINAL, OutboxClassifier::classify($e));
    }

    public function testTypeNotFoundIsTerminal(): void
    {
        $e = new OpenFgaApiException('no such type', 400, 'type_not_found');
        self::assertSame(OutboxDisposition::TERMINAL, OutboxClassifier::classify($e));
    }

    public function testRelationNotFoundIsTerminal(): void
    {
        $e = new OpenFgaApiException('no such relation', 400, 'relation_not_found');
        self::assertSame(OutboxDisposition::TERMINAL, OutboxClassifier::classify($e));
    }

    public function testAuthFailureIsTerminal(): void
    {
        $e = new OpenFgaApiException('auth failure', 401, 'auth_failure');
        self::assertSame(OutboxDisposition::TERMINAL, OutboxClassifier::classify($e));
    }

    public function testUnauthenticatedIsTerminal(): void
    {
        $e = new OpenFgaApiException('unauthenticated', 401, 'unauthenticated');
        self::assertSame(OutboxDisposition::TERMINAL, OutboxClassifier::classify($e));
    }

    public function testRateLimitedIsRetry(): void
    {
        $e = new OpenFgaApiException('rate-limited', 429, null);
        self::assertSame(OutboxDisposition::RETRY, OutboxClassifier::classify($e));
    }

    public function test500IsRetry(): void
    {
        $e = new OpenFgaApiException('server error', 500, null);
        self::assertSame(OutboxDisposition::RETRY, OutboxClassifier::classify($e));
    }

    public function test503IsRetry(): void
    {
        $e = new OpenFgaApiException('unavailable', 503, null);
        self::assertSame(OutboxDisposition::RETRY, OutboxClassifier::classify($e));
    }

    public function testGenericRuntimeExceptionIsRetry(): void
    {
        // Network errors surface as \RuntimeException (Guzzle ConnectException) or similar.
        $e = new \RuntimeException('connection refused');
        self::assertSame(OutboxDisposition::RETRY, OutboxClassifier::classify($e));
    }

    public function testUnknownErrorCodeIsRetry(): void
    {
        // Safe default: anything we don't recognize gets retried.
        $e = new OpenFgaApiException('mystery', 418, 'i_am_a_teapot');
        self::assertSame(OutboxDisposition::RETRY, OutboxClassifier::classify($e));
    }
}
