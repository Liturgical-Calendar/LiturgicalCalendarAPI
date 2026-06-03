<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Handlers\Ops;

use LiturgicalCalendar\Api\Database\Connection;
use LiturgicalCalendar\Api\Handlers\AbstractHandler;
use LiturgicalCalendar\Api\Health;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * HTTP GET /health
 *
 * Returns a JSON object summarising the operational health of the API,
 * including the openfga_outbox block for observability of the async
 * OpenFGA reconciliation subsystem.
 *
 * This endpoint is unauthenticated and publicly accessible so that
 * monitoring infrastructure (load-balancers, uptime checks, etc.) can
 * poll it without credentials.
 */
final class HealthHandler extends AbstractHandler
{
    public function __construct()
    {
        parent::__construct();
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->validateRequestMethod($request);

        $result = [
            'status'         => 'ok',
            'database'       => Connection::isConfigured() ? 'configured' : 'not_configured',
            'openfga_outbox' => Health::buildOutboxStats(),
        ];

        $body = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            $body = '{"status":"error","message":"Failed to encode health response"}';
        }

        return new Response(
            200,
            ['Content-Type' => 'application/json; charset=utf-8'],
            $body
        );
    }
}
