<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services;

use Firebase\JWT\CachedKeySet;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\HttpFactory;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

/**
 * Builds and memoizes the Zitadel JWKS key set.
 *
 * Shared by {@see \LiturgicalCalendar\Api\Http\Middleware\OidcAuthMiddleware} and
 * {@see WsCallerResolver} so that both reach the same filesystem cache. That sharing is not merely
 * tidy: the WebSocket server verifies tokens from inside a single-threaded ReactPHP loop, where a
 * JWKS fetch stalls every other connection for its duration, so a key the HTTP API has already
 * fetched is a key the WebSocket server does not have to.
 */
final class ZitadelKeySetFactory
{
    /**
     * Finite timeouts for the JWKS fetch.
     *
     * Guzzle's defaults for both options are `0`, meaning "wait indefinitely", and `CachedKeySet`
     * fetches **synchronously** from inside `JWT::decode()` whenever it meets an unknown `kid` or a
     * cold cache. In the HTTP API that blocks one php-fpm worker; in the WebSocket server it blocks
     * the entire Ratchet event loop, for every connected client at once, until the socket gives up.
     *
     * A refused connection fails instantly and was never the hazard. A provider that accepts the
     * connection and then goes quiet — a blackholing firewall, an overloaded node — is, and it is
     * indistinguishable from a healthy one until the timeout that did not exist expires.
     *
     * Kept short deliberately: a JWKS fetch that has taken four seconds is not going to produce a
     * useful answer, and the failure path is anonymous rather than broken.
     *
     * @var array{connect_timeout: float, timeout: float}
     */
    public const HTTP_OPTIONS = [
        'connect_timeout' => 2.0,
        'timeout'         => 4.0,
    ];

    /**
     * Cached key sets, keyed by issuer URL, so several OIDC providers can coexist.
     *
     * @var array<string, CachedKeySet>
     */
    private static array $keySets = [];

    public static function for(string $issuer, ?string $internalUrl = null, int $cacheTtl = 3600): CachedKeySet
    {
        $issuer = rtrim($issuer, '/');

        if (isset(self::$keySets[$issuer])) {
            return self::$keySets[$issuer];
        }

        $jwksUri = $issuer . '/oauth/v2/keys';

        if (null !== $internalUrl && '' !== $internalUrl) {
            // Rewrite the JWKS URI to the internal URL for Docker networking. CachedKeySet uses
            // PSR-18 sendRequest(), which does not apply Guzzle's default headers, so the Host header
            // is injected by middleware or Zitadel refuses a request addressed to the compose
            // service name.
            $jwksUri    = rtrim($internalUrl, '/') . '/oauth/v2/keys';
            $hostHeader = ZitadelHostHeader::deriveFromIssuer($issuer);
            $stack      = HandlerStack::create();
            $stack->push(Middleware::mapRequest(
                static fn(RequestInterface $request): RequestInterface => $request->withHeader('Host', $hostHeader)
            ));
            $httpClient = new Client(self::HTTP_OPTIONS + ['handler' => $stack]);
        } else {
            $httpClient = new Client(self::HTTP_OPTIONS);
        }

        // Note the depth: from src/Services/ the project root is two levels up, where it was three
        // from src/Http/Middleware/. Both must land on the same directory or the two callers would
        // keep separate caches and the sharing above would be a fiction.
        $cacheDir = dirname(__DIR__, 2) . '/cache';

        self::$keySets[$issuer] = new CachedKeySet(
            $jwksUri,
            $httpClient,
            new HttpFactory(),
            new FilesystemAdapter('jwks', $cacheTtl, $cacheDir),
            $cacheTtl,
            true // Rate limit JWKS fetches
        );

        return self::$keySets[$issuer];
    }

    /**
     * Drop every memoized key set. Useful for testing.
     */
    public static function reset(): void
    {
        self::$keySets = [];
    }
}
