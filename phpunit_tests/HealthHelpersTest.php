<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests;

use LiturgicalCalendar\Api\Health;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Targeted coverage for two pure static helpers on the WebSocket Health component:
 *  - isInternalApiUrl(): the host check that keeps the first-party WS_API_KEY from leaking to an
 *    arbitrary absolute URL validated via executeValidation.
 *  - isUpstreamFailureStatus(): which upstream HTTP statuses (429, 5xx) are rejected versus flowed
 *    through to per-format validation (e.g. a 404 for an unknown calendar).
 *
 * Both are dependency-free of the Ratchet/Guzzle machinery, so they are exercised directly via
 * reflection without standing up the WebSocket server.
 */
#[CoversClass(Health::class)]
final class HealthHelpersTest extends TestCase
{
    private ?string $apiHostBackup = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->apiHostBackup = isset($_ENV['API_HOST']) && is_string($_ENV['API_HOST']) ? $_ENV['API_HOST'] : null;
    }

    protected function tearDown(): void
    {
        if ($this->apiHostBackup === null) {
            unset($_ENV['API_HOST']);
        } else {
            $_ENV['API_HOST'] = $this->apiHostBackup;
        }
        parent::tearDown();
    }

    private static function isInternalApiUrl(string $url): bool
    {
        $method = new \ReflectionMethod(Health::class, 'isInternalApiUrl');
        /** @var bool $result */
        $result = $method->invoke(null, $url);

        return $result;
    }

    private static function isUpstreamFailureStatus(int $statusCode): bool
    {
        $method = new \ReflectionMethod(Health::class, 'isUpstreamFailureStatus');
        /** @var bool $result */
        $result = $method->invoke(null, $statusCode);

        return $result;
    }

    public function testRelativeUrlIsInternal(): void
    {
        $_ENV['API_HOST'] = 'litcal.example.org';
        $this->assertTrue(self::isInternalApiUrl('/calendar/2020?year_type=CIVIL'));
    }

    public function testMatchingHostIsInternalCaseInsensitive(): void
    {
        $_ENV['API_HOST'] = 'litcal.example.org';
        $this->assertTrue(self::isInternalApiUrl('https://litcal.example.org/api/dev/calendar/2020'));
        $this->assertTrue(self::isInternalApiUrl('https://LITCAL.EXAMPLE.ORG/api/dev/calendar/2020'));
    }

    public function testForeignHostIsNotInternal(): void
    {
        $_ENV['API_HOST'] = 'litcal.example.org';
        $this->assertFalse(self::isInternalApiUrl('https://evil.example.com/api/dev/calendar/2020'));
    }

    public function testDefaultsToLocalhostWhenApiHostUnset(): void
    {
        unset($_ENV['API_HOST']);
        $this->assertTrue(self::isInternalApiUrl('http://localhost:8000/api/dev/calendar/2020'));
        $this->assertFalse(self::isInternalApiUrl('https://litcal.example.org/api/dev/calendar/2020'));
    }

    /**
     * @return array<string, array{int, bool}>
     */
    public static function statusProvider(): array
    {
        return [
            '200 ok'           => [200, false],
            '301 moved'        => [301, false],
            '404 not found'    => [404, false],
            '418 teapot'       => [418, false],
            '429 rate limited' => [429, true],
            '500 server error' => [500, true],
            '503 unavailable'  => [503, true],
        ];
    }

    #[DataProvider('statusProvider')]
    public function testUpstreamFailureStatus(int $statusCode, bool $expected): void
    {
        $this->assertSame($expected, self::isUpstreamFailureStatus($statusCode));
    }
}
