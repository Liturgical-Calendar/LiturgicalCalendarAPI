<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services;

use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Enum\RomanMissal;

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
 * rite (see {@see RiteScopedObjectId} and issue #786). general_roman_calendar keeps
 * bare ids because its ids (temporale, decrees, missal editions) are not calendars
 * and are Roman by construction.
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
     * The decrees corpus is a fixed object id on the general_roman_calendar type —
     * see AccessRequestRepository::GRC_OBJECT_IDS.
     */
    public static function decrees(): self
    {
        return new self('general_roman_calendar', 'decrees');
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
     * An editio typica is a fixed id on `general_roman_calendar`, alongside `temporale` and
     * `decrees`; the three that carry sanctorale data (`EDITIO_TYPICA_1970`, `_2002`, `_2008`)
     * are already in {@see \LiturgicalCalendar\Api\Repositories\AccessRequestRepository::GRC_OBJECT_IDS},
     * so no OpenFGA model migration is needed. A national edition belongs to the national
     * calendar whose conference publishes it, rite-qualified as Roman because Missals live only
     * under the Roman source tree.
     */
    public static function missal(string $missalId): self
    {
        $missalId = self::requireNonEmpty($missalId, 'missal id');

        if (RomanMissal::isLatinMissal($missalId)) {
            return new self('general_roman_calendar', $missalId);
        }

        return new self('national_calendar', RiteScopedObjectId::qualify(Rite::ROMAN, explode('_', $missalId)[0]));
    }

    /**
     * The curated set of officially supported locales — a fixed object id on the
     * general_roman_calendar type, exactly like {@see decrees()}.
     *
     * `jsondata/supportedLocales.json` is keyed by `general_roman_calendar` at its top
     * level and {@see \LiturgicalCalendar\Api\Services\SupportedLocales::official()}
     * describes itself as "the locales officially supported for the General Roman
     * Calendar", so the scoping is already the resource's own. A supported-locale set is
     * likewise not a calendar and is Roman by construction, which is why the id stays
     * bare rather than rite-qualified — see the class docblock.
     *
     * A fixed id on an existing type needs no OpenFGA model migration: ids are not part
     * of the authorization model, only types and relations are. The accepted consequence
     * is that whoever administers the General Roman Calendar curates its supported
     * locales (issue #926).
     */
    public static function supportedLocales(): self
    {
        return new self('general_roman_calendar', 'supported_locales');
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
}
