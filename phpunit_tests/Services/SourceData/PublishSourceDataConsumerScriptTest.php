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
 * This is a poor substitute for actually running the consumer against a real stream — it proves
 * nothing about `PublishConsumerLoop`'s own behaviour, and exists only because the runtime path
 * is unreachable in CI and in local development without Redis. It is chosen deliberately narrow:
 * the one thing this test pins is the single most dangerous mistake in this file — passing
 * `RedisStreamConsumer`'s DEFAULT payload field ('row_id', correct for the OpenFGA outbox)
 * instead of this stream's actual field ('batch_id', the only key
 * {@see \LiturgicalCalendar\Api\Services\SourceData\SourceDataPublishNotifier::notify()}
 * writes). Getting that wrong makes every message on this stream look malformed to
 * `RedisStreamConsumer`, which ACKs it away silently — a "bad message" log line and no other
 * symptom — so the consumer would run forever, waking on every notification and doing nothing
 * with any of them, while the cron backstop quietly did all the real work and nothing ever
 * surfaced the difference.
 *
 * A plain `assertStringContainsString("'batch_id'", $source)` is NOT enough to pin that: the
 * entry point's own explanatory comment immediately above the `RedisStreamConsumer` construction
 * also contains the literal `'batch_id'` (it has to, to explain what the argument is for), so
 * that assertion would keep passing even if the real sixth argument regressed to `'row_id'` and
 * the comment above it were left behind, unedited — comments rot independently of code. This was
 * found during review of this feature (both occurrences existed in the same file at the same
 * time) and closed by anchoring the regex to the `new RedisStreamConsumer(...)` call's own
 * argument list instead of the whole file — see the test method's own docblock.
 */
#[CoversNothing]
final class PublishSourceDataConsumerScriptTest extends TestCase
{
    /**
     * Anchored to the `new RedisStreamConsumer(...)` CALL, not merely to the file containing the
     * string `'batch_id'` somewhere. The file's own explanatory comment immediately above that
     * call also contains the literal `'batch_id'` (it has to, to explain what the argument does)
     * — so a plain `assertStringContainsString("'batch_id'", $source)` would keep passing even
     * if the real sixth argument regressed to `'row_id'` and the comment above it were simply
     * left behind, unedited. That is not a hypothetical: it is exactly what this file looked
     * like when this test was first written, and the review that caught it is why this regex is
     * scoped to the constructor call's own argument list instead of the whole source string.
     */
    public function testTheConsumerEntryPointReadsTheBatchIdField(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../bin/publish-sourcedata-consumer');
        self::assertIsString($source);

        self::assertMatchesRegularExpression(
            '/new\s+RedisStreamConsumer\([^)]*\'batch_id\'\s*\)/s',
            $source,
            "bin/publish-sourcedata-consumer's `new RedisStreamConsumer(...)` call must pass "
                . "'batch_id' as the payload field argument; the class default is 'row_id' "
                . '(correct for the OpenFGA outbox, wrong here), and getting this wrong makes '
                . 'every message on this stream look malformed and be ACKed away silently — a '
                . '"bad message" log line and no other symptom'
        );
    }
}
