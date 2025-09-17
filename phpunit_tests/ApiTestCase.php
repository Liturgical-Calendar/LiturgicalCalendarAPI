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

    private static ?\Throwable $lastException = null;

    private static bool $preferV4;
    private static string $addr;

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

        if (self::isIPAddress($_ENV['API_HOST'])) {
            // Already an IP — detect family directly
            if (filter_var($_ENV['API_HOST'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                $preferV4   = false;
                self::$addr = $_ENV['API_HOST'];
            } elseif (filter_var($_ENV['API_HOST'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $preferV4   = true;
                self::$addr = $_ENV['API_HOST'];
            }
        } else {
            // Hostname — detect preferred stack via DNS resolution
            $records  = dns_get_record($_ENV['API_HOST'], DNS_A + DNS_AAAA);
            $preferV4 = false;
            if ($records && !empty($records)) {
                $first = $records[0];
                if ($first['type'] === 'A') {
                    $preferV4   = true;
                    self::$addr = $first['ip'];
                } elseif ($first['type'] === 'AAAA') {
                    $preferV4   = false;
                    self::$addr = $first['ipv6'];
                }
            }
        }
        self::$preferV4 = $preferV4;

        self::$http = new Client([
            'base_uri'         => sprintf('%s://%s:%s', $_ENV['API_PROTOCOL'], $_ENV['API_HOST'], $_ENV['API_PORT']),
            'handler'          => $stack,
            'timeout'          => 60,
            'connect_timeout'  => 5,
            'http_errors'      => false,
            'headers'          => [ 'Connection' => 'keep-alive' ],
            'curl'             => [ CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0 ],
            'force_ip_resolve' => $preferV4 ? 'v4' : 'v6'
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
            self::$apiAvailable  = false;
            self::$lastException = $e;
        }
    }

    protected function setUp(): void
    {
        if (! self::$apiAvailable) {
            // We use `fail` instead of `markSkipped` because we want the message to show without the `--debug` flag,
            // but `markSkipped` only shows the message with `--debug`
            $this->fail(
                "API is not running on {$_ENV['API_PROTOCOL']}://{$_ENV['API_HOST']}:{$_ENV['API_PORT']} (bound to " . ( self::$preferV4 ? 'IPv4' : 'IPv6' ) . ' address ' . self::$addr . ') — skipping integration tests. Maybe run `composer start` first?' . PHP_EOL . ( self::$lastException ? 'Error: ' . self::$lastException->getMessage() : '' )
            );
        }

        if (self::$transferStats === null || ( self::$transferStats !== 2 && self::$transferStats !== 3 )) {
            $this->fail(
                'Expected HTTP2 or HTTP3 transport, but got ' . ( self::$transferStats ?? 'unknown' )
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

    private static function isIPAddress(string $host): bool
    {
        // Strip IPv6 brackets if present
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }

        return filter_var($host, FILTER_VALIDATE_IP) !== false;
    }
}
