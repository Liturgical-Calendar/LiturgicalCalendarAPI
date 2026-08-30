<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services\SourceData;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * `bin/publish-sourcedata-consumer` cannot be run end to end in this environment: it requires
 * `ext-redis` (see the entry point's own guard, which exits 2 and is exercised manually — see
 * the phase 3 task 13 report) and, beyond the extension, a reachable Redis server. Neither is
 * available here, so its wiring to {@see \LiturgicalCalendar\Api\Services\Outbox\RedisStreamConsumer}
 * and {@see \LiturgicalCalendar\Api\Services\SourceData\PublishConsumerLoop} is NOT exercised at
 * runtime by this suite.
 *
 * This is a poor substitute for actually running the consumer against a real stream — a source
 * -text assertion cannot catch a wiring mistake that still happens to mention the right string
 * elsewhere, and proves nothing about `PublishConsumerLoop`'s own behaviour. It exists only
 * because the runtime path is unreachable in CI and in local development without Redis, and it
 * is chosen deliberately narrow: the one thing this test pins is the single most dangerous
 * mistake in this file — passing `RedisStreamConsumer`'s DEFAULT payload field ('row_id',
 * correct for the OpenFGA outbox) instead of this stream's actual field ('batch_id', the only
 * key {@see \LiturgicalCalendar\Api\Services\SourceData\SourceDataPublishNotifier::notify()}
 * writes). Getting that wrong makes every message on this stream look malformed to
 * `RedisStreamConsumer`, which ACKs it away silently — a "bad message" log line and no other
 * symptom — so the consumer would run forever, waking on every notification and doing nothing
 * with any of them, while the cron backstop quietly did all the real work and nothing ever
 * surfaced the difference.
 */
#[CoversNothing]
final class PublishSourceDataConsumerScriptTest extends TestCase
{
    public function testTheConsumerEntryPointReadsTheBatchIdField(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../bin/publish-sourcedata-consumer');
        self::assertIsString($source);

        self::assertStringContainsString(
            "'batch_id'",
            $source,
            "bin/publish-sourcedata-consumer must pass 'batch_id' as RedisStreamConsumer's payload field; "
                . "the default is 'row_id', which would make every message look malformed and be ACKed away"
        );
    }
}
