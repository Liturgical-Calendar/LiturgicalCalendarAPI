<?php

namespace LiturgicalCalendar\Tests;

use PHPUnit\Framework\TestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\HandlerStack;

abstract class ApiTestCase extends TestCase
{
    protected static bool $apiAvailable = false;

    protected static ?int $transferStats = null;

    protected static ?Client $http = null;

    protected static ?CurlMultiHandler $multiHandler = null;

    public static function setUpBeforeClass(): void
    {
        // Create a shared CurlMultiHandler that will persist connections
        self::$multiHandler = new CurlMultiHandler(['max_handles' => 50]); // pool size; tune as needed

        $stack = HandlerStack::create(self::$multiHandler);

        // Validate required environment variables
        $requiredEnvVars = ['API_PROTOCOL', 'API_HOST', 'API_PORT'];
        foreach ($requiredEnvVars as $var) {
            if (empty($_ENV[$var])) {
                throw new \RuntimeException("Required environment variable {$var} is not set");
            }
        }

        self::$http = new Client([
            'base_uri'        => sprintf('%s://%s:%s', $_ENV['API_PROTOCOL'], $_ENV['API_HOST'], $_ENV['API_PORT']),
            'handler'         => $stack,
            'timeout'         => 60,
            'connect_timeout' => 5,
            'http_errors'     => false,
            'headers'         => [ 'Connection' => 'keep-alive' ],
            'curl'            => [ CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0 ]
        ]);

        try {
            // Simple check — adjust path if your API root needs authentication
            $response           = self::$http->get('/', [
                'on_stats' => function (\GuzzleHttp\TransferStats $stats) {
                    self::$transferStats = $stats->getHandlerStat('http_version');
                }
            ]);
            self::$apiAvailable = $response->getStatusCode() < 500;
            //$response           = self::$http->get('/');
        } catch (ConnectException $e) {
            self::$apiAvailable = false;
        }
    }

    protected function setUp(): void
    {
        if (! self::$apiAvailable) {
            // We use `fail` instead of `markSkipped` because we want the message to show without the `--debug` flag,
            // but `markSkipped` only shows the message with `--debug`
            $this->fail(
                "API is not running on {$_ENV['API_PROTOCOL']}://{$_ENV['API_HOST']}:{$_ENV['API_PORT']} — skipping integration tests. Maybe run `composer start` first?"
            );
        }
        if (self::$transferStats === null || self::$transferStats !== 2) {
            $this->fail(
                'Expected HTTP2 transport, but got ' . ( self::$transferStats ?? 'unknown' )
            );
        }
    }

    protected function tearDown(): void
    {
        // After each test method, tick until idle
        if (self::$multiHandler) {
            do {
                $stillRunning = self::$multiHandler->tick();
            } while ($stillRunning > 0);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$multiHandler) {
            // Tick until all pending curl handles are processed
            do {
                $stillRunning = self::$multiHandler->tick();
            } while ($stillRunning > 0);

            self::$multiHandler = null;
        }

        self::$http = null;
    }

    protected static function findProjectRoot(string $startDir = __DIR__, string $marker = 'composer.json'): ?string
    {
        $dir = $startDir;

        while (true) {
            if (file_exists($dir . DIRECTORY_SEPARATOR . $marker)) {
                return $dir;
            }

            $parentDir = dirname($dir);
            if ($parentDir === $dir) { // reached the project root
                return null;
            }

            $dir = $parentDir;
        }
    }
}
