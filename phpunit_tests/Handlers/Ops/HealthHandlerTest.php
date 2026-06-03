<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers\Ops;

use LiturgicalCalendar\Api\Handlers\Ops\HealthHandler;
use LiturgicalCalendar\Api\Http\Exception\MethodNotAllowedException;
use LiturgicalCalendar\Tests\Handlers\AbstractHandlerTestCase;
use LiturgicalCalendar\Tests\Support\EnvIsolationTrait;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(HealthHandler::class)]
final class HealthHandlerTest extends AbstractHandlerTestCase
{
    use EnvIsolationTrait;

    /**
     * The endpoint hits openfga_outbox; we want the test DB so the count
     * branch in buildOutboxStats() is exercised.
     */
    protected static bool $requiresDatabase = true;

    public function testGetReturns200WithExpectedShape(): void
    {
        $response = ( new HealthHandler() )->handle(
            $this->requestFor('GET', '/health', [], [])
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json; charset=utf-8', $response->getHeaderLine('Content-Type'));

        $body = $this->decodeJsonBody($response);
        self::assertSame('ok', $body['status']);
        self::assertSame('reachable', $body['database']);

        self::assertArrayHasKey('openfga_outbox', $body);
        $outbox = $body['openfga_outbox'];
        self::assertSame(0, $outbox['pending']);
        self::assertSame(0, $outbox['retrying']);
        self::assertSame(0, $outbox['succeeded']);
        self::assertSame(0, $outbox['failed_terminal']);
        self::assertSame(0, $outbox['oldest_pending_age_seconds']);
        self::assertArrayHasKey('consumer', $outbox);

        $consumer = $outbox['consumer'];
        self::assertArrayHasKey('redis_reachable', $consumer);
        self::assertIsBool($consumer['redis_reachable']);
        self::assertSame('litcal:reconcile-stream', $consumer['stream_name']);
        self::assertSame('reconciler', $consumer['group_name']);
        self::assertIsInt($consumer['pending_entries']);
        self::assertIsInt($consumer['oldest_pel_idle_seconds']);
    }

    public function testGetReturnsNotConfiguredWhenDbEnvAbsent(): void
    {
        // Clear DB_* env vars so Connection::isConfigured() returns false.
        $response = $this->withoutEnv(
            ['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD'],
            fn() => ( new HealthHandler() )->handle(
                $this->requestFor('GET', '/health', [], [])
            )
        );

        $body = $this->decodeJsonBody($response);
        self::assertSame('ok', $body['status'], 'no DB configured is not a degraded state');
        self::assertSame('not_configured', $body['database']);
        // The outbox block still ships with zero counts (no DB to read).
        self::assertSame(0, $body['openfga_outbox']['pending']);
    }

    public function testPostReturns405(): void
    {
        $this->expectException(MethodNotAllowedException::class);

        ( new HealthHandler() )->handle(
            $this->requestFor('POST', '/health', [], [])
        );
    }
}
