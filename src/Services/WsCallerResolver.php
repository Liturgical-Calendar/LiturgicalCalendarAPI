<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services;

use Firebase\JWT\JWT;
use LiturgicalCalendar\Api\Http\CookieHelper;
use LiturgicalCalendar\Api\Models\Auth\WsCaller;
use Psr\Http\Message\RequestInterface;

/**
 * Who is on the other end of a WebSocket handshake.
 *
 * Two things make this a separate service rather than a reuse of
 * {@see \LiturgicalCalendar\Api\Http\Middleware\OidcAuthMiddleware}:
 *
 *  1. The handshake carries a PSR-7 **`RequestInterface`**, not a `ServerRequestInterface` — Ratchet's
 *     `WsServer` assigns the raw upgrade request — so there is no `getCookieParams()` and the
 *     `Cookie:` header is parsed here.
 *  2. It runs inside a single-threaded ReactPHP loop, so it must not make a network call per
 *     connection. Roles are read from the token claim only; the Zitadel Management API lookup that
 *     `OidcAuthMiddleware` falls back to when a token carries no roles claim is deliberately absent.
 *     A token issued without that claim therefore reads as authenticated with no roles, and is
 *     refused — which is the fail-closed direction, and the only one acceptable here.
 *
 * Every failure yields {@see WsCaller::anonymous()}. Nothing here throws and nothing here closes a
 * connection: the connection is accepted either way, and the refusal happens per action.
 */
final class WsCallerResolver
{
    public function __construct(
        private readonly ?JwtService $jwtService,
        private readonly ?string $issuer,
        private readonly ?string $internalUrl,
        private readonly int $cacheTtl = 3600
    ) {
    }

    /**
     * Build from the environment, degrading rather than throwing.
     *
     * A missing `JWT_SECRET` or a missing `ZITADEL_ISSUER` disables that one verification path and
     * leaves the other working. Disabling both is legal and means every caller is anonymous — safe,
     * but total, which is why {@see self::describeAvailablePaths()} exists to say so at startup.
     */
    public static function fromEnv(): self
    {
        try {
            $jwtService = JwtServiceFactory::fromEnv();
        } catch (\Throwable) {
            $jwtService = null;
        }

        // Both tables are consulted for the same reason OidcAuthMiddleware consults both: Dotenv does
        // not always populate putenv().
        $issuerEnv      = getenv('ZITADEL_ISSUER') ?: ( $_ENV['ZITADEL_ISSUER'] ?? '' );
        $internalUrlEnv = getenv('ZITADEL_INTERNAL_URL') ?: ( $_ENV['ZITADEL_INTERNAL_URL'] ?? '' );

        $issuer      = is_string($issuerEnv) && '' !== $issuerEnv ? $issuerEnv : null;
        $internalUrl = is_string($internalUrlEnv) && '' !== $internalUrlEnv ? $internalUrlEnv : null;

        return new self($jwtService, $issuer, $internalUrl);
    }

    public function fromHandshake(?RequestInterface $request): WsCaller
    {
        if (null === $request) {
            return WsCaller::anonymous();
        }

        $cookies = self::parseCookieHeader($request->getHeaderLine('Cookie'));
        $token   = CookieHelper::getAccessToken($cookies);

        if (null === $token || '' === $token) {
            return WsCaller::anonymous();
        }

        return $this->fromToken($token);
    }

    /**
     * Zitadel first, legacy second — the same order `OidcAuthMiddleware` uses, and for the same
     * reason: a live deployment issues RS256, while HS256 tokens still exist and still work.
     */
    public function fromToken(string $token): WsCaller
    {
        if (null !== $this->issuer) {
            try {
                $payload = JWT::decode(
                    $token,
                    ZitadelKeySetFactory::for($this->issuer, $this->internalUrl, $this->cacheTtl)
                );
                $sub     = $payload->sub ?? null;
                if (is_string($sub) && '' !== $sub) {
                    return WsCaller::authenticated($sub, ZitadelRoles::fromPayload($payload));
                }
            } catch (\Throwable) {
                // Not a Zitadel token, or the key set is unreachable. Either way this is not the
                // place to distinguish them: fall through to the legacy path, and if that also
                // declines, the caller is anonymous.
            }
        }

        if (null !== $this->jwtService) {
            try {
                $payload = $this->jwtService->verify($token);
            } catch (\Throwable) {
                // `JwtService::verify()` catches the four JWT exceptions it expects, but
                // `JWT::decode()` also throws a bare `DomainException` for a payload that is not
                // valid UTF-8 — which any sufficiently mangled token produces. On the HTTP side that
                // surfaces as a 500 through the error middleware; here it would escape `onOpen()`
                // into the event loop, so it is caught and read as what it is: not a token.
                $payload = null;
            }

            if (null !== $payload) {
                $sub = $payload->sub ?? null;
                if (is_string($sub) && '' !== $sub) {
                    $roles = $payload->roles ?? [];
                    /** @var array<int, string> $roleList */
                    $roleList = is_array($roles) ? array_values(array_filter($roles, 'is_string')) : [];

                    return WsCaller::authenticated($sub, $roleList);
                }
            }
        }

        return WsCaller::anonymous();
    }

    /**
     * Parse a `Cookie:` header into a name => value map.
     *
     * Hand-rolled because the handshake request is a `RequestInterface`, which has no cookie
     * accessor at all. The shape is deliberately the one `CookieHelper` already expects, so the
     * cookie's *name* stays defined in exactly one place.
     *
     * @return array<string, string>
     */
    public static function parseCookieHeader(string $header): array
    {
        $cookies = [];

        foreach (explode(';', $header) as $pair) {
            $pair = trim($pair);
            if ('' === $pair || false === str_contains($pair, '=')) {
                continue;
            }

            [$name, $value] = explode('=', $pair, 2);

            $name = trim($name);
            if ('' === $name) {
                continue;
            }

            $cookies[$name] = urldecode(trim($value));
        }

        return $cookies;
    }

    /**
     * Fetch the JWKS once, off the event loop, so `onOpen()` never pays for it.
     *
     * @return bool Whether a key set was reached. False means RS256 verification is unavailable or
     *              not configured; it is never a reason to refuse to start the server.
     */
    public function warmKeySet(): bool
    {
        if (null === $this->issuer) {
            return false;
        }

        try {
            $keySet = ZitadelKeySetFactory::for($this->issuer, $this->internalUrl, $this->cacheTtl);
            // offsetExists() drives the fetch without needing to know a key id in advance. The
            // answer is irrelevant; reaching the endpoint at all is the point.
            $keySet->offsetExists('warm');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Which verification paths are live, for a startup log line.
     *
     * A deployment that has silently lost one of them degrades every caller it would have verified to
     * anonymous. That is the safe direction, but it is total and it is invisible from the outside —
     * every user simply stops being able to run anything — so the server says which paths it has.
     */
    public function describeAvailablePaths(): string
    {
        $paths = [];

        if (null !== $this->issuer) {
            $paths[] = 'Zitadel RS256';
        }

        if (null !== $this->jwtService) {
            $paths[] = 'legacy HS256';
        }

        return [] === $paths ? 'none (every caller will be anonymous)' : implode(' + ', $paths);
    }
}
