<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services;

use GuzzleHttp\Psr7\Request;
use LiturgicalCalendar\Api\Services\JwtService;
use LiturgicalCalendar\Api\Services\WsCallerResolver;
use LiturgicalCalendar\Api\Services\ZitadelKeySetFactory;
use PHPUnit\Framework\TestCase;

/**
 * The token-audience boundary — raised in review on #894.
 *
 * A signature proves a token is *genuine*, not that it is *yours*. Zitadel signs every token in an
 * instance with the same keys, so verifying the signature alone accepts any correctly-signed token
 * from any other application in that instance — and if such a token happens to carry `admin` or
 * `test_editor` among its project roles, the permission gate hands it a validation run. That is a
 * confused deputy, and the first version of `WsCallerResolver` had it: it mirrored
 * `OidcAuthMiddleware`'s *decode* step without its `iss`/`aud` checks.
 *
 * The rule is exercised through the pure predicate rather than through `JWT::decode()`, so it can be
 * asserted exhaustively without an RSA keypair or a live provider — the crypto is `firebase/php-jwt`'s
 * to test, while *which tokens this application accepts* is ours.
 */
final class WsCallerAudienceTest extends TestCase
{
    private const ISSUER    = 'https://zitadel.example.test';
    private const CLIENT_ID = 'client-123';
    private const PROJECT   = 'project-456';

    protected function tearDown(): void
    {
        ZitadelKeySetFactory::reset();
        parent::tearDown();
    }

    private function payload(string $iss, mixed $aud): object
    {
        return (object) ['sub' => 'someone', 'iss' => $iss, 'aud' => $aud];
    }

    public function testATokenForThisClientIsAccepted(): void
    {
        $this->assertTrue(WsCallerResolver::isIntendedFor(
            $this->payload(self::ISSUER, self::CLIENT_ID),
            self::ISSUER,
            [self::CLIENT_ID, self::PROJECT]
        ));
    }

    public function testATokenForTheProjectIsAccepted(): void
    {
        $this->assertTrue(WsCallerResolver::isIntendedFor(
            $this->payload(self::ISSUER, self::PROJECT),
            self::ISSUER,
            [self::CLIENT_ID, self::PROJECT]
        ));
    }

    public function testAnAudienceArrayIsAcceptedWhenItContainsOne(): void
    {
        $this->assertTrue(WsCallerResolver::isIntendedFor(
            $this->payload(self::ISSUER, ['someone-else', self::CLIENT_ID]),
            self::ISSUER,
            [self::CLIENT_ID, self::PROJECT]
        ));
    }

    /**
     * The finding, stated as a test: a genuine token for a different application must not pass.
     */
    public function testATokenForAnotherApplicationIsRejected(): void
    {
        $this->assertFalse(WsCallerResolver::isIntendedFor(
            $this->payload(self::ISSUER, 'some-other-app'),
            self::ISSUER,
            [self::CLIENT_ID, self::PROJECT]
        ));
    }

    public function testATokenFromAnotherIssuerIsRejected(): void
    {
        $this->assertFalse(WsCallerResolver::isIntendedFor(
            $this->payload('https://evil.example.test', self::CLIENT_ID),
            self::ISSUER,
            [self::CLIENT_ID, self::PROJECT]
        ));
    }

    public function testATrailingSlashOnTheIssuerIsNotAMismatch(): void
    {
        $this->assertTrue(WsCallerResolver::isIntendedFor(
            $this->payload(self::ISSUER . '/', self::CLIENT_ID),
            self::ISSUER,
            [self::CLIENT_ID]
        ));
    }

    public function testAMissingAudienceIsRejected(): void
    {
        $this->assertFalse(WsCallerResolver::isIntendedFor(
            (object) ['sub' => 'someone', 'iss' => self::ISSUER],
            self::ISSUER,
            [self::CLIENT_ID]
        ));
    }

    public function testAMissingIssuerIsRejected(): void
    {
        $this->assertFalse(WsCallerResolver::isIntendedFor(
            (object) ['sub' => 'someone', 'aud' => self::CLIENT_ID],
            self::ISSUER,
            [self::CLIENT_ID]
        ));
    }

    public function testANonStringAudienceShapeIsRejected(): void
    {
        $this->assertFalse(WsCallerResolver::isIntendedFor(
            $this->payload(self::ISSUER, 42),
            self::ISSUER,
            [self::CLIENT_ID]
        ));
    }

    /**
     * An empty audience list would otherwise mean "match nothing to check, so accept" — the direction
     * that turns a misconfiguration into an open door.
     */
    public function testAnEmptyAudienceListAcceptsNothing(): void
    {
        $this->assertFalse(WsCallerResolver::isIntendedFor(
            $this->payload(self::ISSUER, self::CLIENT_ID),
            self::ISSUER,
            []
        ));
    }

    /**
     * And the resolver as a whole agrees: an issuer it cannot audience-check is not a path it offers.
     */
    public function testAnIssuerWithNoAudienceConfiguredDisablesTheZitadelPath(): void
    {
        $resolver = new WsCallerResolver(null, self::ISSUER, null);

        $this->assertFalse($resolver->warmKeySet(), 'nothing worth warming');
        $this->assertStringContainsString('DISABLED', $resolver->describeAvailablePaths());
    }

    public function testAnIssuerWithAnAudienceOffersTheZitadelPath(): void
    {
        $resolver = new WsCallerResolver(null, self::ISSUER, null, 3600, self::CLIENT_ID);

        $described = $resolver->describeAvailablePaths();
        $this->assertStringContainsString('Zitadel RS256', $described);
        $this->assertStringNotContainsString('DISABLED', $described);
    }

    /**
     * The legacy path is untouched by all of this: it is verified against a secret this API owns, so
     * there is no other application whose tokens it could confuse for its own.
     */
    public function testTheLegacyPathStillWorksWithAnUnaudiencedIssuer(): void
    {
        $secret   = 'kFj9wQz2Lm7XpR4tVbN8sHc1YdG6aE0u';
        $resolver = new WsCallerResolver(new JwtService($secret), self::ISSUER, null);
        $token    = ( new JwtService($secret) )->generate('someone', ['roles' => ['admin']]);
        $request  = new Request('GET', '/', ['Cookie' => 'litcal_access_token=' . $token]);

        $this->assertTrue($resolver->fromHandshake($request)->authenticated);
    }
}
