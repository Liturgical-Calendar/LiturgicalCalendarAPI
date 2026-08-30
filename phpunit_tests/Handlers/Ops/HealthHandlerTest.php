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

    /**
     * Both source-data blocks must actually reach the HTTP response.
     *
     * `Health::buildSourceDataWriteModeStatus()` and `buildSourceDataPublisherStatus()` have
     * their own unit tests, but those exercise the builders, not the wiring. A block that is
     * built and never surfaced is worse than one that is missing: the operator procedure for
     * registering the GitHub App ends with "confirm /health reports the publisher configured",
     * which would silently have nothing to confirm.
     */
    public function testGetSurfacesBothSourceDataBlocks(): void
    {
        $response = ( new HealthHandler() )->handle(
            $this->requestFor('GET', '/health', [], [])
        );

        $body = $this->decodeJsonBody($response);

        foreach (['source_data_writes', 'source_data_publisher'] as $block) {
            self::assertArrayHasKey($block, $body, $block . ' must be reachable over HTTP');
            self::assertIsArray($body[$block]);
            /** @var array<string, mixed> $status */
            $status = $body[$block];
            self::assertArrayHasKey('status', $status);
            self::assertContains($status['status'], ['ok', 'warning'], $block . ' reports an unexpected status');
            self::assertArrayHasKey('message', $status);
            self::assertIsString($status['message']);
            self::assertNotSame('', $status['message'], $block . ' must explain itself, not just flag a state');
        }
    }

    /**
     * The locale_readiness block must reach the HTTP response, and a `warning` in it must
     * NOT change the top-level status or the HTTP code.
     *
     * Both halves matter. A block that is built and never surfaced is worse than a missing
     * one — the promotion procedure ends with "confirm /health reports every official locale
     * ready", which would have nothing to confirm. And the non-escalation is the documented
     * contract for every nested block here (change-request runbook, "Read the nested block,
     * not the top level"): a future change that escalated it would start pulling instances
     * out of load-balancer pools over a content defect.
     */
    public function testGetSurfacesTheLocaleReadinessBlock(): void
    {
        $response = ( new HealthHandler() )->handle(
            $this->requestFor('GET', '/health', [], [])
        );

        $body = $this->decodeJsonBody($response);

        self::assertArrayHasKey('locale_readiness', $body, 'the block must be reachable over HTTP');
        /** @var array<string, mixed> $readiness */
        $readiness = $body['locale_readiness'];

        self::assertContains($readiness['status'], ['ok', 'warning']);
        self::assertIsString($readiness['message']);
        self::assertNotSame('', $readiness['message'], 'the block must explain itself, not just flag a state');
        self::assertIsArray($readiness['official']);
        self::assertNotEmpty($readiness['official']);
        self::assertIsArray($readiness['not_ready']);
        self::assertIsArray($readiness['advisories']);

        // Only the database probe may degrade the endpoint itself.
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('ok', $body['status']);
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
