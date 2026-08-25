<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Http\Server;

use Ratchet\Http\HttpServer;
use Ratchet\Http\HttpServerInterface;

/**
 * A Ratchet {@see HttpServer} whose WebSocket handshake may carry larger headers.
 *
 * Ratchet caps the handshake request at `HttpRequestParser::$maxSize`, 4096 bytes by default, and answers
 * `413 Request Entity Too Large` above it. That is ample for a WebSocket handshake — until the browser
 * attaches cookies.
 *
 * This server shares a registrable domain with the sites that authenticate against Zitadel, so a
 * `COOKIE_DOMAIN`-scoped session is sent here too, on every handshake, despite this server never reading
 * a cookie. Zitadel's tokens are JWTs: measured on a live session, `litcal_access_token` and
 * `litcal_id_token` alone are ~2.7 KB of `Cookie:` header, before a refresh token or anything else the
 * domain carries. Add a browser's ordinary handshake headers and 4096 is reachable — at which point every
 * WebSocket connection fails for a logged-in user and succeeds again the moment they log out, with the
 * browser reporting only "WebSocket connection failed".
 *
 * Measured against this server before the change:
 *
 *     ~4020 B handshake -> HTTP/1.1 101 Switching Protocols
 *     ~4220 B handshake -> HTTP/1.1 413 Request Entity Too Large
 *
 * The cap is a denial-of-service guard, so it is raised rather than removed. There is no way to stop the
 * browser sending the cookies: a host cannot opt out of a parent-domain cookie, and narrowing
 * `COOKIE_DOMAIN` would break the cross-subdomain sharing it exists to provide.
 */
final class LargeHeaderHttpServer extends HttpServer
{
    /**
     * Four times Ratchet's default. Comfortably clears a full Zitadel session plus a browser's usual
     * headers, and still bounds the buffer an unauthenticated client can make this server allocate.
     */
    public const MAX_HANDSHAKE_BYTES = 16384;

    public function __construct(HttpServerInterface $component, int $maxSize = self::MAX_HANDSHAKE_BYTES)
    {
        parent::__construct($component);

        // `$_reqParser` is protected on HttpServer and `maxSize` is public on the parser, so a subclass is
        // the supported way to reach it — Ratchet exposes no setter and no constructor argument.
        $this->_reqParser->maxSize = $maxSize;
    }
}
