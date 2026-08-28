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
 * Two shapes are asserted, because the routes differ in kind.
 *
 * The public routes carry an anonymous read, so their allow-list is gated on
 * restrictsOriginsForWrite(): a cross-origin GET must stay open, and only writes and the
 * preflights that clear them are restricted.
 *
 * /auth and /admin have no anonymous read at all — every method is cookie-authenticated —
 * so their allow-list applies unconditionally. Gating those on the write helper would leave
 * POST /auth/login and GET /auth/me reflecting any Origin with credentials, which is the
 * state they were in before they were wired.
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

    /**
     * The guard and the call must be one relation, not two facts.
     *
     * Asserting merely that each string appears somewhere in the route block is too weak:
     * a route could call setAllowedOrigins($allowedOrigins) unconditionally, or from an
     * unrelated branch, and still satisfy both. What matters is that the shared helper and
     * the localhost check together *guard* the assignment — that is the shape that was
     * missing for /tests, and the shape a future route could get wrong while still
     * mentioning all the right identifiers. testThePatternRejectsADecoupledGuard below
     * pins that this pattern actually discriminates.
     */
    private const GUARDED_CALL = '/Router::restrictsOriginsForWrite\(.*?\)\s*&&\s*false\s*===\s*Router::isLocalhost\(\)\s*\)\s*\{\s*\$\w+->setAllowedOrigins\(\$allowedOrigins\);/s';

    #[DataProvider('credentialedWriteRouteProvider')]
    public function testRouteGuardsTheAllowListCallWithTheSharedHelper(string $route): void
    {
        self::assertMatchesRegularExpression(
            self::GUARDED_CALL,
            self::caseBlock($route),
            "the `{$route}` route must pass the configured origin allow-list to its handler from "
            . 'inside the restrictsOriginsForWrite() + isLocalhost() guard, so that the write '
            . 'preflight is covered and the call cannot drift outside the condition'
        );
    }

    /**
     * The guard pattern is only worth anything if it rejects the shape it exists to catch:
     * both identifiers present, but the allow-list applied outside the condition.
     */
    public function testThePatternRejectsADecoupledGuard(): void
    {
        $decoupled = <<<'PHP'
        case 'bogus':
            $bogusHandler = new BogusHandler();
            if (
                Router::restrictsOriginsForWrite(
                    $this->request->getMethod(),
                    $this->request->getHeaderLine('Access-Control-Request-Method')
                )
                && false === Router::isLocalhost()
            ) {
                $somethingUnrelated = true;
            }
            $bogusHandler->setAllowedOrigins($allowedOrigins);
        PHP;

        self::assertDoesNotMatchRegularExpression(self::GUARDED_CALL, $decoupled);
    }

    /**
     * Routes with no anonymous read: every method is credentialed, so the allow-list is
     * not gated on the method at all.
     *
     * @return array<string,array{string}>
     */
    public static function credentialedPrivateRouteProvider(): array
    {
        return [
            'auth'  => ['auth'],
            'admin' => ['admin'],
        ];
    }

    /**
     * The private routes resolve a handler across many branches, so the allow-list is
     * applied once after the sub-dispatch. What must not drift is that it is applied at
     * all, and that it is still behind the localhost bypass.
     */
    private const PRIVATE_GUARDED_CALL = '/Router::restrictOriginsForPrivateRoute\(\$this->handler, \$allowedOrigins\);/';

    #[DataProvider('credentialedPrivateRouteProvider')]
    public function testPrivateRouteRestrictsOriginsForEveryMethod(string $route): void
    {
        self::assertMatchesRegularExpression(
            self::PRIVATE_GUARDED_CALL,
            self::caseBlock($route),
            "the `{$route}` route is credentialed on every method and must hand the configured "
            . 'origin allow-list to whichever handler it resolved'
        );
    }

    /**
     * The private helper must not be gated on restrictsOriginsForWrite(): that would restrict
     * only PUT/PATCH/DELETE and leave POST /auth/login and GET /auth/me open to any origin.
     */
    public function testPrivateRouteHelperIsNotGatedOnTheWriteMethodCheck(): void
    {
        $src   = self::routerSource();
        $start = strpos($src, 'public static function restrictOriginsForPrivateRoute(');
        self::assertNotFalse($start, 'restrictOriginsForPrivateRoute() not found');
        $body = substr($src, $start, 900);

        self::assertStringNotContainsString(
            'restrictsOriginsForWrite',
            $body,
            'a credentialed route with no anonymous read must restrict every method, not only writes'
        );
        self::assertStringContainsString('Router::isLocalhost()', $body);
        self::assertStringContainsString('setAllowedOrigins($allowedOrigins)', $body);
    }
}
