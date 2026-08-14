<?php

namespace LiturgicalCalendar\Api\Test;

use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Test\AssertionCollection;

/**
 * @phpstan-type AppliesToScope object{
 *      rite: string,
 *      national_calendars?: string[],
 *      diocesan_calendars?: string[],
 *      national_calendar?: string,
 *      diocesan_calendar?: string
 * }
 * @phpstan-type ExcludesScope object{
 *      national_calendars?: string[],
 *      diocesan_calendars?: string[],
 *      national_calendar?: string,
 *      diocesan_calendar?: string
 * }
 */
class TestItem
{
    public string $name;
    public string $event_key;
    public string $description;
    public string $test_type;
    public ?int $year_since = null;
    public ?int $year_until = null;

    /** @phpstan-var AppliesToScope */ public object $applies_to;

    /**
     * The rite the test is scoped to, decoded from `applies_to.rite`.
     *
     * Kept alongside the raw `applies_to` object (which stays verbatim so the
     * item round-trips back to JSON unchanged) so consumers can branch on a
     * typed value rather than re-parsing the string.
     */
    public Rite $rite;

    /** @phpstan-var ExcludesScope|null */ public ?object $excludes = null;
    public AssertionCollection $assertions;

    private const REQUIRED_PROPERTIES = [
        'name',
        'event_key',
        'description',
        'test_type',
        'applies_to',
        'assertions'
    ];

    private const STRING_PROPERTIES = [
        'name',
        'event_key',
        'description',
        'test_type'
    ];

    /**
     * Calendar keys permitted in `applies_to` alongside the required `rite`,
     * and the only keys permitted in `excludes`.
     */
    private const CALENDAR_SCOPE_PROPERTIES = [
        'national_calendars',
        'diocesan_calendars',
        'national_calendar',
        'diocesan_calendar'
    ];

    /**
     * Constructs a new TestItem instance from the given test object.
     *
     * @param \stdClass&object{name:string,description:string,test_type:string,event_key:string,applies_to:object,assertions:array<object{year:int,expected_value:string|null,assert:string,assertion:string,comment:string}>} $testObject An object representing a test, which must contain
     *                              the required properties: 'name', 'event_key',
     *                              'description', 'test_type', 'applies_to', and 'assertions'.
     *
     * @throws \InvalidArgumentException If any of the required properties are missing,
     *                                   if any of the string properties are not strings,
     *                                   if 'year_since' or 'year_until' are not integers,
     *                                   if 'applies_to' or 'excludes' are not objects,
     *                                   or if 'applies_to' carries no valid `rite`.
     */
    public function __construct(\stdClass $testObject)
    {
        foreach (self::REQUIRED_PROPERTIES as $property) {
            if (!property_exists($testObject, $property)) {
                throw new \InvalidArgumentException(__METHOD__ . ": Missing required property: $property");
            }
        }

        foreach (self::STRING_PROPERTIES as $property) {
            if (!is_string($testObject->{$property})) {
                throw new \InvalidArgumentException(__METHOD__ . ": Property `$property` must be a string");
            }
        }

        $this->name        = $testObject->name;
        $this->event_key   = $testObject->event_key;
        $this->description = $testObject->description;
        $this->test_type   = $testObject->test_type;
        $this->assertions  = new AssertionCollection($testObject->assertions);

        if (property_exists($testObject, 'year_since')) {
            if (false === is_int($testObject->year_since)) {
                throw new \InvalidArgumentException(__METHOD__ . ': Property `year_since` must be an integer');
            }
            $this->year_since = $testObject->year_since;
        }

        if (property_exists($testObject, 'year_until')) {
            if (false === is_int($testObject->year_until)) {
                throw new \InvalidArgumentException(__METHOD__ . ': Property `year_until` must be an integer');
            }
            $this->year_until = $testObject->year_until;
        }

        if (false === is_object($testObject->applies_to)) {
            throw new \InvalidArgumentException(__METHOD__ . ': Property `applies_to` must be an object');
        }
        $this->rite = self::checkAppliesToConditions($testObject->applies_to);
        /** @phpstan-var AppliesToScope $appliesTo */
        $appliesTo        = $testObject->applies_to;
        $this->applies_to = $appliesTo;

        if (property_exists($testObject, 'excludes')) {
            if (false === is_object($testObject->excludes)) {
                throw new \InvalidArgumentException(__METHOD__ . ': Property `excludes` must be an object');
            }
            self::checkExcludesConditions($testObject->excludes);
            $this->excludes = $testObject->excludes;
        }
    }

    /**
     * Validate an `applies_to` object and decode its required `rite`.
     *
     * A test with no rite cannot be run: the runner would fall back to the
     * General Roman Calendar and every assertion of an Ambrosian test would
     * fail for the wrong reason (issue #767). The rite is therefore mandatory,
     * and the calendar keys remain optional — `{"rite": "ambrosian"}` alone
     * names the rite-level calendar.
     *
     * @throws \InvalidArgumentException
     */
    private static function checkAppliesToConditions(object $appliesTo): Rite
    {
        $appliesToArr = (array) $appliesTo;

        if (false === array_key_exists('rite', $appliesToArr)) {
            throw new \InvalidArgumentException(__METHOD__ . ': Property `applies_to` must have a `rite` property, one of: ' . implode(', ', array_column(Rite::cases(), 'value')));
        }

        if (false === is_string($appliesToArr['rite'])) {
            throw new \InvalidArgumentException(__METHOD__ . ': Property `rite` must have a string value');
        }

        $rite = Rite::tryFrom($appliesToArr['rite']);
        if (null === $rite) {
            throw new \InvalidArgumentException(__METHOD__ . ": Property `rite` has an unknown value `{$appliesToArr['rite']}`, expected one of: " . implode(', ', array_column(Rite::cases(), 'value')));
        }

        self::checkCalendarScopeConditions($appliesToArr, 'applies_to');

        return $rite;
    }

    /**
     * Validate an `excludes` object.
     *
     * `excludes` narrows the set of calendars a test applies to, so it needs at
     * least one calendar key. It does NOT accept a `rite`: the rite is already
     * pinned by `applies_to`, and excluding it would leave nothing to test.
     *
     * @throws \InvalidArgumentException
     */
    private static function checkExcludesConditions(object $excludes): void
    {
        $excludesArr = (array) $excludes;

        if (array_key_exists('rite', $excludesArr)) {
            throw new \InvalidArgumentException(__METHOD__ . ': Property `excludes` must not have a `rite` property; the rite is pinned by `applies_to`');
        }

        if (false === count(array_intersect_key($excludesArr, array_flip(self::CALENDAR_SCOPE_PROPERTIES))) > 0) {
            throw new \InvalidArgumentException(__METHOD__ . ': Property `excludes` must have at least one of the properties: ' . implode(', ', self::CALENDAR_SCOPE_PROPERTIES));
        }

        self::checkCalendarScopeConditions($excludesArr, 'excludes');
    }

    /**
     * Type-check the calendar keys shared by `applies_to` and `excludes`.
     *
     * @param array<array-key,mixed> $appliesToOrExcludesArr
     * @param string                 $context The owning property name, for error messages.
     *
     * @throws \InvalidArgumentException
     */
    private static function checkCalendarScopeConditions(array $appliesToOrExcludesArr, string $context): void
    {
        if (array_key_exists('national_calendar', $appliesToOrExcludesArr)) {
            if (false === is_string($appliesToOrExcludesArr['national_calendar'])) {
                throw new \InvalidArgumentException(__METHOD__ . ": Property `{$context}`.`national_calendar` must have a string value");
            }
        }

        if (array_key_exists('diocesan_calendar', $appliesToOrExcludesArr)) {
            if (false === is_string($appliesToOrExcludesArr['diocesan_calendar'])) {
                throw new \InvalidArgumentException(__METHOD__ . ": Property `{$context}`.`diocesan_calendar` must have a string value");
            }
        }

        if (array_key_exists('national_calendars', $appliesToOrExcludesArr)) {
            if (false === is_array($appliesToOrExcludesArr['national_calendars'])) {
                throw new \InvalidArgumentException(__METHOD__ . ": Property `{$context}`.`national_calendars` must have an array value");
            }

            foreach ($appliesToOrExcludesArr['national_calendars'] as $calendar) {
                if (false === is_string($calendar)) {
                    throw new \InvalidArgumentException(__METHOD__ . ": Property `{$context}`.`national_calendars` must have an array of strings value");
                }
            }
        }

        if (array_key_exists('diocesan_calendars', $appliesToOrExcludesArr)) {
            if (false === is_array($appliesToOrExcludesArr['diocesan_calendars'])) {
                throw new \InvalidArgumentException(__METHOD__ . ": Property `{$context}`.`diocesan_calendars` must have an array value");
            }

            foreach ($appliesToOrExcludesArr['diocesan_calendars'] as $calendar) {
                if (false === is_string($calendar)) {
                    throw new \InvalidArgumentException(__METHOD__ . ": Property `{$context}`.`diocesan_calendars` must have an array of strings value");
                }
            }
        }
    }
}
