<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use LiturgicalCalendar\Api\Services\ZitadelService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * ZitadelService builds its own Guzzle client in the constructor (no
 * injection seam). These tests swap the private `httpClient` property
 * via reflection with a MockHandler-backed Client so the HTTP
 * interactions can be exercised offline.
 */
#[CoversClass(ZitadelService::class)]
final class ZitadelServiceTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $savedEnv = [];

    private const UNSET = "\0__unset__\0";

    private const VARS = [
        'ZITADEL_ISSUER',
        'ZITADEL_PROJECT_ID',
        'ZITADEL_MACHINE_TOKEN',
        'ZITADEL_INTERNAL_URL',
    ];

    protected function setUp(): void
    {
        foreach (self::VARS as $k) {
            $this->savedEnv[$k] = array_key_exists($k, $_ENV) ? $_ENV[$k] : self::UNSET;
            unset($_ENV[$k]);
            putenv($k);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $k => $v) {
            if ($v === self::UNSET) {
                unset($_ENV[$k]);
                putenv($k);
            } else {
                $_ENV[$k] = $v;
            }
        }
    }

    private function makeService(MockHandler $mock): ZitadelService
    {
        $svc = new ZitadelService(
            'https://zitadel.test',
            'project-123',
            'machine-token',
        );

        $stack  = HandlerStack::create($mock);
        $client = new Client(['handler' => $stack, 'base_uri' => 'https://zitadel.test']);

        $refl = new \ReflectionClass($svc);
        $prop = $refl->getProperty('httpClient');
        $prop->setValue($svc, $client);

        return $svc;
    }

    public function testIsConfiguredFalseWhenAllMissing(): void
    {
        self::assertFalse(ZitadelService::isConfigured());
    }

    public function testIsConfiguredFalseWhenAnyMissing(): void
    {
        $_ENV['ZITADEL_ISSUER']     = 'https://z.test';
        $_ENV['ZITADEL_PROJECT_ID'] = 'p1';
        // No machine token.
        self::assertFalse(ZitadelService::isConfigured());
    }

    public function testIsConfiguredTrueWhenAllSet(): void
    {
        $_ENV['ZITADEL_ISSUER']        = 'https://z.test';
        $_ENV['ZITADEL_PROJECT_ID']    = 'p1';
        $_ENV['ZITADEL_MACHINE_TOKEN'] = 'tk';

        self::assertTrue(ZitadelService::isConfigured());
    }

    public function testFromEnvThrowsOnMissingRequired(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ZITADEL_ISSUER, ZITADEL_PROJECT_ID');
        ZitadelService::fromEnv();
    }

    public function testFromEnvProducesService(): void
    {
        $_ENV['ZITADEL_ISSUER']        = 'https://z.test/';
        $_ENV['ZITADEL_PROJECT_ID']    = 'p1';
        $_ENV['ZITADEL_MACHINE_TOKEN'] = 'tk';

        $svc = ZitadelService::fromEnv();
        // Issuer trailing slash is stripped.
        self::assertSame('https://z.test', $svc->getIssuer());
        self::assertSame('p1', $svc->getProjectId());
    }

    public function testGetUserReturnsUserObjectOnOk(): void
    {
        $svc = $this->makeService(new MockHandler([
            new Response(200, [], json_encode(['user' => ['id' => 'u1', 'human' => ['profile' => ['displayName' => 'Alice']]]])),
        ]));

        $user = $svc->getUser('u1');
        self::assertIsArray($user);
        self::assertSame('u1', $user['id']);
        self::assertSame('Alice', $user['human']['profile']['displayName']);
    }

    public function testGetUserReturnsNullOnHttpError(): void
    {
        $svc = $this->makeService(new MockHandler([new Response(404)]));
        self::assertNull($svc->getUser('does-not-exist'));
    }

    public function testGetUserReturnsNullOnUnexpectedBodyShape(): void
    {
        $svc = $this->makeService(new MockHandler([new Response(200, [], 'not json')]));
        self::assertNull($svc->getUser('u1'));
    }

    public function testGetDiscoveryDocumentIsCachedWithinTtl(): void
    {
        // Two calls — only the first one should hit the mock; the second
        // returns the cached document.
        $svc = $this->makeService(new MockHandler([
            new Response(200, [], json_encode([
                'issuer'                 => 'https://zitadel.test',
                'authorization_endpoint' => 'https://zitadel.test/oauth/v2/authorize',
                'token_endpoint'         => 'https://zitadel.test/oauth/v2/token',
                'jwks_uri'               => 'https://zitadel.test/oauth/v2/keys',
            ])),
            // No further responses queued — a second HTTP call would error.
        ]));

        $first = $svc->getDiscoveryDocument();
        self::assertIsArray($first);
        self::assertSame('https://zitadel.test', $first['issuer']);

        $second = $svc->getDiscoveryDocument();
        self::assertSame($first, $second, 'Cached discovery should be returned without re-fetching');
    }

    public function testEndpointConvenienceAccessors(): void
    {
        $svc = $this->makeService(new MockHandler([
            new Response(200, [], json_encode([
                'authorization_endpoint' => 'https://zitadel.test/oauth/v2/authorize',
                'token_endpoint'         => 'https://zitadel.test/oauth/v2/token',
                'userinfo_endpoint'      => 'https://zitadel.test/oidc/v1/userinfo',
                'end_session_endpoint'   => 'https://zitadel.test/oidc/v1/end_session',
                'jwks_uri'               => 'https://zitadel.test/oauth/v2/keys',
            ])),
        ]));

        self::assertSame('https://zitadel.test/oauth/v2/authorize', $svc->getAuthorizationEndpoint());
        // Subsequent accessors hit the cached discovery, not the wire.
        self::assertSame('https://zitadel.test/oauth/v2/token', $svc->getTokenEndpoint());
        self::assertSame('https://zitadel.test/oidc/v1/userinfo', $svc->getUserinfoEndpoint());
        self::assertSame('https://zitadel.test/oidc/v1/end_session', $svc->getEndSessionEndpoint());
        self::assertSame('https://zitadel.test/oauth/v2/keys', $svc->getJwksUri());
    }

    public function testGetDiscoveryDocumentReturnsNullOnFetchFailure(): void
    {
        $svc = $this->makeService(new MockHandler([new Response(500)]));
        self::assertNull($svc->getDiscoveryDocument());
    }

    public function testAssignUserRolePostsAndReportsSuccess(): void
    {
        $svc = $this->makeService(new MockHandler([new Response(200, [], '{}')]));
        self::assertTrue($svc->assignUserRole('u1', 'developer'));
    }

    public function testAssignUserRoleReturnsFalseOnError(): void
    {
        $svc = $this->makeService(new MockHandler([new Response(500)]));
        self::assertFalse($svc->assignUserRole('u1', 'developer'));
    }

    public function testResendEmailVerificationReportsSuccess(): void
    {
        $svc    = $this->makeService(new MockHandler([new Response(200, [], '{}')]));
        $result = $svc->resendEmailVerification('u1');
        self::assertTrue($result['success']);
        // success result omits the 'error' key entirely.
        self::assertArrayNotHasKey('error', $result);
    }

    public function testResendEmailVerificationReportsError(): void
    {
        $svc    = $this->makeService(new MockHandler([new Response(500, [], 'Internal Server Error')]));
        $result = $svc->resendEmailVerification('u1');
        self::assertFalse($result['success']);
        self::assertNotNull($result['error']);
    }
}
