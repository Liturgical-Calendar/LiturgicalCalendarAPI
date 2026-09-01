<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services;

use LiturgicalCalendar\Api\Enum\Rite;

/**
 * The `<rite>/<calendarId>` form of an OpenFGA object id.
 *
 * A calendar id alone does not identify a calendar. The source tree is partitioned
 * by rite (`jsondata/sourcedata/rite/{rite}/calendars/...`), so nothing stops the
 * same diocese being defined under both; `lugano_ch` only happens to be
 * Ambrosian-only today. A grant on the bare id would therefore be ambiguous, and
 * would silently widen to cover a Roman `lugano_ch` the moment one existed.
 *
 * Every object type that names a *calendar* carries its rite this way:
 *
 *   diocesan_calendar:ambrosian/lugano_ch      diocesan_calendar_test:ambrosian/lugano_ch
 *   national_calendar:roman/US                 national_calendar_test:roman/US
 *   wider_region:roman/Europe                  rite_calendar:roman/temporale
 *
 * `rite_calendar_test` is the exception that proves the rule: its id *is* the rite, with no
 * separate calendar id to qualify.
 *
 * **`rite_calendar` (#955) also follows this format**, e.g. `rite_calendar:roman/temporale` or
 * `rite_calendar:ambrosian/EDITIO_TYPICA_2024` — do not confuse it with the type below just
 * because both name the rite-level tier. It is the generalisation of the legacy
 * `general_roman_calendar`, which modelled that tier as though only the Roman rite had one;
 * `rite_calendar` qualifies its ids the same way every other calendar type here does, because
 * more than one rite now has a rite-level tier to disambiguate.
 *
 * `general_roman_calendar` — deprecated, retired at the #955 prune milestone
 * (`docs/ops/rite-calendar-migration-runbook.md`) — is the true exception: it keeps BARE ids,
 * and that remains correct for it specifically, not for `rite_calendar`. None of its ids need
 * disambiguating: `temporale` and `decrees` name no calendar at all, and a missal edition id
 * (`EDITIO_TYPICA_1970`, `EDITIO_TYPICA_2024`, ...) is already unique across every rite, unlike
 * a nation or diocese code. That is not self-evident from the ids alone — the Ambrosian typical
 * edition now shares the `EDITIO_TYPICA_` prefix with its Roman namesakes — so it is asserted,
 * not assumed: {@see \LiturgicalCalendar\Tests\Enum\MissalCatalogTest::testTheRitesDoNotShareIds}
 * fails loudly the day a future Roman 2024 typical edition collides with the Ambrosian one. See
 * {@see \LiturgicalCalendar\Api\Http\Middleware\OpenFgaAuthorizationMiddleware::forMissals()}.
 *
 * Introduced for the test scopes in issue #767 and extended to the data resource
 * types in #786; this class is the single definition of the format for both, which
 * is why it lives here rather than on {@see TestScopeResolver}.
 */
final class RiteScopedObjectId
{
    /**
     * Separates the rite from the calendar id.
     *
     * `/` is safe in an OpenFGA object id — only whitespace, `:`, `#` and `*` carry
     * meaning there — and reads as the hierarchy it is: rite over calendar.
     */
    public const SEPARATOR = '/';

    /**
     * Compose the rite-qualified object id for a calendar.
     */
    public static function qualify(Rite $rite, string $calendarId): string
    {
        return $rite->value . self::SEPARATOR . $calendarId;
    }

    /**
     * Split a rite-qualified object id back into its rite and calendar id.
     *
     * Returns null when the id is not rite-qualified — an unmigrated legacy id such
     * as a bare `rotter_nl`, or a prefix that names no known rite. Callers decide
     * what an unqualified id means for them: input validation rejects it, while the
     * reconciler's existence check tolerates it for the whole migration window.
     *
     * @return array{0: Rite, 1: string}|null
     */
    public static function parse(string $objectId): ?array
    {
        $pos = strpos($objectId, self::SEPARATOR);
        if (false === $pos) {
            return null;
        }

        $rite       = Rite::tryFrom(substr($objectId, 0, $pos));
        $calendarId = substr($objectId, $pos + strlen(self::SEPARATOR));

        if (null === $rite || '' === $calendarId) {
            return null;
        }

        return [$rite, $calendarId];
    }

    /**
     * The calendar id with any rite qualifier stripped.
     *
     * For code that needs the bare id regardless of whether the caller supplied a
     * migrated or a legacy one — filesystem lookups, mostly.
     */
    public static function calendarId(string $objectId): string
    {
        $parsed = self::parse($objectId);

        return null === $parsed ? $objectId : $parsed[1];
    }
}
