<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\SourceData;

use LiturgicalCalendar\Api\Database\Connection;
use LiturgicalCalendar\Api\Services\OpenFgaClient;

/**
 * Whether this deployment records source-data edits as change requests, or
 * writes them straight to disk the way it always has.
 *
 * Opt-in and fail-safe. The flag alone is not enough: queue mode needs Postgres
 * to store proposals and OpenFGA to decide who may approve them. A flag set
 * without that stack behind it falls back to disk and reports itself as
 * misconfigured, rather than accepting edits nobody could ever approve.
 */
final class SourceDataWriteMode
{
    public const FLAG = 'SOURCEDATA_CHANGE_REQUESTS';

    /**
     * True when edits should become change requests rather than files.
     */
    public static function changeRequestsEnabled(): bool
    {
        return self::flagSet() && self::stackAvailable();
    }

    /**
     * True when the operator asked for queue mode but the stack cannot support it.
     *
     * Callers log this and Health surfaces it; the request itself still succeeds
     * in disk mode.
     */
    public static function isMisconfigured(): bool
    {
        return self::flagSet() && !self::stackAvailable();
    }

    /**
     * True when this deployment is writing to disk despite having everything
     * queue mode needs — almost always a forgotten flag on a host that rsyncs
     * `--delete` from git, where the next deploy silently reverts the edit.
     */
    public static function isUnexpectedlyWritingToDisk(): bool
    {
        return !self::flagSet() && self::stackAvailable();
    }

    private static function flagSet(): bool
    {
        $value = $_ENV[self::FLAG] ?? 'false';

        return is_string($value) && 'true' === strtolower(trim($value));
    }

    private static function stackAvailable(): bool
    {
        return Connection::isConfigured() && OpenFgaClient::isConfigured();
    }
}
