<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services;

use GuzzleHttp\Psr7\Request;
use LiturgicalCalendar\Api\Services\JwtService;
use LiturgicalCalendar\Api\Services\WsCallerResolver;
use LiturgicalCalendar\Tests\Support\EnvIsolationTrait;
use PHPUnit\Framework\TestCase;

final class WsCallerResolverTest extends TestCase
{
    use EnvIsolationTrait;

    /**
     * Deliberately free of the words JwtServiceFactory treats as placeholders — this is handed to
     * JwtService directly, but a secret that would be rejected by the factory reads as a mistake.
     */
    private const SECRET = 'kFj9wQz2Lm7XpR4tVbN8sHc1YdG6aE0u';

    private function resolver(): WsCallerResolver
    {
        return new WsCallerResolver(new JwtService(self::SECRET), null, null);
    }

    /**
     * @param array<int, string> $roles
     */
    private function tokenFor(string $sub, array $roles): string
    {
        return ( new JwtService(self::SECRET) )->generate($sub, ['roles' => $roles]);
    }

    public function testNoRequestIsAnonymous(): void
    {
        $this->assertFalse($this->resolver()->fromHandshake(null)->authenticated);
    }

    public function testNoCookieHeaderIsAnonymous(): void
    {
        $this->assertFalse($this->resolver()->fromHandshake(new Request('GET', '/'))->authenticated);
    }

    public function testLegacyHs256CookieIsAuthenticatedWithItsRoles(): void
    {
        $token   = $this->tokenFor('someone', ['test_editor']);
        $request = new Request('GET', '/', ['Cookie' => 'litcal_access_token=' . $token]);

        $caller = $this->resolver()->fromHandshake($request);

        $this->assertTrue($caller->authenticated);
        $this->assertSame('someone', $caller->sub);
        $this->assertSame(['test_editor'], $caller->roles);
    }

    public function testCookieIsFoundAmongOthers(): void
    {
        $token   = $this->tokenFor('someone', ['admin']);
        $request = new Request('GET', '/', [
            'Cookie' => 'other=1; litcal_access_token=' . $token . '; litcal_id_token=zzz',
        ]);

        $this->assertTrue($this->resolver()->fromHandshake($request)->authenticated);
    }

    public function testGarbageTokenIsAnonymousRatherThanAnError(): void
    {
        $request = new Request('GET', '/', ['Cookie' => 'litcal_access_token=not.a.jwt']);
        $this->assertFalse($this->resolver()->fromHandshake($request)->authenticated);
    }

    public function testTokenSignedWithAnotherSecretIsAnonymous(): void
    {
        $forged  = ( new JwtService('Zx3mQ8vT1kL6yW9pB4nR7cJ2hD5sF0gA') )->generate('someone', ['roles' => ['admin']]);
        $request = new Request('GET', '/', ['Cookie' => 'litcal_access_token=' . $forged]);

        $this->assertFalse($this->resolver()->fromHandshake($request)->authenticated);
    }

    public function testExpiredTokenIsAnonymous(): void
    {
        $expired = ( new JwtService(self::SECRET, 'HS256', -10) )->generate('someone', ['roles' => ['admin']]);
        $request = new Request('GET', '/', ['Cookie' => 'litcal_access_token=' . $expired]);

        $this->assertFalse($this->resolver()->fromHandshake($request)->authenticated);
    }

    public function testTokenWithoutRolesClaimAuthenticatesWithNoRoles(): void
    {
        $token  = ( new JwtService(self::SECRET) )->generate('someone');
        $caller = $this->resolver()->fromToken($token);

        $this->assertTrue($caller->authenticated);
        $this->assertSame([], $caller->roles);
    }

    public function testMalformedCookieHeaderDoesNotThrow(): void
    {
        $request = new Request('GET', '/', ['Cookie' => '=; ;;  ; nonsense']);
        $this->assertFalse($this->resolver()->fromHandshake($request)->authenticated);
    }

    public function testCookieValuesAreUrlDecoded(): void
    {
        $parsed = WsCallerResolver::parseCookieHeader('a=one%20two');
        $this->assertSame('one two', $parsed['a']);
    }

    public function testResolverWithNoVerificationPathAtAllIsAnonymous(): void
    {
        $this->withoutEnv(['JWT_SECRET', 'ZITADEL_ISSUER', 'ZITADEL_INTERNAL_URL'], function (): void {
            $resolver = WsCallerResolver::fromEnv();
            $request  = new Request('GET', '/', ['Cookie' => 'litcal_access_token=anything']);

            $this->assertFalse($resolver->fromHandshake($request)->authenticated);
            $this->assertStringContainsString('none', strtolower($resolver->describeAvailablePaths()));
            $this->assertFalse($resolver->warmKeySet(), 'nothing to warm without an issuer');
        });
    }

    public function testDescribeNamesTheLegacyPathWhenOnlyItIsConfigured(): void
    {
        $this->assertStringContainsString('HS256', $this->resolver()->describeAvailablePaths());
    }
}
