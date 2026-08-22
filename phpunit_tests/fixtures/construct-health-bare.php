<?php

/**
 * Construct a Health the way the real entry point does: with nothing initialised beforehand.
 *
 * `bin/LitCalTestServer.php` requires the autoloader and calls `new Health()` — it does not call
 * `Router::getApiPaths()` first, and never did. PHPUnit's bootstrap *does*, long before any test
 * builds a Health, so the two orders differ and only this one matches production.
 *
 * Run as its own process by {@see \LiturgicalCalendar\Tests\HealthConstructionOrderTest}. It lives
 * under the project root rather than in a temp directory because `Router::getApiPaths()` locates the
 * project by walking up from `$_SERVER['argv'][0]` looking for composer.json.
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

new \LiturgicalCalendar\Api\Health();

echo 'CONSTRUCTED';
