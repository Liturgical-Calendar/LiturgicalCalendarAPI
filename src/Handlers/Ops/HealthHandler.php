<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Handlers\Ops;

use LiturgicalCalendar\Api\Database\Connection;
use LiturgicalCalendar\Api\Handlers\AbstractHandler;
use LiturgicalCalendar\Api\Health;
use LiturgicalCalendar\Api\Http\Enum\RequestMethod;
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
 * poll it without credentials. It is GET-only — non-GET requests get a
 * 405 from validateRequestMethod().
 */
final class HealthHandler extends AbstractHandler
{
    public function __construct()
    {
        parent::__construct();
        // GET-only; any other verb is a 405. The default allowed-methods
        // list is permissive (all verbs); restrict it here so monitoring
        // clients get a stable contract.
        $this->allowedRequestMethods = [RequestMethod::GET];
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->validateRequestMethod($request);

        // Active DB probe: if PG is configured, run a 1-row SELECT to
        // verify we can actually reach it. Without this the endpoint's
        // "database" field would always read "configured" even when PG is
        // down, masking outages from monitoring infrastructure.
        $dbStatus = 'not_configured';
        $overall  = 'ok';
        if (Connection::isConfigured()) {
            try {
                $pdo = Connection::getInstance();
                $pdo->query('SELECT 1');
                $dbStatus = 'reachable';
            } catch (\Throwable) {
                $dbStatus = 'unreachable';
                $overall  = 'degraded';
            }
        }

        $result = [
            'status'         => $overall,
            'database'       => $dbStatus,
            'openfga_outbox' => Health::buildOutboxStats(),
        ];

        $body = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            $body = '{"status":"error","message":"Failed to encode health response"}';
        }

        // 503 on degraded so load balancers / uptime checks fail fast.
        $statusCode = $overall === 'ok' ? 200 : 503;

        return new Response(
            $statusCode,
            ['Content-Type' => 'application/json; charset=utf-8'],
            $body
        );
    }
}
