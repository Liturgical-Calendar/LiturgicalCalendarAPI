<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Handlers\Ops;

use LiturgicalCalendar\Api\Handlers\AbstractHandler;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Invalidates the OPcache so freshly rsync'd code is picked up.
 *
 * POST /_ops/opcache-reset
 *
 * The deploy workflow can land new files on disk while php-fpm keeps
 * serving cached bytecode for the old files (especially when rsync
 * preserves source mtimes that pre-date the cached entries). On the FPM
 * SAPI opcache lives in process-shared memory, so a single
 * opcache_reset() call from any worker invalidates the cache for all of
 * them — the next request recompiles from disk.
 *
 * Authentication is the responsibility of DeployTokenMiddleware piped
 * upstream by the Router. This handler assumes the request has passed
 * the token gate.
 */
final class OpcacheResetHandler extends AbstractHandler
{
    public function __construct()
    {
        parent::__construct();
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->validateRequestMethod($request);

        if (!function_exists('opcache_reset')) {
            return new Response(
                503,
                ['Content-Type' => 'text/plain; charset=utf-8'],
                "OPcache extension is not loaded; nothing to reset.\n"
            );
        }

        $ok = @opcache_reset();
        if ($ok === false) {
            // Either the extension is loaded-but-disabled (opcache.enable=0)
            // or the calling script is on opcache.restrict_api's blacklist.
            return new Response(
                500,
                ['Content-Type' => 'text/plain; charset=utf-8'],
                "opcache_reset() returned false (opcache disabled, or restrict_api blocks this caller).\n"
            );
        }

        return new Response(
            200,
            ['Content-Type' => 'text/plain; charset=utf-8'],
            "OPcache reset.\n"
        );
    }
}
