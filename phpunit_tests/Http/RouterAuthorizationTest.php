<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Http;

use PHPUnit\Framework\TestCase;

final class RouterAuthorizationTest extends TestCase
{
    /**
     * The set of routes that require auth for write methods must include the
     * General Roman Calendar write routes.
     */
    public function testProtectedWriteRoutesIncludeGrcRoutes(): void
    {
        $src = file_get_contents(__DIR__ . '/../../src/Router.php');
        self::assertIsString($src);
        // Guard list literal must contain all five route names.
        self::assertMatchesRegularExpression(
            "/in_array\\(\\s*\\\$route,\\s*\\['data',\\s*'tests',\\s*'temporale',\\s*'missals',\\s*'decrees'\\]/",
            $src
        );
        // temporale must no longer be admin-only.
        self::assertStringNotContainsString('// Temporale requires admin role', $src);
    }
}
