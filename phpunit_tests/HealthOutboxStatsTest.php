<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests;

use LiturgicalCalendar\Api\Health;
use LiturgicalCalendar\Api\Repositories\OutboxRepository;
use LiturgicalCalendar\Api\Services\Outbox\OutboxOperation;
use LiturgicalCalendar\Tests\Repositories\RepositoryTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Targeted coverage for Health::buildOutboxStats, the static helper that
 * powers the /health endpoint's openfga_outbox block. The Redis side of
 * the helper is exercised live (best-effort — relies on ext-redis being
 * loaded); the PG side is exercised against real rows.
 */
#[CoversClass(Health::class)]
final class HealthOutboxStatsTest extends RepositoryTestCase
{
    public function testStatsReportsZeroOnEmptyOutbox(): void
    {
        $stats = Health::buildOutboxStats();

        self::assertSame(0, $stats['pending']);
        self::assertSame(0, $stats['retrying']);
        self::assertSame(0, $stats['succeeded']);
        self::assertSame(0, $stats['failed_terminal']);
        self::assertSame(0, $stats['oldest_pending_age_seconds']);

        self::assertArrayHasKey('consumer', $stats);
        self::assertSame('litcal:reconcile-stream', $stats['consumer']['stream_name']);
        self::assertSame('reconciler', $stats['consumer']['group_name']);
    }

    public function testStatsBucketsRowsPerStatusAndReportsOldestAge(): void
    {
        self::assertNotNull(self::$pdo);
        $repo = new OutboxRepository(self::$pdo);

        $ids = $repo->insertBatch([
            [
                'operation'       => OutboxOperation::WRITE_TUPLE,
                'fga_user'        => 'user:a',
                'fga_relation'    => 'editor',
                'fga_object'      => 'national_calendar:IT',
                'idempotency_key' => 'h1-' . bin2hex(random_bytes(4)),
                'metadata'        => [],
            ],
            [
                'operation'       => OutboxOperation::WRITE_TUPLE,
                'fga_user'        => 'user:b',
                'fga_relation'    => 'editor',
                'fga_object'      => 'national_calendar:US',
                'idempotency_key' => 'h2-' . bin2hex(random_bytes(4)),
                'metadata'        => [],
            ],
            [
                'operation'       => OutboxOperation::WRITE_TUPLE,
                'fga_user'        => 'user:c',
                'fga_relation'    => 'editor',
                'fga_object'      => 'national_calendar:FR',
                'idempotency_key' => 'h3-' . bin2hex(random_bytes(4)),
                'metadata'        => [],
            ],
        ]);
        // One stays pending, one becomes succeeded, one becomes failed_terminal.
        $repo->markSucceeded($ids[1]);
        $repo->markFailedTerminal($ids[2], 'validation_error', 'validation_error');

        $stats = Health::buildOutboxStats();

        self::assertSame(1, $stats['pending']);
        self::assertSame(1, $stats['succeeded']);
        self::assertSame(1, $stats['failed_terminal']);
        // oldest_pending_age_seconds is computed from created_at, which the
        // migration default-sets to NOW(). Brand-new rows are < 1s old; an
        // age value of 0 or 1 is both acceptable.
        self::assertGreaterThanOrEqual(0, $stats['oldest_pending_age_seconds']);
        self::assertLessThanOrEqual(5, $stats['oldest_pending_age_seconds'], 'fresh rows must show a small age');
    }

    public function testStatsConsumerStreamNameHonorsEnv(): void
    {
        $originalStream = $_ENV['REDIS_OUTBOX_STREAM'] ?? null;
        $originalGroup  = $_ENV['REDIS_OUTBOX_GROUP'] ?? null;
        try {
            $_ENV['REDIS_OUTBOX_STREAM'] = 'custom:stream:name';
            $_ENV['REDIS_OUTBOX_GROUP']  = 'custom-group';

            $stats = Health::buildOutboxStats();

            self::assertSame('custom:stream:name', $stats['consumer']['stream_name']);
            self::assertSame('custom-group', $stats['consumer']['group_name']);
        } finally {
            if ($originalStream === null) {
                unset($_ENV['REDIS_OUTBOX_STREAM']);
            } else {
                $_ENV['REDIS_OUTBOX_STREAM'] = $originalStream;
            }
            if ($originalGroup === null) {
                unset($_ENV['REDIS_OUTBOX_GROUP']);
            } else {
                $_ENV['REDIS_OUTBOX_GROUP'] = $originalGroup;
            }
        }
    }
}
