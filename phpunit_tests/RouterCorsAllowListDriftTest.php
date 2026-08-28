<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests;

use LiturgicalCalendar\Api\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A route whose handler allows credentials but which Router never hands the origin
 * allow-list to.
 *
 * This is the failure /tests was. It enabled credentials for cookie-authenticated writes
 * from the admin-tests page, but its `case` in Router::route() never called
 * setAllowedOrigins(), so the handler kept AbstractHandler's default of ['*'] and echoed
 * back whatever Origin it was given, with Access-Control-Allow-Credentials: true. Nothing
 * failed; the route simply never consulted the configured list. Divergence between "this
 * handler allows credentials" and "Router restricts its origins" was silent, and stayed
 * silent until someone went looking. Here it is a red test.
 *
 * Router::route() constructs handlers, emits and calls die(), so it cannot be exercised
 * in-process (see RouterTest's docblock). The wiring is therefore asserted against the
 * source of each route's `case` block, which is the only place the omission is visible.
 *
 * Only these routes are asserted. The /auth and /admin routes also construct
 * credential-allowing handlers and are also unwired, and are deliberately NOT listed: they
 * are a larger change with its own blast radius, not a forgotten line, and asserting them
 * here would turn this into a permanently red test rather than a drift guard. They are
 * covered instead by the access token being SameSite=Lax, which keeps a cross-site fetch
 * from carrying the cookie at all.
 */
#[CoversClass(Router::class)]
final class RouterCorsAllowListDriftTest extends TestCase
{
    /**
     * Routes whose handler sets allowCredentials and which accept write methods.
     *
     * @return array<string,array{string}>
     */
    public static function credentialedWriteRouteProvider(): array
    {
        return [
            'missals'   => ['missals'],
            'decrees'   => ['decrees'],
            'data'      => ['data'],
            'temporale' => ['temporale'],
            'tests'     => ['tests'],
        ];
    }

    private static function routerSource(): string
    {
        $path = dirname(__DIR__) . '/src/Router.php';
        $src  = file_get_contents($path);
        self::assertIsString($src, "could not read {$path}");
        return $src;
    }

    /**
     * Extract the body of a single `case '<route>':` block from Router::route()'s switch,
     * up to the `break;` that closes it.
     */
    private static function caseBlock(string $route): string
    {
        $src   = self::routerSource();
        $start = strpos($src, "case '{$route}':");
        self::assertNotFalse($start, "no `case '{$route}':` block found in Router.php");

        $end = strpos($src, 'break;', $start);
        self::assertNotFalse($end, "`case '{$route}':` has no closing break;");

        return substr($src, $start, $end - $start);
    }

    #[DataProvider('credentialedWriteRouteProvider')]
    public function testRouteHandsTheOriginAllowListToItsHandler(string $route): void
    {
        self::assertStringContainsString(
            'setAllowedOrigins($allowedOrigins)',
            self::caseBlock($route),
            "the `{$route}` route allows credentials on writes but never passes the configured "
            . 'origin allow-list to its handler, so it will echo any Origin it is given'
        );
    }

    /**
     * The allow-list must be gated on the shared helper, not on an inline method check:
     * an inline `in_array($method, [PUT, PATCH, DELETE])` silently excludes the OPTIONS
     * preflight, which is the only point a cross-origin write can actually be refused.
     */
    #[DataProvider('credentialedWriteRouteProvider')]
    public function testRouteGatesTheAllowListOnTheSharedHelper(string $route): void
    {
        self::assertStringContainsString(
            'Router::restrictsOriginsForWrite(',
            self::caseBlock($route),
            "the `{$route}` route must gate its allow-list on restrictsOriginsForWrite(), "
            . 'so the write preflight is covered and not just the write itself'
        );
    }
}
