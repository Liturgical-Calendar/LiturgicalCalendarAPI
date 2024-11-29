<?php

namespace LiturgicalCalendar\Api\Enum;

/**
 * The action to take when creating or modifying a calendar event for a National Calendar.
 */
enum CalEventAction: string
{
    /**
     * This `metadata.action` is a combination of `setProperty:name` and `setProperty:grade`,
     * and requires values for both the `festivity.name` and `festivity.grade` properties.
     */
    case MakePatron = 'makePatron';

    /**
     * This `metadata.action` requires a value for the `metadata.property` property, of either `name` or `grade`.
     * In the case of `setProperty:grade`, the `festivity.grade` property is also required.
     * In the case of `setProperty:name`, the `festivity.name` property is also required.
     */
    case SetProperty = 'setProperty';

    /**
     * This `metadata.action` requires values for the `festivity.day` and `festivity.month` properties.
     */
    case MoveFestivity = 'moveFestivity';

    /**
     * This `metadata.action` requires values for the following properties:
     *
     * For a fixed date festivity:
     * - `festivity.name`
     * - `festivity.day`
     * - `festivity.month`
     * - `festivity.grade`
     * - `festivity.color`
     * - `festivity.common`
     *
     * For a mobile date festivity:
     * - `festivity.name`
     * - `festivity.grade`
     * - `festivity.color`
     * - `festivity.common`
     * - `festivity.strtotime`
     */
    case CreateNew = 'createNew';
}
