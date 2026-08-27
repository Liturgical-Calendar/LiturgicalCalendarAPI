<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Support;

use LiturgicalCalendar\Api\Services\JwtServiceFactory;

/**
 * A minted access token for the tests that drive the WebSocket server over the wire.
 *
 * Those tests reach `Health` through a real handshake, so — since #894 — they need a real credential
 * on it. The legacy HS256 flavour is used rather than a Zitadel token because the API owns the secret
 * and can therefore mint one locally: an RS256 token would need a live provider, which would make a
 * unit-test suite depend on Zitadel being up.
 *
 * **Skips loudly rather than passing unexecuted.** A fresh worktree has no `.env.local`, so
 * `JWT_SECRET` is absent and no token can be minted. A suite that quietly stopped exercising the
 * server would still report green, which is worse than a red one: the test was written to guard
 * something, and silence is indistinguishable from success.
 */
trait WsAuthTrait
{
    /**
     * @param array<int, string> $roles
     */
    protected function wsAccessTokenOrSkip(array $roles = ['admin']): string
    {
        try {
            $jwtService = JwtServiceFactory::fromEnv();
        } catch (\Throwable $e) {
            $this->markTestSkipped(
                'JWT_SECRET is not configured, so no WebSocket credential can be minted: ' . $e->getMessage()
            );
        }

        return $jwtService->generate('phpunit', ['roles' => $roles]);
    }
}
