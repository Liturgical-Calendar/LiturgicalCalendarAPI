<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Http;

use LiturgicalCalendar\Api\Http\Exception\ForbiddenException;
use LiturgicalCalendar\Api\Http\Middleware\HttpsEnforcementMiddleware;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[CoversClass(HttpsEnforcementMiddleware::class)]
final class HttpsEnforcementMiddlewareTest extends TestCase
{
    private const UNSET = "\0__unset__\0";

    /** @var mixed */
    private $savedAppEnv;
    /** @var mixed */
    private $savedEnforcement;

    protected function setUp(): void
    {
        $this->savedAppEnv      = array_key_exists('APP_ENV', $_ENV) ? $_ENV['APP_ENV'] : self::UNSET;
        $this->savedEnforcement = array_key_exists('HTTPS_ENFORCEMENT', $_ENV) ? $_ENV['HTTPS_ENFORCEMENT'] : self::UNSET;
        unset($_ENV['APP_ENV'], $_ENV['HTTPS_ENFORCEMENT']);
    }

    protected function tearDown(): void
    {
        foreach (['APP_ENV' => $this->savedAppEnv, 'HTTPS_ENFORCEMENT' => $this->savedEnforcement] as $k => $v) {
            if ($v === self::UNSET) {
                unset($_ENV[$k]);
            } else {
                $_ENV[$k] = $v;
            }
        }
    }

    private function handler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200);
            }
        };
    }

    public function testSkipsEnforcementInDevelopment(): void
    {
        $request  = ( new Psr17Factory() )->createServerRequest('GET', 'http://example.test/auth/login');
        $response = ( new HttpsEnforcementMiddleware() )->process($request, $this->handler());
        self::assertSame(200, $response->getStatusCode());
    }

    public function testSkipsEnforcementWhenExplicitlyDisabled(): void
    {
        $_ENV['APP_ENV']           = 'production';
        $_ENV['HTTPS_ENFORCEMENT'] = 'false';
        $request                   = ( new Psr17Factory() )->createServerRequest('GET', 'http://example.test/auth/login');
        $response                  = ( new HttpsEnforcementMiddleware() )->process($request, $this->handler());
        self::assertSame(200, $response->getStatusCode());
    }

    public function testRequiresHttpsInProduction(): void
    {
        $_ENV['APP_ENV'] = 'production';
        $request         = ( new Psr17Factory() )->createServerRequest('GET', 'http://example.test/auth/login');
        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage('HTTPS is required');
        ( new HttpsEnforcementMiddleware() )->process($request, $this->handler());
    }

    public function testAcceptsHttpsUriScheme(): void
    {
        $_ENV['APP_ENV'] = 'production';
        $request         = ( new Psr17Factory() )->createServerRequest('GET', 'https://example.test/auth/login');
        $response        = ( new HttpsEnforcementMiddleware() )->process($request, $this->handler());
        self::assertSame(200, $response->getStatusCode());
    }

    public function testAcceptsForwardedProtoHttps(): void
    {
        $_ENV['APP_ENV'] = 'production';
        $request         = ( new Psr17Factory() )
            ->createServerRequest('GET', 'http://example.test/auth/login')
            ->withHeader('X-Forwarded-Proto', 'https');
        $response        = ( new HttpsEnforcementMiddleware() )->process($request, $this->handler());
        self::assertSame(200, $response->getStatusCode());
    }

    public function testAcceptsServerHttpsParam(): void
    {
        $_ENV['APP_ENV'] = 'staging';
        $factory         = new Psr17Factory();
        $request         = $factory->createServerRequest(
            'GET',
            'http://example.test/auth/login',
            ['HTTPS' => 'on']
        );
        $response        = ( new HttpsEnforcementMiddleware() )->process($request, $this->handler());
        self::assertSame(200, $response->getStatusCode());
    }

    public function testAcceptsServerPort443(): void
    {
        $_ENV['APP_ENV'] = 'production';
        $factory         = new Psr17Factory();
        $request         = $factory->createServerRequest(
            'GET',
            'http://example.test/auth/login',
            ['SERVER_PORT' => '443']
        );
        $response        = ( new HttpsEnforcementMiddleware() )->process($request, $this->handler());
        self::assertSame(200, $response->getStatusCode());
    }

    public function testAcceptsRequestSchemeServerParam(): void
    {
        $_ENV['APP_ENV'] = 'production';
        $factory         = new Psr17Factory();
        $request         = $factory->createServerRequest(
            'GET',
            'http://example.test/auth/login',
            ['REQUEST_SCHEME' => 'https']
        );
        $response        = ( new HttpsEnforcementMiddleware() )->process($request, $this->handler());
        self::assertSame(200, $response->getStatusCode());
    }
}
