<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Database;

/**
 * Renders a timestamp read back from Postgres as the RFC 3339 string the API's schemas promise.
 *
 * Two things make this less obvious than it looks, and both are properties of this deployment
 * rather than of Postgres:
 *
 * 1. `Connection` issues `SET timezone TO 'Europe/Vatican'` on every connection it opens. A column
 *    declared `TIMESTAMP` (no time zone) — which is what `access_requests.created_at` and
 *    `applications.created_at` are — therefore stores Vatican **wall-clock**, and comes back from
 *    pdo_pgsql carrying no offset at all: `2026-08-31 17:08:52.000816`. Read as UTC it is off by
 *    the Vatican offset; read as local time it is off by whatever the PHP process happens to be
 *    set to. It has to be interpreted in the session's zone, explicitly.
 * 2. A `TIMESTAMPTZ` column in the same query comes back WITH an offset (`…+02`). Passing the
 *    session zone as the second argument to `DateTimeImmutable` is correct for both: an explicit
 *    offset in the string wins, and the zone is used only when there is none.
 *
 * Normalising to UTC also makes the output sortable: `format('Y-m-d\TH:i:sP')` always ends in
 * `+00:00`, so `strcmp` over these strings is chronologically correct even when the values came
 * from differently-typed columns.
 *
 * The rule lives here, beside the `SET timezone` it depends on, so that a change to the session
 * zone has one place to be reflected rather than two.
 */
final class DbTimestamp
{
    /** The session time zone {@see Connection} sets, and therefore the zone a naive value is in. */
    private const SESSION_TIMEZONE = 'Europe/Vatican';

    /**
     * Convert a raw pdo_pgsql timestamp string to RFC 3339 in UTC.
     *
     * Returns the input unchanged when it is empty or unparseable. Both are unreachable for a
     * `NOT NULL` timestamp column, and neither is worth turning a notification badge into a 500:
     * the same reasoning `EasterHandler` applies to a cache it cannot write. Note in particular
     * that `new DateTimeImmutable('')` means *now* — passing an empty value through would silently
     * invent a timestamp, which is the one outcome worse than passing it along.
     */
    public static function toRfc3339(string $dbTimestamp): string
    {
        if ('' === trim($dbTimestamp)) {
            return $dbTimestamp;
        }

        try {
            return ( new \DateTimeImmutable($dbTimestamp, new \DateTimeZone(self::SESSION_TIMEZONE)) )
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:sP');
        } catch (\Exception) {
            return $dbTimestamp;
        }
    }
}
