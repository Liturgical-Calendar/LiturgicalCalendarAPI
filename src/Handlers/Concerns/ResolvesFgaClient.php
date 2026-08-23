<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Handlers\Concerns;

use LiturgicalCalendar\Api\Services\OpenFgaClient;

/**
 * Lazy, memoized access to the OpenFGA client, shared by every handler that
 * consults authorization relations.
 *
 * Six handlers used to carry a byte-identical copy of this pair, and
 * {@see ResolvesOutboxTooling} carried a seventh variant that rebuilt the
 * client — and therefore its Guzzle connection pool — on every call. One copy
 * means one place to change the construction, the memoization, or the
 * "configured?" predicate.
 *
 * **Test seam.** `$fgaClient` is populated by the using handler's constructor
 * from its optional `?OpenFgaClient` parameter, which is how the handler tests
 * inject a `MockHandler`-backed client without touching the environment. A
 * handler that has no such constructor parameter simply never sets it and
 * always takes the `fromEnv()` path.
 */
trait ResolvesFgaClient
{
    /**
     * Injected by the constructor (tests) or built lazily on first use
     * (production). Never rebuilt once resolved, so a fan-out of relation
     * lookups reuses one keep-alive connection.
     */
    private ?OpenFgaClient $fgaClient = null;

    /**
     * Whether a client can be obtained at all — either one was injected, or the
     * environment carries the store/model/URL triple `fromEnv()` needs.
     *
     * Callers use this to skip the OpenFGA leg entirely and fall back to their
     * fail-closed result, rather than letting `getFgaClient()` throw.
     */
    private function isFgaClientAvailable(): bool
    {
        return $this->fgaClient !== null || OpenFgaClient::isConfigured();
    }

    /**
     * The memoized client, built from the environment on first use.
     *
     * @throws \RuntimeException If OpenFGA is not configured. Guard with
     *                           {@see isFgaClientAvailable()} first.
     */
    private function getFgaClient(): OpenFgaClient
    {
        if ($this->fgaClient === null) {
            $this->fgaClient = OpenFgaClient::fromEnv();
        }

        return $this->fgaClient;
    }
}
