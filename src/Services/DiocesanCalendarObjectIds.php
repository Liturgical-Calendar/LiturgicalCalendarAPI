<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services;

use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Models\CatholicDiocesesLatinRite\CatholicDiocesesMap;
use LiturgicalCalendar\Api\Utilities;

/**
 * The `diocesan_calendar` object ids a permission may name (#993).
 *
 * A diocesan calendar depends on its national calendar: it can only be created once the
 * national calendar of its nation exists. A grant over a diocese that fails that rule writes an
 * OpenFGA tuple over a calendar nobody can create, so a `<rite>/<diocese_id>` id is valid when
 * either:
 *
 * - a diocesan calendar with that id already exists under that rite, so every current grant
 *   stays valid; or
 * - the rite is Roman, the id is a diocese in the Latin-rite dioceses data, and its nation has an
 *   existing national calendar: a prospective diocese, whose curator may request access to
 *   create it.
 *
 * Only the Roman rite has prospective dioceses: the Ambrosian rite has no national tier and no
 * source list of dioceses to draw one from.
 *
 * The calendars index is rebuilt on every call rather than memoised: calendars are created and
 * deleted by the `/data` endpoints, and a stale answer here would either refuse a grant over a
 * calendar that now exists or allow one over a nation whose calendar was just deleted. The
 * dioceses data is static and is read once per process.
 */
final class DiocesanCalendarObjectIds
{
    public const TYPE = 'diocesan_calendar';

    private static ?CatholicDiocesesMap $dioceses = null;

    public static function isValid(string $objectId): bool
    {
        return null === self::invalidReason($objectId);
    }

    /**
     * Why $objectId names no diocesan calendar that exists or can be created, or null when it does.
     */
    public static function invalidReason(string $objectId): ?string
    {
        $parsed = RiteScopedObjectId::parse($objectId);
        if (null === $parsed) {
            return "\"{$objectId}\" is not a rite-qualified diocese id";
        }

        [$rite, $dioceseId] = $parsed;
        $metadata           = CalendarMetadataProvider::create();

        foreach ($metadata->diocesan_calendars as $diocesanCalendar) {
            if ($diocesanCalendar->rite === $rite && $diocesanCalendar->calendar_id === $dioceseId) {
                return null;
            }
        }

        if ($rite !== Rite::ROMAN) {
            return "no {$rite->value} diocesan calendar \"{$dioceseId}\" exists, and only Roman-rite diocesan calendars can be requested before they exist";
        }

        $diocese = self::dioceses()->dioceseNameAndNationFromId($dioceseId);
        if (null === $diocese) {
            return "\"{$dioceseId}\" is not a Latin-rite diocese";
        }

        // The dioceses data keys nations in lowercase; calendar keys are ISO 3166-1 uppercase.
        $nation = strtoupper($diocese['country_iso']);
        if (false === in_array($nation, $metadata->national_calendars_keys, true)) {
            return "a diocesan calendar for {$diocese['diocese_name']} depends on the national calendar of {$nation}, which does not exist yet";
        }

        return null;
    }

    /**
     * Human-readable description of the ids {@see self::isValid()} accepts.
     */
    public static function label(): string
    {
        return 'a rite-qualified diocese id, either of an existing diocesan calendar (e.g. '
            . RiteScopedObjectId::qualify(Rite::AMBROSIAN, 'lugano_ch')
            . ') or of a Latin-rite diocese whose nation has a national calendar (e.g. '
            . RiteScopedObjectId::qualify(Rite::ROMAN, 'albany_us') . ')';
    }

    private static function dioceses(): CatholicDiocesesMap
    {
        return self::$dioceses ??= CatholicDiocesesMap::fromObject(
            Utilities::jsonFileToObject(JsonData::CATHOLIC_DIOCESES_LATIN_RITE->path())
        );
    }
}
