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
