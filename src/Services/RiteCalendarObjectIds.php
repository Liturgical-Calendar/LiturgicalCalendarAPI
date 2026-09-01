<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services;

use LiturgicalCalendar\Api\Enum\MissalCatalog;
use LiturgicalCalendar\Api\Enum\Rite;

/**
 * The object ids valid for the `rite_calendar` OpenFGA type.
 *
 * `rite_calendar` is the tier above nations, wider regions and dioceses — the calendar
 * belonging to a rite as a whole. It generalises the older `general_roman_calendar`, which
 * modelled that tier as though only the Roman rite had one (issue #955).
 *
 * Ids are rite-qualified `<rite>/<subresource>` through {@see RiteScopedObjectId}, like every
 * other object type that names a calendar. The predecessor kept BARE ids on the argument that a
 * missal edition id is already unique across rites — true, and still true, but it does not
 * generalise: `temporale`, `decrees` and `supported_locales` are sub-resource *kinds*, one per
 * rite, not unique ids. `temporale` is ambiguous in the corpus **today**, since
 * `jsondata/sourcedata/rite/ambrosian/missals/propriumdetempore` exists; it only fails to bite
 * because the temporale write route is Roman-only.
 *
 * Missal ids are DERIVED from {@see MissalCatalog}, not re-declared, so an edition added to a
 * rite becomes grantable without editing this class — which is what makes "a rite added later
 * needs no new object type" true of the id list too, and not only of the type.
 */
final class RiteCalendarObjectIds
{
    /**
     * Non-missal sub-resources, per rite.
     *
     * `temporale` exists for both rites: each has a `propriumdetempore` in its own partition of
     * the source tree. `ambrosian/temporale` is grantable although no write route consumes it
     * yet — the same forward-looking allowance
     * {@see \LiturgicalCalendar\Api\Repositories\AccessRequestRepository::isValidNationCode()}
     * makes for prospective nations, so that whoever will own the resource can be granted admin
     * before it exists (#669).
     *
     * `decrees` is Roman-only: only `jsondata/sourcedata/rite/roman/decrees` exists.
     * `supported_locales` is Roman-only because `jsondata/supportedLocales.json` is itself keyed
     * `general_roman_calendar` at its top level. That the API-wide locale set is filed under one
     * rite is a known wart, recorded as a follow-up in the design rather than fixed here.
     *
     * @var array<string, list<string>>
     */
    private const FIXED_IDS = [
        'roman'     => ['temporale', 'decrees', 'supported_locales'],
        'ambrosian' => ['temporale'],
    ];

    /**
     * The bare sub-resource ids valid for one rite.
     *
     * @return list<string>
     */
    public static function forRite(Rite $rite): array
    {
        return array_merge(self::FIXED_IDS[$rite->value] ?? [], self::missalIdsForRite($rite));
    }

    /**
     * The typical editions of a rite that actually carry sanctorale data.
     *
     * BOTH conditions are load-bearing. `isEditioTypica()` alone admits Roman
     * `EDITIO_TYPICA_1971` and `EDITIO_TYPICA_1975` and Ambrosian `EDITIO_TYPICA_1976`, which
     * are typical editions that ship no sanctorale file — a grant over them would authorize
     * editing a resource with nothing in it. `getSanctoraleFileName() !== false` is the same
     * test the original design used to exclude 1971/1975 by hand; deriving it keeps the set
     * correct when an edition gains or loses sanctorale data.
     *
     * @return list<string>
     */
    private static function missalIdsForRite(Rite $rite): array
    {
        $source = MissalCatalog::for($rite);
        $ids    = [];

        foreach ($source->getMissalIds() as $missalId) {
            if ($source->isEditioTypica($missalId) && false !== $source->getSanctoraleFileName($missalId)) {
                $ids[] = $missalId;
            }
        }

        return $ids;
    }

    /**
     * The rite-qualified ids valid for one rite.
     *
     * @return list<string>
     */
    public static function qualifiedIdsForRite(Rite $rite): array
    {
        return array_map(
            static fn (string $id): string => RiteScopedObjectId::qualify($rite, $id),
            self::forRite($rite)
        );
    }

    /**
     * Every rite-qualified id, across every rite.
     *
     * @return list<string>
     */
    public static function allQualifiedIds(): array
    {
        $ids = [];

        foreach (Rite::cases() as $rite) {
            $ids = array_merge($ids, self::qualifiedIdsForRite($rite));
        }

        return $ids;
    }

    /**
     * Whether an object id is valid for the `rite_calendar` type.
     *
     * A bare id — `decrees`, `temporale` — is deliberately INVALID here. Those are legacy
     * `general_roman_calendar` ids; they keep authorizing through that legacy type for the
     * migration window, and are migrated by `scripts/migrate-rite-calendar-tuples.php`. Letting
     * them validate against the new type as well would create a second spelling of the same
     * grant with no migration path off it.
     */
    public static function isValid(string $objectId): bool
    {
        $parsed = RiteScopedObjectId::parse($objectId);

        if (null === $parsed) {
            return false;
        }

        [$rite, $subResource] = $parsed;

        return in_array($subResource, self::forRite($rite), true);
    }

    /**
     * Every valid qualified id, for an error message that tells the caller what to send.
     */
    public static function label(): string
    {
        return implode(', ', self::allQualifiedIds());
    }
}
