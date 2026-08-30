<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Support;

/**
 * The WebSocket suites' half of the #922 guard.
 *
 * Three of the four `WebSocket/*` classes drive actions that fan out from the Ratchet
 * handler to the HTTP API, so they probed `API_HOST:API_PORT` with a bare TCP connect and
 * skipped when it was refused. That answers "is anything there?", never "is it ours?" —
 * so a stale container holding the port turned every one of those tests into a failure
 * about liturgical data.
 *
 * `ApiTestCase` carries the same logic for `Routes/*`; these classes extend PHPUnit's
 * `TestCase` directly (they are not HTTP-client tests), which is why the shared part lives
 * in {@see ApiServerPreflight} and only the skip-versus-fail decision lives here.
 */
trait RequiresLiveApiTrait
{
    /**
     * Skip when the API server is absent; fail loudly when something that is not the API
     * holds its port.
     *
     * @param string $why Appended to the skip message: what this class needs the API for.
     */
    protected function requireLiveApi(string $host, int $port, string $why = ''): void
    {
        $preflight = ApiServerPreflight::inspect(
            (string) ( $_ENV['API_PROTOCOL'] ?? 'http' ),
            $host,
            $port
        );

        if ($preflight->isForeign()) {
            $preflight->announceOnce();
            self::fail($preflight->message());
        }

        if (false === $preflight->ok()) {
            $this->markTestSkipped(trim(sprintf(
                'HTTP API not reachable on %s:%d — start it with `composer start`. %s',
                $host,
                $port,
                $why
            )));
        }
    }
}
