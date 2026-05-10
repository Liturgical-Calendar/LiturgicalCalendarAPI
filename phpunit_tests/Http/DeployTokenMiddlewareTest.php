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
}
