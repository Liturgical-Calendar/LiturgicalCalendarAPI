<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\EngineCache;

use LiturgicalCalendar\Api\Services\SourceData\SourceDataPublisherFactory;

/**
 * Where the engine cache lives, and how long its entries are kept.
 *
 * The request handlers address the cache by a path relative to the web process's working directory
 * (`engineCache/v<API_VERSION>-<dataDigest>/` in CalendarHandler, `engineCache/easter/` in EasterHandler). Every
 * web entry point serves `public/` — php-fpm runs `public/index.php` from that directory, and the built-in server
 * and the Docker image both use `-t public` — so on disk the cache is `<project root>/public/engineCache`. The job
 * runner's working directory is the project root instead, so it must resolve the path explicitly with
 * {@see self::directory()}; using the relative path from there would prune `<project root>/engineCache`, which is
 * only the test suite's cache.
 */
final class EngineCache
{
    /** The cache directory, relative to the web process's working directory. */
    public const DIRECTORY = 'engineCache';

    /** The directory every web entry point serves, relative to the project root. */
    public const WEB_ROOT = 'public';

    /** Environment variable: entries older than this many days are pruned; 0 disables pruning. */
    public const MAX_AGE_ENV = 'ENGINE_CACHE_MAX_AGE_DAYS';

    public const DEFAULT_MAX_AGE_DAYS = 30;

    /** A hundred years: anything larger is a typo, and would overflow the seconds arithmetic long before it mattered. */
    public const MAX_MAX_AGE_DAYS = 36500;

    /** The absolute path of the cache directory the web handlers read and write. */
    public static function directory(string $projectRoot): string
    {
        return rtrim($projectRoot, '/\\') . DIRECTORY_SEPARATOR . self::WEB_ROOT . DIRECTORY_SEPARATOR . self::DIRECTORY;
    }

    /**
     * ENGINE_CACHE_MAX_AGE_DAYS from the environment, validated by {@see self::parseMaxAgeDays()}.
     *
     * @throws \RuntimeException If the variable is set to anything but a whole number of days in range.
     */
    public static function maxAgeDaysFromEnv(): int
    {
        return self::parseMaxAgeDays(SourceDataPublisherFactory::envString(self::MAX_AGE_ENV));
    }

    /**
     * Unset or blank means the default; otherwise a whole number of days from 0 (pruning disabled) to
     * MAX_MAX_AGE_DAYS. Integer syntax only, as in JwtServiceFactory: `is_numeric('1.5')` is true and `(int) '1.5'`
     * is 1, so the looser check would quietly prune a cache an operator meant to keep for a day and a half.
     *
     * @throws \RuntimeException If the value is not a whole number of days, or is out of range.
     */
    public static function parseMaxAgeDays(string $raw): int
    {
        $raw = trim($raw);
        if ($raw === '') {
            return self::DEFAULT_MAX_AGE_DAYS;
        }
        if (1 !== preg_match('/^\+?\d+$/', $raw)) {
            throw new \RuntimeException(sprintf(
                '%s must be a whole number of days, or 0 to disable pruning (got: %s)',
                self::MAX_AGE_ENV,
                $raw
            ));
        }
        $digits = ltrim($raw, '+0');
        if (strlen($digits) > strlen((string) self::MAX_MAX_AGE_DAYS) || (int) $digits > self::MAX_MAX_AGE_DAYS) {
            throw new \RuntimeException(sprintf(
                '%s must be at most %d days (got: %s)',
                self::MAX_AGE_ENV,
                self::MAX_MAX_AGE_DAYS,
                $raw
            ));
        }

        return (int) $digits;
    }
}
