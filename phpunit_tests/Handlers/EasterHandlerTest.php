<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Handlers\EasterHandler;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(EasterHandler::class)]
final class EasterHandlerTest extends AbstractHandlerTestCase
{
    private const CACHE_DIR = 'engineCache/easter';

    protected function setUp(): void
    {
        parent::setUp();
        // Each test starts from a cold cache — the handler's ETag and 304
        // branches only run on cache misses.
        if (is_dir(self::CACHE_DIR)) {
            foreach ((array) glob(self::CACHE_DIR . '/*') as $file) {
                if (is_string($file) && is_file($file)) {
                    unlink($file);
                }
            }
        }
    }

    /**
     * A cache that cannot be written costs the client a cache entry, not its answer.
     *
     * This handler is the only one of its family that caches serialised output to disk, and it used
     * to `throw new ServiceUnavailableException('Failed to write cache file')` when the write failed
     * — so on a read-only rootfs (a hardened deployment, or the API's own docker image) `/easter`
     * alone answered **500**, and only for a representation with no pre-warmed file beside it. The
     * shipped image carries `en.json` and `la.json`, which is exactly why JSON looked healthy while
     * `Accept: application/yaml` did not: the first YAML request tried to create `la.yml` and died
     * with "Failed to open stream: Read-only file system".
     *
     * The unwritable directory is simulated by putting a *file* where the cache directory belongs,
     * which makes both `mkdir()` and `file_put_contents()` fail without needing root or a real
     * read-only mount, and without depending on the test runner's uid.
     */
    public function testAnUnwritableCacheDoesNotCostTheClientItsResponse(): void
    {
        $cacheDir = self::CACHE_DIR;
        if (is_dir($cacheDir)) {
            foreach ((array) glob($cacheDir . '/*') as $file) {
                if (is_string($file) && is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($cacheDir);
        }
        // A regular file where the directory should be: mkdir() fails, and so does every write into it.
        file_put_contents($cacheDir, 'not a directory');

        try {
            $response = @( new EasterHandler() )->handle(
                $this->requestFor('GET', '/easter', ['Accept-Language' => 'la'])
            );

            self::assertSame(200, $response->getStatusCode(), 'an unwritable cache must not become a 5xx');
            $body = $this->decodeJsonBody($response);
            self::assertArrayHasKey('litcal_easter', $body);
            self::assertNotEmpty($body['litcal_easter'], 'the response must be fully computed, not truncated');
            self::assertNotEmpty($response->getHeaderLine('ETag'), 'the ETag is computed from the body, not from the cache');
        } finally {
            if (is_file($cacheDir)) {
                unlink($cacheDir);
            }
        }
    }

    public function testOptionsPreflightSucceeds(): void
    {
        $response = ( new EasterHandler() )->handle(
            $this->requestFor('OPTIONS', '/easter', [
                'Origin'                        => 'https://app.example.test',
                'Access-Control-Request-Method' => 'GET',
            ])
        );

        self::assertSame(204, $response->getStatusCode());
    }

    public function testGetReturnsEasterDatesAndEtag(): void
    {
        // Latin path: avoids the IntlDateFormatter setup; uses LatinUtils.
        // Locale via Accept-Language so EasterParams picks it up.
        $response = ( new EasterHandler() )->handle(
            $this->requestFor('GET', '/easter', ['Accept-Language' => 'la'])
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertNotEmpty($response->getHeaderLine('ETag'));
        $body = $this->decodeJsonBody($response);
        self::assertArrayHasKey('litcal_easter', $body);
        self::assertNotEmpty($body['litcal_easter']);
        // 1583..9999 inclusive — exactly 8417 entries.
        self::assertCount(8417, $body['litcal_easter']);
        self::assertArrayHasKey('lastCoincidence', $body);
        self::assertArrayHasKey('lastCoincidenceString', $body);

        // Sanity-check one well-known date: Easter 2025 (Gregorian) is Apr 20.
        $entry2025 = $body['litcal_easter'][2025 - 1583];
        self::assertSame(
            ( new \DateTime('2025-04-20') )->format('U'),
            (string) $entry2025['gregorianEaster']
        );
    }

    public function testConditionalGetWithMatchingEtagReturns304(): void
    {
        // First (cold) call to learn the ETag. Important: the handler only
        // checks If-None-Match on the cache-miss path, so we must clear the
        // cache between the two calls to keep both runs cold.
        $first = ( new EasterHandler() )->handle(
            $this->requestFor('GET', '/easter', ['Accept-Language' => 'la'])
        );
        $etag  = trim($first->getHeaderLine('ETag'), ' "');
        self::assertNotEmpty($etag);

        unlink(self::CACHE_DIR . '/la.json');

        $second = ( new EasterHandler() )->handle(
            $this->requestFor('GET', '/easter', [
                'Accept-Language' => 'la',
                'If-None-Match'   => '"' . $etag . '"',
            ])
        );

        self::assertSame(304, $second->getStatusCode());
        self::assertSame('0', $second->getHeaderLine('Content-Length'));
    }
}
