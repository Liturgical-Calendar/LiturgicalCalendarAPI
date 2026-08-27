<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services;

use GuzzleHttp\Psr7\Request;
use LiturgicalCalendar\Api\Services\JwtService;
use LiturgicalCalendar\Api\Services\WsCallerResolver;
use LiturgicalCalendar\Api\Services\ZitadelKeySetFactory;
use PHPUnit\Framework\TestCase;

/**
 * What the resolver does when Zitadel is configured but unreachable — #894.
 *
 * This is the failure mode most likely to be met in anger, and the one whose wrong answer would be
 * worst: a WebSocket server that treated "cannot reach the identity provider" as "no opinion, let
 * them through" would hand the whole gate to anyone who could take Zitadel offline. Every assertion
 * here is a statement that the degradation is *closed*, and that a path that still works keeps
 * working.
 *
 * The issuer is a port nothing listens on, so the JWKS fetch fails instantly rather than by timeout.
 */
final class WsCallerResolverZitadelTest extends TestCase
{
    private const SECRET = 'kFj9wQz2Lm7XpR4tVbN8sHc1YdG6aE0u';

    /** Nothing listens here; connection is refused immediately. */
    private const DEAD_ISSUER = 'http://127.0.0.1:1';

    protected function tearDown(): void
    {
        // The factory memoizes per issuer for the life of the process. Left in place, this file's
        // fake issuer would be handed to any later test that happened to name it.
        ZitadelKeySetFactory::reset();
        parent::tearDown();
    }

    /**
     * @param array<int, string> $roles
     */
    private function hs256Token(string $sub, array $roles): string
    {
        return ( new JwtService(self::SECRET) )->generate($sub, ['roles' => $roles]);
    }

    public function testAnUnreachableProviderDoesNotBreakTheLegacyPath(): void
    {
        $resolver = new WsCallerResolver(new JwtService(self::SECRET), self::DEAD_ISSUER, null);
        $request  = new Request('GET', '/', [
            'Cookie' => 'litcal_access_token=' . $this->hs256Token('someone', ['admin']),
        ]);

        $caller = $resolver->fromHandshake($request);

        $this->assertTrue($caller->authenticated, 'the Zitadel attempt must fall through, not abort');
        $this->assertSame(['admin'], $caller->roles);
    }

    public function testAnUnreachableProviderWithNoLegacyPathIsAnonymous(): void
    {
        $resolver = new WsCallerResolver(null, self::DEAD_ISSUER, null);
        $request  = new Request('GET', '/', [
            'Cookie' => 'litcal_access_token=' . $this->hs256Token('someone', ['admin']),
        ]);

        $this->assertFalse(
            $resolver->fromHandshake($request)->authenticated,
            'no verification path can reach a verdict, so the caller is anonymous'
        );
    }

    public function testWarmingReportsFailureWithoutThrowing(): void
    {
        $resolver = new WsCallerResolver(new JwtService(self::SECRET), self::DEAD_ISSUER, null);

        $this->assertFalse($resolver->warmKeySet(), 'an unreachable JWKS is reported, not raised');
    }

    public function testBothPathsAreNamedWhenBothAreConfigured(): void
    {
        $resolver = new WsCallerResolver(new JwtService(self::SECRET), self::DEAD_ISSUER, null);

        $described = $resolver->describeAvailablePaths();

        $this->assertStringContainsString('Zitadel RS256', $described);
        $this->assertStringContainsString('legacy HS256', $described);
    }

    public function testOnlyTheZitadelPathIsNamedWhenTheLegacySecretIsAbsent(): void
    {
        $resolver = new WsCallerResolver(null, self::DEAD_ISSUER, null);

        $described = $resolver->describeAvailablePaths();

        $this->assertStringContainsString('Zitadel RS256', $described);
        $this->assertStringNotContainsString('HS256', $described);
    }

    public function testTheInternalUrlVariantIsAcceptedForDockerNetworking(): void
    {
        $resolver = new WsCallerResolver(null, 'https://zitadel.example.test', 'http://zitadel:8080');

        // The Host-header rewrite path is exercised on the way to a failed fetch; what matters is
        // that it is reached without throwing and still refuses.
        $this->assertFalse($resolver->warmKeySet());
        $this->assertFalse($resolver->fromToken('whatever')->authenticated);
    }
}
