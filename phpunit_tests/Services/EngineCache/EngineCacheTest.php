<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services\EngineCache;

use LiturgicalCalendar\Api\Http\Exception\ServiceUnavailableException;
use LiturgicalCalendar\Api\Services\EngineCache\EngineCache;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * ENGINE_CACHE_MAX_AGE_DAYS validation, and the path the job prunes.
 */
#[CoversClass(EngineCache::class)]
final class EngineCacheTest extends TestCase
{
    /** @return array<string, array{string, int}> */
    public static function validValues(): array
    {
        return [
            'unset or blank'    => ['', 30],
            'whitespace'        => ['   ', 30],
            'zero disables'     => ['0', 0],
            'one day'           => ['1', 1],
            'the default'       => ['30', 30],
            'leading zeros'     => ['007', 7],
            'explicit plus'     => ['+14', 14],
            'surrounding space' => [' 90 ', 90],
            'the maximum'       => ['36500', 36500],
        ];
    }

    #[DataProvider('validValues')]
    public function testAcceptsWholeDays(string $raw, int $expected): void
    {
        self::assertSame($expected, EngineCache::parseMaxAgeDays($raw));
    }

    /** @return array<string, array{string}> */
    public static function invalidValues(): array
    {
        return [
            'negative'           => ['-1'],
            'fraction'           => ['1.5'],
            'exponent'           => ['1e2'],
            'word'               => ['thirty'],
            'unit suffix'        => ['30d'],
            'hex'                => ['0x1e'],
            'too large'          => ['36501'],
            'overflowing digits' => ['99999999999999999999999'],
        ];
    }

    #[DataProvider('invalidValues')]
    public function testRejectsAnythingElseNamingTheVariable(string $raw): void
    {
        try {
            EngineCache::parseMaxAgeDays($raw);
            self::fail("accepted {$raw}");
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('ENGINE_CACHE_MAX_AGE_DAYS', $e->getMessage());
            self::assertStringContainsString(trim($raw), $e->getMessage());
        }
    }

    public function testReadsTheEnvironment(): void
    {
        $saved = [$_ENV[EngineCache::MAX_AGE_ENV] ?? null, getenv(EngineCache::MAX_AGE_ENV)];
        try {
            $_ENV[EngineCache::MAX_AGE_ENV] = '12';
            self::assertSame(12, EngineCache::maxAgeDaysFromEnv());

            unset($_ENV[EngineCache::MAX_AGE_ENV]);
            putenv(EngineCache::MAX_AGE_ENV);
            self::assertSame(EngineCache::DEFAULT_MAX_AGE_DAYS, EngineCache::maxAgeDaysFromEnv());

            $_ENV[EngineCache::MAX_AGE_ENV] = 'soon';
            $this->expectException(\RuntimeException::class);
            EngineCache::maxAgeDaysFromEnv();
        } finally {
            if ($saved[0] === null) {
                unset($_ENV[EngineCache::MAX_AGE_ENV]);
            } else {
                $_ENV[EngineCache::MAX_AGE_ENV] = $saved[0];
            }
            putenv($saved[1] === false ? EngineCache::MAX_AGE_ENV : EngineCache::MAX_AGE_ENV . '=' . $saved[1]);
        }
    }

    /** The web handlers run from public/, so that is where their relative `engineCache/` lands. */
    public function testTheDirectoryIsTheOneTheWebHandlersUse(): void
    {
        self::assertSame('/srv/api/public/engineCache', EngineCache::directory('/srv/api/'));
        self::assertSame('/srv/api/public/engineCache', EngineCache::directory('/srv/api'));
        self::assertSame(
            realpath(dirname(__DIR__, 3) . '/public') . '/' . EngineCache::DIRECTORY,
            EngineCache::directory((string) realpath(dirname(__DIR__, 3)))
        );
    }

    /** A present entry is read as is. */
    public function testReadIfPresentReturnsTheContents(): void
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'ecr');
        try {
            file_put_contents($file, '{"cached":true}');
            self::assertSame('{"cached":true}', EngineCache::readIfPresent($file));
        } finally {
            @unlink($file);
        }
    }

    /** An entry pruned between the existence check and the read is a cache miss, not a 503. */
    public function testReadIfPresentTreatsAVanishedEntryAsAMiss(): void
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'ecr');
        unlink($file);
        self::assertNull(EngineCache::readIfPresent($file));
    }

    /** A file that is there but cannot be read is still an error: a broken cache must not be recomputed silently. */
    public function testReadIfPresentStillFailsOnAnUnreadableEntry(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('root reads a file whatever its mode.');
        }
        $file = (string) tempnam(sys_get_temp_dir(), 'ecr');
        file_put_contents($file, 'x');
        chmod($file, 0o000);
        try {
            $this->expectException(ServiceUnavailableException::class);
            EngineCache::readIfPresent($file);
        } finally {
            chmod($file, 0o600);
            @unlink($file);
        }
    }
}
