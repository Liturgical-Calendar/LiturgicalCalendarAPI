<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests;

use LiturgicalCalendar\Api\Health;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * `new Health()` must work with nothing initialised first, because that is what the entry point does.
 *
 * **This test exists because the suite could not see the bug it guards.** `Health::__construct()`
 * builds a `WebSocketMessageValidator`, whose constructor resolves the message schema's path through
 * `Router::$apiFilePath` — a *typed* static, so reading it before assignment is a fatal `Error`, not
 * a null. `bin/LitCalTestServer.php` calls `new Health()` without calling `Router::getApiPaths()`
 * first; `Health::onOpen()` was where paths got initialised, on the first client connection. So the
 * WebSocket server died at startup, every time, on a code path 447 in-process tests never touched.
 *
 * They never touched it because `phpunit_tests/bootstrap.php` initialises the paths before any test
 * runs. Every test therefore constructs a `Health` in an order production never uses. A test written
 * inside this process — however carefully — would assert against that initialised state and pass.
 *
 * Hence the subprocess. The fixture requires nothing but the autoloader, exactly as the entry point
 * does, and this asserts it survives. It is the cheapest thing that could have caught the failure.
 */
#[CoversClass(Health::class)]
final class HealthConstructionOrderTest extends TestCase
{
    public function testAHealthCanBeConstructedWithNothingInitialisedFirst(): void
    {
        $projectRoot = dirname(__DIR__);
        $fixture     = $projectRoot . '/phpunit_tests/fixtures/construct-health-bare.php';
        self::assertFileExists($fixture);

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process     = proc_open(
            [PHP_BINARY, $fixture],
            $descriptors,
            $pipes,
            $projectRoot
        );
        self::assertIsResource($process, 'could not start the subprocess');

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        self::assertSame(
            0,
            $exitCode,
            "constructing a Health in a bare process failed — this is what the WebSocket server does at startup:\n{$stderr}"
        );
        self::assertStringContainsString('CONSTRUCTED', $stdout);
        self::assertStringNotContainsString(
            'must not be accessed before initialization',
            $stderr,
            'a typed static was read before it was assigned; Health::__construct() resolves a path, so it must initialise the paths first'
        );
    }
}
