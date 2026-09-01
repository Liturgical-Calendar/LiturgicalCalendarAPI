<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services;

use LiturgicalCalendar\Api\Enum\MissalCatalog;
use LiturgicalCalendar\Api\Enum\Rite;

/**
 * Identifies the resource a source-data change targets.
 *
 * Object types match AccessRequestRepository::VALID_OBJECT_TYPES, so the
 * permission tuple produced by {@see fgaPermission()} can be handed straight to
 * ResourceAdminService without translation.
 *
 * Calendar-naming types (national_calendar, diocesan_calendar, wider_region) and
 * the scoped test types all carry a rite-qualified `<rite>/<calendarId>` id, because
 * a bare calendar id does not identify a calendar: the source tree is partitioned
 * by rite, so nothing stops the same diocese id being defined under more than one
 * rite (see {@see RiteScopedObjectId} and issue #786).
 * `rite_calendar` ids are rite-qualified too, as of #955: its sub-resources are per-rite kinds
 * (`roman/temporale`, `ambrosian/temporale`), not globally unique ids.
 *
 * This mirrors AccessRequestRepository::isValidObjectIdForType() — the actual
 * validation logic — not the older "all other types accept any non-empty id" prose
 * above it in that file, which predates #786 and was never updated when the
 * rite-qualification requirement was added to the code.
 */
final readonly class ChangeResource
{
    /** Test object types whose ids must be rite-qualified. */
    private const RITE_QUALIFIED_TEST_TYPES = [
        'national_calendar_test',
        'diocesan_calendar_test',
    ];

    private function __construct(
        public string $type,
        public string $id
    ) {
    }

    public static function nationalCalendar(Rite $rite, string $nation): self
    {
        $nation = self::requireNonEmpty($nation, 'nation');

        return new self('national_calendar', RiteScopedObjectId::qualify($rite, $nation));
    }

    public static function diocesanCalendar(Rite $rite, string $diocese): self
    {
        $diocese = self::requireNonEmpty($diocese, 'diocese');

        return new self('diocesan_calendar', RiteScopedObjectId::qualify($rite, $diocese));
    }

    /**
     * Wider regions have no notion of rite beyond Roman: isValidObjectIdForType()
     * rejects any non-Roman rite for wider_region, so a Rite parameter here would
     * exist only to be refused. Qualify with Rite::ROMAN internally instead.
     */
    public static function widerRegion(string $region): self
    {
        $region = self::requireNonEmpty($region, 'wider region');

        return new self('wider_region', RiteScopedObjectId::qualify(Rite::ROMAN, $region));
    }

    /**
     * The decrees corpus of a rite — a fixed sub-resource on the `rite_calendar` type.
     *
     * Only the Roman rite has a decrees corpus on disk today
     * (`jsondata/sourcedata/rite/roman/decrees`), which is why the parameter defaults to it and
     * why `RiteCalendarObjectIds` lists `decrees` for that rite alone. The parameter exists so
     * that a rite which later grows one needs no signature change here.
     */
    public static function decrees(Rite $rite = Rite::ROMAN): self
    {
        return new self('rite_calendar', self::requireGrantableRiteCalendarId($rite, 'decrees'));
    }

    /**
     * One Missal's sanctorale — the file its entries live in, and therefore the resource a
     * sanctorale write targets (issue #943).
     *
     * The scoping mirrors {@see \LiturgicalCalendar\Api\Http\Middleware\OpenFgaAuthorizationMiddleware::forMissals()}
     * exactly, and must: that middleware decides whether the caller MAY write, and this decides
     * what the recorded proposal is ABOUT. If the two disagreed, a caller could be authorized
     * against one object and have the change request filed against another — which is precisely
     * the pair a reviewer later checks permissions on.
     *
     * A typical edition is a rite-qualified sub-resource on `rite_calendar`, alongside
     * `<rite>/temporale` and `<rite>/decrees` — see {@see RiteCalendarObjectIds}. The ids were
     * bare under the predecessor type on the argument that a missal edition id is already unique
     * across rites; that is still true of missal ids specifically, but does not generalise to the
     * tier's other sub-resources, so #955 qualifies all of them under one rule. A national
     * edition belongs to the national calendar whose conference publishes it, qualified with the
     * same rite the caller passed in.
     *
     * @param string $missalId The missal identifier (e.g. "EDITIO_TYPICA_2002", "IT_1983" or "EDITIO_TYPICA_2024")
     * @param Rite   $rite     The rite the missal belongs to
     */
    public static function missal(string $missalId, Rite $rite = Rite::ROMAN): self
    {
        $missalId = self::requireNonEmpty($missalId, 'missal id');
        $source   = MissalCatalog::for($rite);

        if ($source->isEditioTypica($missalId)) {
            return new self('rite_calendar', self::requireGrantableRiteCalendarId($rite, $missalId));
        }

        return new self('national_calendar', RiteScopedObjectId::qualify($rite, $source->regionFor($missalId)));
    }

    /**
     * The curated set of officially supported locales — a fixed sub-resource on the
     * `rite_calendar` type, exactly like {@see decrees()}.
     *
     * `jsondata/supportedLocales.json` is keyed by `general_roman_calendar` at its top level, so
     * filing it under `roman/` is the honest reading of today's data even though the locale set
     * is API-wide. That mismatch is a known wart, recorded as a follow-up in the #955 design.
     *
     * The accepted consequence is unchanged from #926: whoever administers the Roman rite-level
     * calendar curates its supported locales.
     */
    public static function supportedLocales(Rite $rite = Rite::ROMAN): self
    {
        return new self('rite_calendar', self::requireGrantableRiteCalendarId($rite, 'supported_locales'));
    }

    /**
     * @param string $objectType One of AccessRequestRepository::VALID_OBJECT_TYPES ending in `_test`.
     * @param string $calendarId The calendar the test is scoped to, unqualified.
     */
    public static function test(Rite $rite, string $objectType, string $calendarId): self
    {
        $calendarId = self::requireNonEmpty($calendarId, 'calendar id');

        $id = in_array($objectType, self::RITE_QUALIFIED_TEST_TYPES, true)
            ? RiteScopedObjectId::qualify($rite, $calendarId)
            : $calendarId;

        return new self($objectType, $id);
    }

    /**
     * The OpenFGA tuple asserting that a caller administers this resource.
     *
     * @return array{object_type: string, object_id: string, relation: string}
     */
    public function fgaPermission(): array
    {
        return [
            'object_type' => $this->type,
            'object_id'   => $this->id,
            'relation'    => 'admin',
        ];
    }

    /**
     * The git branch carrying this resource's rolling pull request.
     *
     * Unused in phase 1. Defined here so the publisher does not re-derive naming
     * from a different place and drift.
     */
    public function branch(): string
    {
        return sprintf('litcal-data/%s/%s', $this->type, $this->id);
    }

    private static function requireNonEmpty(string $value, string $label): string
    {
        if ($value === '') {
            throw new \InvalidArgumentException(sprintf('A change resource requires a non-empty %s', $label));
        }

        return $value;
    }

    /**
     * The rite-qualified `rite_calendar` id, refused unless it is one a permission can be held on.
     *
     * Every `rite_calendar` id this class composes is checked here, because a change request is
     * only useful if somebody can be authorized over its resource: `ChangeRequestReview` resolves
     * the reviewer's rights through `ResourceAdminService` against exactly this `type`/`id` pair,
     * so an id outside {@see RiteCalendarObjectIds} would file a proposal nobody can ever hold the
     * `admin` tuple for — un-reviewable rather than merely unauthorized.
     *
     * The composed ids are not all in the catalogue by construction, which is why this is a check
     * and not a comment. `decrees(Rite::AMBROSIAN)` yields `ambrosian/decrees` and
     * `supportedLocales(Rite::AMBROSIAN)` yields `ambrosian/supported_locales`; only the Roman
     * rite declares either sub-resource. `missal()` admits any TYPICAL edition, including the ones
     * that ship no sanctorale file at all (`EDITIO_TYPICA_1971`, `EDITIO_TYPICA_1975`,
     * `EDITIO_TYPICA_1976`), which `RiteCalendarObjectIds` deliberately excludes. Neither is
     * reachable through a call site today — both fixed-sub-resource factories default to Roman —
     * so this is a constructor guard rather than a bug fix, kept in the shape of
     * {@see requireNonEmpty()}: the factory refuses to build an ungrantable resource instead of
     * leaving it to be discovered at review time.
     */
    private static function requireGrantableRiteCalendarId(Rite $rite, string $subResource): string
    {
        $objectId = RiteScopedObjectId::qualify($rite, $subResource);

        if (false === RiteCalendarObjectIds::isValid($objectId)) {
            throw new \InvalidArgumentException(sprintf(
                'A change resource cannot name rite_calendar:%s — no permission can be held on it. Valid ids: %s',
                $objectId,
                RiteCalendarObjectIds::label()
            ));
        }

        return $objectId;
    }
}
