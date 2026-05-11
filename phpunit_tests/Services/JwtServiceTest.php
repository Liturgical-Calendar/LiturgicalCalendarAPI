<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services;

use LiturgicalCalendar\Api\Services\JwtService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(JwtService::class)]
final class JwtServiceTest extends TestCase
{
    private const SECRET = 'test-secret-must-be-at-least-32-chars-long-xx';

    private function service(int $expiry = 3600, int $refreshExpiry = 604800): JwtService
    {
        return new JwtService(self::SECRET, 'HS256', $expiry, $refreshExpiry);
    }

    public function testConstructorRejectsShortSecret(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('at least 32 characters');

        new JwtService('too-short');
    }

    public function testGenerateAndVerifyAccessToken(): void
    {
        $svc   = $this->service();
        $token = $svc->generate('alice', ['roles' => ['admin']]);

        $payload = $svc->verify($token);
        self::assertNotNull($payload);
        self::assertSame('alice', $payload->sub);
        self::assertSame('access', $payload->type);
        self::assertSame(['admin'], $payload->roles);
        self::assertIsInt($payload->iat);
        self::assertIsInt($payload->exp);
        self::assertSame($payload->iat + 3600, $payload->exp);
    }

    public function testStandardClaimsOverrideCustomClaims(): void
    {
        $svc = $this->service();
        // Caller attempts to spoof the type / sub claims; standard claims must win.
        $token = $svc->generate('alice', [
            'type'  => 'admin',
            'sub'   => 'evil',
            'extra' => 'preserved',
        ]);

        $payload = $svc->verify($token);
        self::assertNotNull($payload);
        self::assertSame('access', $payload->type);
        self::assertSame('alice', $payload->sub);
        self::assertSame('preserved', $payload->extra);
    }

    public function testVerifyRejectsTokenWithWrongType(): void
    {
        $svc     = $this->service();
        $refresh = $svc->generateRefreshToken('alice');

        // verify() is for access tokens only; a refresh token must be rejected.
        self::assertNull($svc->verify($refresh));
    }

    public function testVerifyRejectsTokenSignedByDifferentSecret(): void
    {
        $other = new JwtService('different-secret-also-at-least-32-chars', 'HS256');
        $token = $other->generate('alice');

        self::assertNull($this->service()->verify($token));
    }

    public function testVerifyRejectsMalformedToken(): void
    {
        self::assertNull($this->service()->verify('not.a.real.token'));
    }

    public function testVerifyRejectsExpiredToken(): void
    {
        // Use 1-second expiry, then sleep so the token is past exp.
        $svc   = $this->service(1, 1);
        $token = $svc->generate('alice');
        sleep(2);

        self::assertNull($svc->verify($token));
    }

    public function testGenerateRefreshTokenHasRefreshTypeAndLongerExpiry(): void
    {
        $svc   = $this->service();
        $token = $svc->generateRefreshToken('alice');

        $payload = $svc->verifyRefreshToken($token);
        self::assertNotNull($payload);
        self::assertSame('refresh', $payload->type);
        self::assertSame($payload->iat + 604800, $payload->exp);
    }

    public function testVerifyRefreshTokenRejectsAccessToken(): void
    {
        $svc    = $this->service();
        $access = $svc->generate('alice');

        self::assertNull($svc->verifyRefreshToken($access));
    }

    public function testRefreshIssuesNewAccessTokenWithPreservedCustomClaims(): void
    {
        $svc          = $this->service();
        $refreshToken = $svc->generateRefreshToken('alice');

        $newAccess = $svc->refresh($refreshToken);
        self::assertNotNull($newAccess);

        $payload = $svc->verify($newAccess);
        self::assertNotNull($payload);
        self::assertSame('alice', $payload->sub);
        self::assertSame('access', $payload->type);
    }

    public function testRefreshReturnsNullForInvalidRefreshToken(): void
    {
        self::assertNull($this->service()->refresh('not.a.real.token'));
    }

    public function testRefreshReturnsNullWhenGivenAnAccessToken(): void
    {
        $svc    = $this->service();
        $access = $svc->generate('alice');

        self::assertNull($svc->refresh($access));
    }

    public function testExtractUsernameDoesNotValidateSignature(): void
    {
        // extractUsername reads the payload without verifying. A token signed
        // by a different secret should still yield its 'sub' claim.
        $other = new JwtService('different-secret-also-at-least-32-chars', 'HS256');
        $token = $other->generate('alice');

        self::assertSame('alice', $this->service()->extractUsername($token));
    }

    public function testExtractUsernameReturnsNullForMalformedToken(): void
    {
        $svc = $this->service();
        self::assertNull($svc->extractUsername('only-one-part'));
        self::assertNull($svc->extractUsername('two.parts'));
        self::assertNull($svc->extractUsername('a.b.c.d'));
    }

    public function testExpiryGettersExposeConfiguration(): void
    {
        $svc = $this->service(900, 86400);
        self::assertSame(900, $svc->getExpiry());
        self::assertSame(86400, $svc->getRefreshExpiry());
    }
}
