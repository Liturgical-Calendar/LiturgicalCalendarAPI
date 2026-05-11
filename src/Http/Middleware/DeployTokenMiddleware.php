<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Http\Middleware;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Authenticates deploy-time requests to /_ops/migrate via a long random
 * shared token. Fail-closed: empty DEPLOY_TOKEN, missing/wrong header,
 * or APP_ENV outside {staging, production} all reject. Comparisons use
 * hash_equals to avoid timing-based token discovery.
 */
final class DeployTokenMiddleware implements MiddlewareInterface
{
    private const HEADER          = 'X-Deploy-Token';
    private const ALLOWED_APP_ENV = ['staging', 'production'];

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $appEnv = getenv('APP_ENV') ?: ( $_ENV['APP_ENV'] ?? '' );
        if (!is_string($appEnv) || !in_array($appEnv, self::ALLOWED_APP_ENV, true)) {
            return new Response(503, ['Content-Type' => 'text/plain'], "Deploy endpoint disabled in this environment\n");
        }

        $expected = getenv('DEPLOY_TOKEN') ?: ( $_ENV['DEPLOY_TOKEN'] ?? '' );
        if (!is_string($expected) || $expected === '') {
            return new Response(503, ['Content-Type' => 'text/plain'], "Deploy endpoint not configured\n");
        }

        $provided = $request->getHeaderLine(self::HEADER);
        if ($provided === '' || !hash_equals($expected, $provided)) {
            return new Response(401, ['Content-Type' => 'text/plain'], "Unauthorized\n");
        }

        return $handler->handle($request);
    }
}
