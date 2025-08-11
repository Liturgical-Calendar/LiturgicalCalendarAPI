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
        $client = new Client([
            'base_uri'    => "{$_ENV['API_PROTOCOL']}://{$_ENV['API_HOST']}:{$_ENV['API_PORT']}",
            'http_errors' => false,
            'timeout'     => 2.0,
        ]);

        try {
            // Simple check — adjust path if your API root needs authentication
            $client->get('/');
            self::$apiAvailable = true;
        } catch (ConnectException $e) {
            self::$apiAvailable = false;
        }
    }

    protected function setUp(): void
    {
        if (! self::$apiAvailable) {
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
}
