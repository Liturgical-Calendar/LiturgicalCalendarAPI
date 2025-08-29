<?php

namespace LiturgicalCalendar\Tests\Methods;

use PHPUnit\Framework\TestCase;
use LiturgicalCalendar\Api\Router;
use Dotenv\Dotenv;

class RouterTest extends TestCase
{
    public function testGetApiPaths()
    {
        // Set default environment variables
        $_ENV['API_PROTOCOL']  = 'http';
        $_ENV['API_HOST']      = 'localhost';
        $_ENV['API_PORT']      = '8000';
        $_ENV['API_BASE_PATH'] = '/';

        // Call getApiPaths()
        Router::getApiPaths();

        // Assert resulting URL
        $this->assertEquals('http://localhost:8000', Router::$apiPath);
        $this->assertEquals('/', Router::$apiBase);

        // Override environment variables
        $_ENV['API_PROTOCOL']  = 'https';
        $_ENV['API_HOST']      = 'mydomain.com';
        $_ENV['API_PORT']      = '443';
        $_ENV['API_BASE_PATH'] = '/api/v1/';

        // Call getApiPaths() again
        Router::getApiPaths();

        // Assert resulting URL
        $this->assertEquals('https://mydomain.com/api/v1', Router::$apiPath);
        $this->assertEquals('/api/v1/', Router::$apiBase);
    }

    public function tearDown(): void
    {
        // Clean up environment variables after each test
        $dotenv = Dotenv::createMutable(self::getProjectRoot(), ['.env', '.env.local', '.env.development', '.env.production'], false);
        $dotenv->safeLoad();
    }

    private function getProjectRoot(): string
    {
        $projectFolder = __DIR__;
        $level         = 0;
        while (true) {
            if (file_exists($projectFolder . DIRECTORY_SEPARATOR . 'composer.json')) {
                return $projectFolder;
            }

            // Don't look more than 4 levels up
            if ($level > 4) {
                throw new \Exception('Unable to find project root folder.');
            }

            $parentDir = dirname($projectFolder);
            if ($parentDir === $projectFolder) { // reached the system root folder
                throw new \Exception('Unable to find project root folder.');
            }

            ++$level;
            $projectFolder = $parentDir;
        }
        return $projectFolder;
    }
}
