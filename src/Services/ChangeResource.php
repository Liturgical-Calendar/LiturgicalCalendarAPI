<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services;

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
