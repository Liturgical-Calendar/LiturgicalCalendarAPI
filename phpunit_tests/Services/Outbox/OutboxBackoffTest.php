<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services\Outbox;

use LiturgicalCalendar\Api\Services\Outbox\OutboxBackoff;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(OutboxBackoff::class)]
final class OutboxBackoffTest extends TestCase
{
    /**
     * @return iterable<string, array{int, int}>
     */
    public static function backoffCases(): iterable
    {
        yield 'attempt 1 → 1s'   => [1, 1];
        yield 'attempt 2 → 2s'   => [2, 2];
        yield 'attempt 3 → 4s'   => [3, 4];
        yield 'attempt 4 → 8s'   => [4, 8];
        yield 'attempt 5 → 16s'  => [5, 16];
        yield 'attempt 6 → 32s'  => [6, 32];
        yield 'attempt 7 → 64s'  => [7, 64];
        yield 'attempt 8 → 128s' => [8, 128];
        yield 'attempt 9 → 256s' => [9, 256];
        yield 'attempt 10 → 512s' => [10, 512];
        yield 'attempts past cap stay at 512s' => [99, 512];
    }

    #[DataProvider('backoffCases')]
    public function testSecondsForAttempt(int $attempts, int $expectedSeconds): void
    {
        self::assertSame($expectedSeconds, OutboxBackoff::secondsForAttempt($attempts));
    }

    public function testZeroAttemptsThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        OutboxBackoff::secondsForAttempt(0);
    }

    public function testNegativeAttemptsThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        OutboxBackoff::secondsForAttempt(-1);
    }
}
