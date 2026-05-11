<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers\Auth;

use LiturgicalCalendar\Api\Handlers\Auth\MeHandler;
use LiturgicalCalendar\Api\Http\Exception\MethodNotAllowedException;
use LiturgicalCalendar\Api\Http\Exception\UnauthorizedException;
use LiturgicalCalendar\Api\Services\JwtServiceFactory;
use LiturgicalCalendar\Tests\Handlers\AbstractHandlerTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(MeHandler::class)]
final class MeHandlerTest extends AbstractHandlerTestCase
{
    public function testOptionsPreflightReturnsCorsHeaders(): void
    {
        $handler = new MeHandler();
        $request = $this->requestFor('OPTIONS', '/auth/me', [
            'Origin'                        => 'https://app.example.test',
            'Access-Control-Request-Method' => 'GET',
        ]);

        $response = $handler->handle($request);

        self::assertSame(204, $response->getStatusCode());
        self::assertNotEmpty($response->getHeaderLine('Access-Control-Allow-Methods'));
    }

    public function testPostIsMethodNotAllowed(): void
    {
        $handler = new MeHandler();
        $request = $this->requestFor('POST', '/auth/me');

        $this->expectException(MethodNotAllowedException::class);
        $handler->handle($request);
    }

    public function testMissingTokenIsUnauthorized(): void
    {
        $handler = new MeHandler();
        $request = $this->requestFor('GET', '/auth/me');

        $this->expectException(UnauthorizedException::class);
        $this->expectExceptionMessage('Not authenticated');
        $handler->handle($request);
    }

    public function testGarbageBearerTokenIsUnauthorized(): void
    {
        $handler = new MeHandler();
        $request = $this->requestFor('GET', '/auth/me', ['Authorization' => 'Bearer not.a.real.token']);

        $this->expectException(UnauthorizedException::class);
        $this->expectExceptionMessage('Invalid or expired token');
        $handler->handle($request);
    }

    public function testValidBearerTokenReturnsUserInfo(): void
    {
        $jwt   = JwtServiceFactory::fromEnv();
        $token = $jwt->generate('alice', ['roles' => ['developer', 'admin']]);

        $handler  = new MeHandler();
        $request  = $this->requestFor('GET', '/auth/me', [
            'Authorization' => 'Bearer ' . $token,
        ]);
        $response = $handler->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));

        $body = $this->decodeJsonBody($response);
        self::assertTrue($body['authenticated']);
        self::assertSame('alice', $body['username']);
        self::assertSame(['developer', 'admin'], $body['roles']);
        self::assertIsInt($body['exp']);
    }

    public function testCookieIsPreferredOverHeader(): void
    {
        $jwt         = JwtServiceFactory::fromEnv();
        $cookieToken = $jwt->generate('bob', ['roles' => ['viewer']]);
        $headerToken = $jwt->generate('eve', ['roles' => ['attacker']]);

        $handler = new MeHandler();
        $request = $this->requestFor('GET', '/auth/me', [
            'Authorization' => 'Bearer ' . $headerToken,
        ])->withCookieParams(['litcal_access_token' => $cookieToken]);

        $response = $handler->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeJsonBody($response);
        // Cookie wins — Eve's header token must be ignored.
        self::assertSame('bob', $body['username']);
    }
}
