<?php

namespace LiturgicalCalendar\Tests;

use PHPUnit\Framework\TestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;

abstract class ApiTestCase extends TestCase
{
    protected static bool $apiAvailable = false;

    protected ?Client $http = null;

    public static function setUpBeforeClass(): void
    {
        // Validate required environment variables
        $requiredEnvVars = ['API_PROTOCOL', 'API_HOST', 'API_PORT'];
        foreach ($requiredEnvVars as $var) {
            if (empty($_ENV[$var])) {
                throw new \RuntimeException("Required environment variable {$var} is not set");
            }
        }

        $client = new Client([
            'base_uri'    => "{$_ENV['API_PROTOCOL']}://{$_ENV['API_HOST']}:{$_ENV['API_PORT']}",
            'http_errors' => false,
            'timeout'     => 2.0,
        ]);

        try {
            // Simple check — adjust path if your API root needs authentication
            $response           = $client->get('/');
            self::$apiAvailable = $response->getStatusCode() < 500;
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

        $this->http = new Client([
            'base_uri'    => "{$_ENV['API_PROTOCOL']}://{$_ENV['API_HOST']}:{$_ENV['API_PORT']}",
            'http_errors' => false,
            'timeout'     => 2.0,
        ]);
    }

    protected function tearDown(): void
    {
        $this->http = null;
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
