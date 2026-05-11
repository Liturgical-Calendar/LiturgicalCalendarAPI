<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers\Auth;

use LiturgicalCalendar\Api\Handlers\Auth\RefreshHandler;
use LiturgicalCalendar\Api\Http\Exception\MethodNotAllowedException;
use LiturgicalCalendar\Api\Http\Exception\UnauthorizedException;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use LiturgicalCalendar\Api\Services\JwtServiceFactory;
use LiturgicalCalendar\Tests\Handlers\AbstractHandlerTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(RefreshHandler::class)]
final class RefreshHandlerTest extends AbstractHandlerTestCase
{
    public function testOptionsPreflightSucceeds(): void
    {
        $response = ( new RefreshHandler() )->handle($this->requestFor('OPTIONS', '/auth/refresh', [
            'Origin'                        => 'https://app.example.test',
            'Access-Control-Request-Method' => 'POST',
        ]));

        self::assertSame(204, $response->getStatusCode());
    }

    public function testGetIsMethodNotAllowed(): void
    {
        $this->expectException(MethodNotAllowedException::class);
        ( new RefreshHandler() )->handle($this->requestFor('GET', '/auth/refresh'));
    }

    public function testMissingRefreshTokenIsValidationError(): void
    {
        $this->expectException(ValidationException::class);
        ( new RefreshHandler() )->handle($this->requestFor('POST', '/auth/refresh', [], []));
    }

    public function testGarbageRefreshTokenIsUnauthorized(): void
    {
        $this->expectException(UnauthorizedException::class);
        $this->expectExceptionMessage('Invalid or expired refresh token');

        ( new RefreshHandler() )->handle(
            $this->requestFor('POST', '/auth/refresh', [], ['refresh_token' => 'not.a.real.token'])
        );
    }

    public function testValidRefreshTokenFromBodyReturnsNewAccessToken(): void
    {
        $jwt     = JwtServiceFactory::fromEnv();
        $refresh = $jwt->generateRefreshToken('alice');

        $response = ( new RefreshHandler() )->handle(
            $this->requestFor('POST', '/auth/refresh', [], ['refresh_token' => $refresh])
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $body = $this->decodeJsonBody($response);
        self::assertIsString($body['access_token']);
        self::assertNotEmpty($body['access_token']);
        self::assertSame('Bearer', $body['token_type']);
        self::assertIsInt($body['expires_in']);

        // Access-token cookie is freshly set.
        self::assertStringContainsString('litcal_access_token=', implode("\n", $response->getHeader('Set-Cookie')));
    }

    public function testRefreshTokenFromCookieIsPreferredOverBody(): void
    {
        $jwt           = JwtServiceFactory::fromEnv();
        $cookieRefresh = $jwt->generateRefreshToken('cookie-user');
        $bodyRefresh   = $jwt->generateRefreshToken('body-user');

        $request = $this->requestFor('POST', '/auth/refresh', [], ['refresh_token' => $bodyRefresh])
            ->withCookieParams(['litcal_refresh_token' => $cookieRefresh]);

        $response = ( new RefreshHandler() )->handle($request);

        self::assertSame(200, $response->getStatusCode());
        // The username encoded into the new access token should be the
        // cookie's, not the body's — verify by decoding the access_token.
        $body           = $this->decodeJsonBody($response);
        $verifiedAccess = $jwt->verify($body['access_token']);
        self::assertNotNull($verifiedAccess);
        self::assertSame('cookie-user', $verifiedAccess->sub ?? $verifiedAccess->username);
    }
}
