<?php

namespace LiturgicalCalendar\Tests\Methods;

use PHPUnit\Framework\TestCase;
use LiturgicalCalendar\Api\Router as ApiRouter;

class RouterTest extends TestCase
{
    public function testGetApiPaths()
    {
        // Set default environment variables
        putenv('API_PROTOCOL=http');
        putenv('API_HOST=localhost');
        putenv('API_PORT=8000');
        putenv('API_BASE_PATH=/');

        // Call getApiPaths()
        ApiRouter::getApiPaths();

        // Assert resulting URL
        $this->assertEquals('http://localhost:8000/', ApiRouter::$apiPath);

        // Override environment variables
        putenv('API_PROTOCOL=https');
        putenv('API_HOST=mydomain.com');
        putenv('API_PORT=443');
        putenv('API_BASE_PATH=/api/v1');

        // Call getApiPaths() again
        ApiRouter::getApiPaths();

        // Assert resulting URL
        $this->assertEquals('https://mydomain.com/api/v1', ApiRouter::$apiPath);
    }
}
