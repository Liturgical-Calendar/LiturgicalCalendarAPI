<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Http;

use LiturgicalCalendar\Api\Http\Middleware\DeployTokenMiddleware;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class DeployTokenMiddlewareTest extends TestCase
{
    /** @var array<string,string|false> */
    private array $envBackup = [];

    private RequestHandlerInterface $okHandler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->envBackup = [
            'DEPLOY_TOKEN_env'    => $_ENV['DEPLOY_TOKEN']    ?? '__UNSET__',
            'DEPLOY_TOKEN_getenv' => getenv('DEPLOY_TOKEN') === false ? '__UNSET__' : (string) getenv('DEPLOY_TOKEN'),
            'APP_ENV_env'         => $_ENV['APP_ENV']         ?? '__UNSET__',
            'APP_ENV_getenv'      => getenv('APP_ENV') === false ? '__UNSET__' : (string) getenv('APP_ENV'),
        ];

        $this->okHandler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200, [], 'passed-through');
            }
        };
    }

    protected function tearDown(): void
    {
        foreach (['DEPLOY_TOKEN', 'APP_ENV'] as $var) {
            $envBackup    = $this->envBackup["{$var}_env"];
            $getenvBackup = $this->envBackup["{$var}_getenv"];
            if ($envBackup === '__UNSET__') {
                unset($_ENV[$var]);
            } else {
                $_ENV[$var] = $envBackup;
            }
            if ($getenvBackup === '__UNSET__') {
                putenv($var);
            } else {
                putenv("{$var}={$getenvBackup}");
            }
        }
        parent::tearDown();
    }

    public function testEmptyDeployTokenReturns503(): void
    {
        unset($_ENV['DEPLOY_TOKEN']);
        putenv('DEPLOY_TOKEN');
        $_ENV['APP_ENV'] = 'staging';
        putenv('APP_ENV=staging');

        $middleware = new DeployTokenMiddleware();
        $request    = new ServerRequest('POST', '/_ops/migrate', ['X-Deploy-Token' => 'doesntmatter']);

        $response = $middleware->process($request, $this->okHandler);

        $this->assertSame(503, $response->getStatusCode());
        $this->assertNotSame('passed-through', (string) $response->getBody());
    }

    public function testAppEnvDevelopmentReturns503(): void
    {
        $_ENV['DEPLOY_TOKEN'] = 'thisistheexpectedtoken';
        putenv('DEPLOY_TOKEN=thisistheexpectedtoken');
        $_ENV['APP_ENV'] = 'development';
        putenv('APP_ENV=development');

        $middleware = new DeployTokenMiddleware();
        $request    = new ServerRequest('POST', '/_ops/migrate', ['X-Deploy-Token' => 'thisistheexpectedtoken']);

        $response = $middleware->process($request, $this->okHandler);

        $this->assertSame(503, $response->getStatusCode());
    }

    public function testAppEnvUnsetReturns503(): void
    {
        $_ENV['DEPLOY_TOKEN'] = 'thisistheexpectedtoken';
        putenv('DEPLOY_TOKEN=thisistheexpectedtoken');
        unset($_ENV['APP_ENV']);
        putenv('APP_ENV');

        $middleware = new DeployTokenMiddleware();
        $request    = new ServerRequest('POST', '/_ops/migrate', ['X-Deploy-Token' => 'thisistheexpectedtoken']);

        $response = $middleware->process($request, $this->okHandler);

        $this->assertSame(503, $response->getStatusCode());
    }

    public function testMissingHeaderReturns401(): void
    {
        $_ENV['DEPLOY_TOKEN'] = 'thisistheexpectedtoken';
        putenv('DEPLOY_TOKEN=thisistheexpectedtoken');
        $_ENV['APP_ENV'] = 'staging';
        putenv('APP_ENV=staging');

        $middleware = new DeployTokenMiddleware();
        $request    = new ServerRequest('POST', '/_ops/migrate'); // no header

        $response = $middleware->process($request, $this->okHandler);

        $this->assertSame(401, $response->getStatusCode());
    }

    public function testWrongHeaderReturns401(): void
    {
        $_ENV['DEPLOY_TOKEN'] = 'thisistheexpectedtoken';
        putenv('DEPLOY_TOKEN=thisistheexpectedtoken');
        $_ENV['APP_ENV'] = 'production';
        putenv('APP_ENV=production');

        $middleware = new DeployTokenMiddleware();
        $request    = new ServerRequest('POST', '/_ops/migrate', ['X-Deploy-Token' => 'wrongtoken']);

        $response = $middleware->process($request, $this->okHandler);

        $this->assertSame(401, $response->getStatusCode());
    }

    public function testCorrectHeaderPassesThrough(): void
    {
        $_ENV['DEPLOY_TOKEN'] = 'thisistheexpectedtoken';
        putenv('DEPLOY_TOKEN=thisistheexpectedtoken');
        $_ENV['APP_ENV'] = 'staging';
        putenv('APP_ENV=staging');

        $middleware = new DeployTokenMiddleware();
        $request    = new ServerRequest('POST', '/_ops/migrate', ['X-Deploy-Token' => 'thisistheexpectedtoken']);

        $response = $middleware->process($request, $this->okHandler);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('passed-through', (string) $response->getBody());
    }

    public function testGetenvFallbackWhenSuperglobalUnset(): void
    {
        // Simulate FPM with restricted variables_order: $_ENV missing the key
        // but getenv() still returns it. Mirrors ApiKeyRateLimitMiddleware fix.
        unset($_ENV['DEPLOY_TOKEN'], $_ENV['APP_ENV']);
        putenv('DEPLOY_TOKEN=thisistheexpectedtoken');
        putenv('APP_ENV=staging');

        $middleware = new DeployTokenMiddleware();
        $request    = new ServerRequest('POST', '/_ops/migrate', ['X-Deploy-Token' => 'thisistheexpectedtoken']);

        $response = $middleware->process($request, $this->okHandler);

        $this->assertSame(200, $response->getStatusCode());
    }
}
