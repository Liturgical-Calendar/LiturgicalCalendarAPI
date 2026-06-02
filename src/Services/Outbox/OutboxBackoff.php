<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\Outbox;

/**
 * Exponential backoff for outbox retries.
 *
 * Schedule: 1s, 2s, 4s, 8s, 16s, 32s, 64s, 128s, 256s, 512s — capped at
 * 2^9 = 512s. Total budget across 10 attempts: ~17 minutes.
 *
 * Pure function. Lives in its own file for testability and so the
 * schedule is editable in one place without touching the processor.
 */
final class OutboxBackoff
{
    private function __construct()
    {
    }

    /**
     * @param int $attempts The new attempt count (just incremented), 1..n.
     */
    public static function secondsForAttempt(int $attempts): int
    {
        if ($attempts < 1) {
            throw new \InvalidArgumentException('attempts must be >= 1');
        }

        return 1 << min($attempts - 1, 9);
    }
}
