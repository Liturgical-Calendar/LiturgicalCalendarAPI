<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers\Auth;

use LiturgicalCalendar\Api\Handlers\Auth\LogoutHandler;
use LiturgicalCalendar\Api\Http\Exception\MethodNotAllowedException;
use LiturgicalCalendar\Api\Services\JwtServiceFactory;
use LiturgicalCalendar\Tests\Handlers\AbstractHandlerTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(LogoutHandler::class)]
final class LogoutHandlerTest extends AbstractHandlerTestCase
{
    public function testOptionsPreflightSucceeds(): void
    {
        $handler  = new LogoutHandler();
        $request  = $this->requestFor('OPTIONS', '/auth/logout', [
            'Origin'                        => 'https://app.example.test',
            'Access-Control-Request-Method' => 'POST',
        ]);
        $response = $handler->handle($request);

        self::assertSame(204, $response->getStatusCode());
    }

    public function testGetIsMethodNotAllowed(): void
    {
        $this->expectException(MethodNotAllowedException::class);
        ( new LogoutHandler() )->handle($this->requestFor('GET', '/auth/logout'));
    }

    public function testLogoutWithoutTokenStillReturnsOkAndClearsCookies(): void
    {
        $handler  = new LogoutHandler();
        $request  = $this->requestFor('POST', '/auth/logout');
        $response = $handler->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeJsonBody($response);
        self::assertSame('Logged out successfully', $body['message']);

        // Both auth cookies are explicitly cleared with Max-Age=0.
        $setCookies = $response->getHeader('Set-Cookie');
        $combined   = implode("\n", $setCookies);
        self::assertStringContainsString('litcal_access_token=', $combined);
        self::assertStringContainsString('litcal_refresh_token=', $combined);
    }

    public function testLogoutWithValidBearerTokenSucceeds(): void
    {
        $token   = JwtServiceFactory::fromEnv()->generate('alice');
        $handler = new LogoutHandler();
        $request = $this->requestFor('POST', '/auth/logout', [
            'Authorization' => 'Bearer ' . $token,
        ]);

        $response = $handler->handle($request);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testLogoutWithGarbageTokenIsStillSuccessful(): void
    {
        // The handler swallows JWT-extraction errors so the user can always
        // log out even if their token is malformed.
        $handler  = new LogoutHandler();
        $request  = $this->requestFor('POST', '/auth/logout', ['Authorization' => 'Bearer this.is.not.a.jwt']);
        $response = $handler->handle($request);

        self::assertSame(200, $response->getStatusCode());
    }
}
