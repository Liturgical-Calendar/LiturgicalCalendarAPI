<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Http;

use LiturgicalCalendar\Api\Http\CookieHelper;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CookieHelper::class)]
final class CookieHelperTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $savedServer = [];

    /** @var array<string,mixed> */
    private array $savedEnv = [];

    /** Sentinel for keys that were not originally present. */
    private const UNSET = "\0__unset__\0";

    private const SERVER_KEYS = [
        'REQUEST_SCHEME',
        'HTTPS',
        'SERVER_PORT',
        'HTTP_X_FORWARDED_PROTO',
        'HTTP_HOST',
        'SERVER_NAME',
        'COOKIE_DOMAIN',
    ];

    private const ENV_KEYS = ['COOKIE_DOMAIN'];

    protected function setUp(): void
    {
        foreach (self::SERVER_KEYS as $k) {
            $this->savedServer[$k] = array_key_exists($k, $_SERVER) ? $_SERVER[$k] : self::UNSET;
            unset($_SERVER[$k]);
        }
        foreach (self::ENV_KEYS as $k) {
            $this->savedEnv[$k] = array_key_exists($k, $_ENV) ? $_ENV[$k] : self::UNSET;
            unset($_ENV[$k]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->savedServer as $k => $v) {
            if ($v === self::UNSET) {
                unset($_SERVER[$k]);
            } else {
                $_SERVER[$k] = $v;
            }
        }
        foreach ($this->savedEnv as $k => $v) {
            if ($v === self::UNSET) {
                unset($_ENV[$k]);
            } else {
                $_ENV[$k] = $v;
            }
        }
    }

    public function testIsSecureDetectsHttps(): void
    {
        $_SERVER['HTTPS'] = 'on';
        self::assertTrue(CookieHelper::isSecure());
    }

    public function testIsSecureDetectsRequestScheme(): void
    {
        $_SERVER['REQUEST_SCHEME'] = 'https';
        self::assertTrue(CookieHelper::isSecure());
    }

    public function testIsSecureDetectsPort443(): void
    {
        $_SERVER['SERVER_PORT'] = '443';
        self::assertTrue(CookieHelper::isSecure());
    }

    public function testIsSecureDetectsForwardedProto(): void
    {
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        self::assertTrue(CookieHelper::isSecure());
    }

    public function testIsSecureFalseByDefault(): void
    {
        self::assertFalse(CookieHelper::isSecure());
    }

    public function testGetCookieDomainPrefersEnvOverride(): void
    {
        $_ENV['COOKIE_DOMAIN'] = '.example.com';
        $_SERVER['HTTP_HOST']  = 'foo.example.com';
        self::assertSame('.example.com', CookieHelper::getCookieDomain());
    }

    public function testGetCookieDomainStripsPort(): void
    {
        $_SERVER['HTTP_HOST'] = 'api.example.com:8080';
        self::assertSame('api.example.com', CookieHelper::getCookieDomain());
    }

    public function testGetCookieDomainReturnsEmptyForLocalhost(): void
    {
        $_SERVER['HTTP_HOST'] = 'localhost';
        self::assertSame('', CookieHelper::getCookieDomain());

        $_SERVER['HTTP_HOST'] = '127.0.0.1:8000';
        self::assertSame('', CookieHelper::getCookieDomain());
    }

    public function testGetCookieDomainFallsBackToServerName(): void
    {
        $_SERVER['SERVER_NAME'] = 'api.example.com';
        self::assertSame('api.example.com', CookieHelper::getCookieDomain());
    }

    public function testBuildCookieHeaderBasic(): void
    {
        $_SERVER['HTTP_HOST'] = 'localhost';
        $header               = CookieHelper::buildCookieHeader('foo', 'bar baz');
        // url-encoded value, default path '/', HttpOnly, SameSite=Lax, no Secure, no Domain.
        self::assertStringContainsString('foo=bar+baz', $header);
        self::assertStringContainsString('Path=/', $header);
        self::assertStringContainsString('HttpOnly', $header);
        self::assertStringContainsString('SameSite=Lax', $header);
        self::assertStringNotContainsString('Secure', $header);
        self::assertStringNotContainsString('Domain=', $header);
        self::assertStringNotContainsString('Max-Age', $header);
    }

    public function testBuildCookieHeaderPersistentEmitsMaxAgeAndExpires(): void
    {
        $_SERVER['HTTP_HOST'] = 'localhost';
        $header               = CookieHelper::buildCookieHeader('k', 'v', maxAge: 3600);
        self::assertStringContainsString('Max-Age=3600', $header);
        self::assertMatchesRegularExpression('/Expires=[A-Z][a-z]{2}, /', $header);
    }

    public function testBuildCookieHeaderDeletionUsesMaxAgeZeroAndEpochExpiry(): void
    {
        $_SERVER['HTTP_HOST'] = 'localhost';
        $header               = CookieHelper::buildCookieHeader('k', '', maxAge: -1);
        self::assertStringContainsString('Max-Age=0', $header);
        self::assertStringContainsString('Expires=Thu, 01 Jan 1970 00:00:00 GMT', $header);
    }

    public function testBuildCookieHeaderSecureAndDomain(): void
    {
        $_SERVER['HTTPS']     = 'on';
        $_SERVER['HTTP_HOST'] = 'api.example.com';
        $header               = CookieHelper::buildCookieHeader('k', 'v', sameSite: 'None');
        self::assertStringContainsString('Secure', $header);
        self::assertStringContainsString('SameSite=None', $header);
        self::assertStringContainsString('Domain=api.example.com', $header);
    }

    public function testBuildCookieHeaderDowngradesSameSiteNoneWhenInsecure(): void
    {
        $_SERVER['HTTP_HOST'] = 'api.example.com';
        // No HTTPS — SameSite=None must be downgraded to Lax (None+!Secure is rejected by browsers).
        $header = CookieHelper::buildCookieHeader('k', 'v', sameSite: 'None');
        self::assertStringContainsString('SameSite=Lax', $header);
    }

    public function testSetAccessTokenCookieAttachesToResponse(): void
    {
        $_SERVER['HTTP_HOST'] = 'localhost';
        $response             = new Response();
        $response             = CookieHelper::setAccessTokenCookie($response, 'tk', 1800);
        self::assertNotEmpty($response->getHeaderLine('Set-Cookie'));
        self::assertStringContainsString(CookieHelper::ACCESS_TOKEN_COOKIE . '=tk', $response->getHeaderLine('Set-Cookie'));
        self::assertStringContainsString('Max-Age=1800', $response->getHeaderLine('Set-Cookie'));
    }

    public function testSetRefreshTokenCookieRememberMeFalseIsSessionCookie(): void
    {
        $_SERVER['HTTP_HOST'] = 'localhost';
        $response             = new Response();
        $response             = CookieHelper::setRefreshTokenCookie($response, 'rt', 604800, rememberMe: false);
        $header               = $response->getHeaderLine('Set-Cookie');
        self::assertStringContainsString(CookieHelper::REFRESH_TOKEN_COOKIE . '=rt', $header);
        // Session cookie — no Max-Age.
        self::assertStringNotContainsString('Max-Age=', $header);
        self::assertStringContainsString('Path=/auth', $header);
        self::assertStringContainsString('SameSite=Strict', $header);
    }

    public function testSetRefreshTokenCookieRememberMeTrueIsPersistent(): void
    {
        $_SERVER['HTTP_HOST'] = 'localhost';
        $response             = new Response();
        $response             = CookieHelper::setRefreshTokenCookie($response, 'rt', 7200, rememberMe: true);
        self::assertStringContainsString('Max-Age=7200', $response->getHeaderLine('Set-Cookie'));
    }

    public function testClearAuthCookiesAddsBothDeletionHeaders(): void
    {
        $_SERVER['HTTP_HOST'] = 'localhost';
        $response             = new Response();
        $response             = CookieHelper::clearAuthCookies($response);
        $headers              = $response->getHeader('Set-Cookie');
        self::assertCount(2, $headers);
        $combined = implode("\n", $headers);
        self::assertStringContainsString(CookieHelper::ACCESS_TOKEN_COOKIE, $combined);
        self::assertStringContainsString(CookieHelper::REFRESH_TOKEN_COOKIE, $combined);
        self::assertStringContainsString('Max-Age=0', $combined);
    }

    public function testGetAccessAndRefreshTokenFromCookies(): void
    {
        $cookies = [
            CookieHelper::ACCESS_TOKEN_COOKIE  => 'abc',
            CookieHelper::REFRESH_TOKEN_COOKIE => 'def',
        ];
        self::assertSame('abc', CookieHelper::getAccessToken($cookies));
        self::assertSame('def', CookieHelper::getRefreshToken($cookies));
        self::assertNull(CookieHelper::getAccessToken([]));
        self::assertNull(CookieHelper::getRefreshToken([]));
    }
}
