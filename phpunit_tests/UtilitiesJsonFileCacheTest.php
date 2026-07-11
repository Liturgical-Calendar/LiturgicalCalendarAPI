<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests;

use LiturgicalCalendar\Api\Utilities;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Utilities::invalidateJsonFileCache().
 *
 * Verifies that after a JSON file is rewritten on disk,
 * calling invalidateJsonFileCache() causes the next read
 * via jsonFileToObjectArray() / jsonFileToArray() / jsonFileToObject()
 * to return fresh data rather than the stale cached value.
 *
 * These tests only run when the APCu extension is present AND
 * apc.enable_cli=1 is set (required for APCu to work in CLI processes).
 * Without those conditions the tests are skipped — the CI environment
 * supplies both, which is the environment where the original bug manifested.
 *
 * To run locally with APCu (if the extension is installed):
 *   php -d apc.enable_cli=1 vendor/bin/phpunit phpunit_tests/UtilitiesJsonFileCacheTest.php
 *
 * @requires extension apcu
 */
final class UtilitiesJsonFileCacheTest extends TestCase
{
    private ?string $tmpFile = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Skip when apc.enable_cli is off — apcu_fetch always misses in CLI without it.
        if (!function_exists('apcu_enabled') || !apcu_enabled()) {
            $this->markTestSkipped('APCu is installed but apc.enable_cli=1 is required. Run with: php -d apc.enable_cli=1 vendor/bin/phpunit phpunit_tests/UtilitiesJsonFileCacheTest.php');
        }

        // Create a temporary JSON file for the tests.
        $this->tmpFile = sys_get_temp_dir() . '/litcal_utilities_cache_test_' . uniqid() . '.json';
    }

    protected function tearDown(): void
    {
        if ($this->tmpFile !== null && file_exists($this->tmpFile)) {
            unlink($this->tmpFile);
        }
        parent::tearDown();
    }

    /**
     * Primes the APCu cache via jsonFileToObjectArray(), rewrites the file,
     * asserts the stale value is still served (proving the cache is active),
     * then invalidates and asserts the fresh value is served.
     */
    public function testInvalidateJsonFileCacheClearsObjectArrayCache(): void
    {
        // Write the initial JSON — an array of objects.
        $initial = [['key' => 'original_value']];
        file_put_contents($this->tmpFile, json_encode($initial));

        // Prime the APCu cache.
        $first = Utilities::jsonFileToObjectArray($this->tmpFile);
        $this->assertCount(1, $first);
        $this->assertSame('original_value', $first[0]->key);

        // Rewrite the file on disk without invalidating — the next read should still
        // serve the stale cached data, confirming the cache is actually in effect.
        $updated = [['key' => 'updated_value']];
        file_put_contents($this->tmpFile, json_encode($updated));

        $stale = Utilities::jsonFileToObjectArray($this->tmpFile);
        $this->assertSame(
            'original_value',
            $stale[0]->key,
            'Without invalidation, APCu must serve the stale cached value (proves cache is active)'
        );

        // Now invalidate and verify that fresh data is returned.
        Utilities::invalidateJsonFileCache($this->tmpFile);

        $fresh = Utilities::jsonFileToObjectArray($this->tmpFile);
        $this->assertSame(
            'updated_value',
            $fresh[0]->key,
            'After invalidation, jsonFileToObjectArray() must return the rewritten file contents'
        );
    }

    /**
     * Same scenario but exercising jsonFileToArray() (key prefix: jsoncache_array_).
     */
    public function testInvalidateJsonFileCacheClearsArrayCache(): void
    {
        $initial = ['greeting' => 'hello'];
        file_put_contents($this->tmpFile, json_encode($initial));

        // Prime.
        $first = Utilities::jsonFileToArray($this->tmpFile);
        $this->assertSame('hello', $first['greeting']);

        // Rewrite without invalidating.
        file_put_contents($this->tmpFile, json_encode(['greeting' => 'world']));

        $stale = Utilities::jsonFileToArray($this->tmpFile);
        $this->assertSame(
            'hello',
            $stale['greeting'],
            'Without invalidation, APCu must serve the stale cached value'
        );

        // Invalidate and read fresh.
        Utilities::invalidateJsonFileCache($this->tmpFile);

        $fresh = Utilities::jsonFileToArray($this->tmpFile);
        $this->assertSame(
            'world',
            $fresh['greeting'],
            'After invalidation, jsonFileToArray() must return the rewritten file contents'
        );
    }

    /**
     * Same scenario but exercising jsonFileToObject() (key prefix: jsoncache_object_).
     */
    public function testInvalidateJsonFileCacheClearsObjectCache(): void
    {
        file_put_contents($this->tmpFile, json_encode(['status' => 'initial']));

        // Prime.
        $first = Utilities::jsonFileToObject($this->tmpFile);
        $this->assertSame('initial', $first->status);

        // Rewrite without invalidating.
        file_put_contents($this->tmpFile, json_encode(['status' => 'refreshed']));

        $stale = Utilities::jsonFileToObject($this->tmpFile);
        $this->assertSame(
            'initial',
            $stale->status,
            'Without invalidation, APCu must serve the stale cached value'
        );

        // Invalidate and read fresh.
        Utilities::invalidateJsonFileCache($this->tmpFile);

        $fresh = Utilities::jsonFileToObject($this->tmpFile);
        $this->assertSame(
            'refreshed',
            $fresh->status,
            'After invalidation, jsonFileToObject() must return the rewritten file contents'
        );
    }
}
