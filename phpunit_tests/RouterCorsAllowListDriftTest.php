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
}
