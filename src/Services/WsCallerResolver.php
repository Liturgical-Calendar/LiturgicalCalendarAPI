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
    /**
     * @param string|null $clientId  Zitadel client id, accepted as a token audience.
     * @param string|null $projectId Zitadel project id, also accepted — Zitadel puts it in `aud` for
     *                               machine-to-machine tokens where a user token carries the client id.
     */
    public function __construct(
        private readonly ?JwtService $jwtService,
        private readonly ?string $issuer,
        private readonly ?string $internalUrl,
        private readonly int $cacheTtl = 3600,
        private readonly ?string $clientId = null,
        private readonly ?string $projectId = null
    ) {
    }

    /**
     * Whether a decoded Zitadel payload was actually issued *to this application, by this provider*.
     *
     * A signature proves a token is genuine, not that it is meant for you. Zitadel signs every token
     * in an instance with the same keys, so without this check any correctly-signed token from any
     * other application in the same instance verifies here — and if it happens to carry `admin` or
     * `test_editor` among its project roles, it is handed permission to start runs. That is a
     * confused-deputy hole, not a theoretical one.
     *
     * `OidcAuthMiddleware::tryOidcValidation()` performs exactly these two checks after decoding;
     * this is the same rule, kept as a pure function so both the WebSocket path and its tests can
     * reach it without a live provider.
     *
     * @param array<int, string> $validAudiences The client id and project id, empty entries removed.
     */
    public static function isIntendedFor(object $payload, string $issuer, array $validAudiences): bool
    {
        // An unconfigured audience list would accept everything, so it accepts nothing instead.
        if ([] === $validAudiences) {
            return false;
        }

        $iss = $payload->iss ?? null;
        if (false === is_string($iss) || rtrim($iss, '/') !== rtrim($issuer, '/')) {
            return false;
        }

        $aud = $payload->aud ?? null;

        if (is_string($aud)) {
            return in_array($aud, $validAudiences, true);
        }

        if (is_array($aud)) {
            return [] !== array_intersect(array_filter($aud, 'is_string'), $validAudiences);
        }

        return false;
    }

    /**
     * The audiences a token may name to be accepted here.
     *
     * @return array<int, string>
     */
    private function validAudiences(): array
    {
        return array_values(array_filter(
            [$this->clientId, $this->projectId],
            static fn(?string $v): bool => is_string($v) && '' !== $v
        ));
    }

    /**
     * Whether the RS256 path can reach a trustworthy verdict at all.
     *
     * An issuer with no audience to check against cannot: it could verify that a token is genuine but
     * not that it was meant for this application, and accepting on that basis is worse than not
     * accepting at all. So the path is reported and treated as unavailable rather than as permissive.
     */
    private function zitadelUsable(): bool
    {
        return null !== $this->issuer && [] !== $this->validAudiences();
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
        $clientIdEnv    = getenv('ZITADEL_CLIENT_ID') ?: ( $_ENV['ZITADEL_CLIENT_ID'] ?? '' );
        $projectIdEnv   = getenv('ZITADEL_PROJECT_ID') ?: ( $_ENV['ZITADEL_PROJECT_ID'] ?? '' );

        $issuer      = is_string($issuerEnv) && '' !== $issuerEnv ? $issuerEnv : null;
        $internalUrl = is_string($internalUrlEnv) && '' !== $internalUrlEnv ? $internalUrlEnv : null;
        $clientId    = is_string($clientIdEnv) && '' !== $clientIdEnv ? $clientIdEnv : null;
        $projectId   = is_string($projectIdEnv) && '' !== $projectIdEnv ? $projectIdEnv : null;

        return new self($jwtService, $issuer, $internalUrl, 3600, $clientId, $projectId);
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
        if (null !== $this->issuer && $this->zitadelUsable()) {
            try {
                $payload = JWT::decode(
                    $token,
                    ZitadelKeySetFactory::for($this->issuer, $this->internalUrl, $this->cacheTtl)
                );
                $sub     = $payload->sub ?? null;
                // The signature says the token is genuine; `isIntendedFor()` says it was issued to
                // this application. Both are required, and skipping the second would accept any
                // correctly-signed token from any other app in the same Zitadel instance.
                if (
                    is_string($sub) && '' !== $sub
                    && self::isIntendedFor($payload, $this->issuer, $this->validAudiences())
                ) {
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
        // Nothing to warm for a path that will not be used. Warming an unaudienced issuer would
        // fetch keys this resolver has already decided it cannot trust a token against.
        if (null === $this->issuer || false === $this->zitadelUsable()) {
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

        if ($this->zitadelUsable()) {
            $paths[] = 'Zitadel RS256';
        } elseif (null !== $this->issuer) {
            // Named explicitly, because this is the configuration most likely to look correct and
            // behave as though Zitadel were switched off: an issuer is set, so nothing looks missing.
            $paths[] = 'Zitadel RS256 DISABLED (ZITADEL_ISSUER is set but neither ZITADEL_CLIENT_ID nor ZITADEL_PROJECT_ID is, so no token audience can be checked)';
        }

        if (null !== $this->jwtService) {
            $paths[] = 'legacy HS256';
        }

        return [] === $paths ? 'none (every caller will be anonymous)' : implode(' + ', $paths);
    }
}
