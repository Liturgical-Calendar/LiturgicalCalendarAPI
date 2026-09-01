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
    /** The OpenFGA object type this class describes. */
    public const TYPE = 'rite_calendar';

    /**
     * The pre-#955 object type this one generalises, retired at the prune milestone
     * (`docs/ops/rite-calendar-migration-runbook.md`). Named here because
     * {@see legacyCounterpart()} and {@see riteCounterpart()} are the single definition of the
     * pairing between the two.
     */
    public const LEGACY_TYPE = 'general_roman_calendar';

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

    /**
     * The pre-#955 object that denoted the same resource as a `rite_calendar` object.
     *
     * **This is the ONE definition of the legacy/successor pairing**, and everything that needs
     * the pair asks here. That single-definition property is not tidiness, it is a correctness
     * requirement: authorization widens through the pair
     * ({@see \LiturgicalCalendar\Api\Http\Middleware\OpenFgaAuthorizationMiddleware}) while
     * revocation must close through it
     * ({@see \LiturgicalCalendar\Api\Handlers\Admin\PermissionAdminHandler}). If the two computed
     * it separately and drifted, the drift would show up either as a revoke that silently leaves a
     * surviving grant behind — the primary check denies, the fallback re-authorizes off the legacy
     * tuple, and the revocation is a no-op for the whole migration window — or as a revoke that
     * deletes a tuple belonging to a different resource.
     *
     * The pairing is derivable from the id alone, and deliberately asymmetric between the two
     * kinds of sub-resource:
     *
     * - **A typical edition pairs across EVERY rite.** Missal ids are unique across rites
     *   ({@see \LiturgicalCalendar\Tests\Enum\MissalCatalogTest::testTheRitesDoNotShareIds}), so
     *   `general_roman_calendar:EDITIO_TYPICA_2024` genuinely denoted the AMBROSIAN typical
     *   edition and must keep pairing with `rite_calendar:ambrosian/EDITIO_TYPICA_2024`. The
     *   edition must belong to the rite the id names: that is what `forMissals()` always passed,
     *   and it keeps a bogus cross-rite id such as `rite_calendar:roman/EDITIO_TYPICA_2024` from
     *   reaching another rite's legacy tuple.
     * - **A fixed sub-resource pairs ONLY for the Roman rite.** `temporale`, `decrees` and
     *   `supported_locales` are sub-resource *kinds*, one per rite, and every legacy id was Roman
     *   by construction — the predecessor type modelled the tier as though only the Roman rite had
     *   one. Pairing `general_roman_calendar:decrees` with an Ambrosian object would re-introduce
     *   exactly the un-qualification #955 exists to remove.
     *
     * Anything else — an id that is not rite-qualified, a rite that does not declare the
     * sub-resource, an object type that is not `rite_calendar` — has no counterpart.
     *
     * @return array{0: string, 1: string}|null `[legacyObjectType, legacyObjectId]`, or null
     */
    public static function legacyCounterpart(string $objectType, string $objectId): ?array
    {
        if (self::TYPE !== $objectType) {
            return null;
        }

        $parsed = RiteScopedObjectId::parse($objectId);
        if (null === $parsed) {
            return null;
        }

        [$rite, $subResource] = $parsed;

        if (MissalCatalog::for($rite)->isEditioTypica($subResource)) {
            return [self::LEGACY_TYPE, $subResource];
        }

        if (Rite::ROMAN === $rite && in_array($subResource, self::FIXED_IDS[Rite::ROMAN->value], true)) {
            return [self::LEGACY_TYPE, $subResource];
        }

        return null;
    }

    /**
     * The `rite_calendar` object that succeeds a pre-#955 `general_roman_calendar` object.
     *
     * The inverse of {@see legacyCounterpart()}, and needed for the same reason: a revoke aimed at
     * the LEGACY object must close the successor too, or the hole this pairing exists to plug
     * simply reappears mirrored — the admin revokes `general_roman_calendar:decrees`, the migrated
     * `rite_calendar:roman/decrees` tuple survives, and the primary authorization check keeps
     * allowing the write with no fallback even needed.
     *
     * A legacy id is bare, so the rite has to be recovered rather than read off: a typical edition
     * belongs to whichever rite declares it (unique across rites, as above), and a fixed
     * sub-resource is Roman by construction. An id neither of those describes — anything already
     * rite-qualified included — has no successor.
     *
     * `legacyCounterpart(riteCounterpart($legacy)) === $legacy` for every id that has one.
     *
     * @return array{0: string, 1: string}|null `[riteObjectType, riteObjectId]`, or null
     */
    public static function riteCounterpart(string $objectType, string $objectId): ?array
    {
        if (self::LEGACY_TYPE !== $objectType) {
            return null;
        }

        foreach (Rite::cases() as $rite) {
            if (MissalCatalog::for($rite)->isEditioTypica($objectId)) {
                return [self::TYPE, RiteScopedObjectId::qualify($rite, $objectId)];
            }
        }

        if (in_array($objectId, self::FIXED_IDS[Rite::ROMAN->value], true)) {
            return [self::TYPE, RiteScopedObjectId::qualify(Rite::ROMAN, $objectId)];
        }

        return null;
    }
}
