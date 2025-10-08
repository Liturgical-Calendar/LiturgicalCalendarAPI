<?php

namespace LiturgicalCalendar\Api\Enum;

/**
 * The action to take when creating or modifying a calendar event for a National Calendar.
 */
enum CalEventAction: string
{
    /**
     * This `metadata.action` is a combination of `setProperty:name` and `setProperty:grade`,
     * and requires values for both the `liturgical_event.name` and `liturgical_event.grade` properties.
     */
    case MakePatron = 'makePatron';

    /**
     * This `metadata.action` requires a value for the `metadata.property` property, of either `name` or `grade`.
     * In the case of `setProperty:grade`, the `liturgical_event.grade` property is also required.
     * In the case of `setProperty:name`, the `liturgical_event.name` property is also required.
     */
    case SetProperty = 'setProperty';

    /**
     * This `metadata.action` requires values for the `liturgical_event.day` and `liturgical_event.month` properties.
     */
    case MoveEvent = 'moveEvent';

    /**
     * This `metadata.action` requires values for the following properties:
     *
     * For a fixed date liturgical event:
     * - `liturgical_event.name`
     * - `liturgical_event.day`
     * - `liturgical_event.month`
     * - `liturgical_event.grade`
     * - `liturgical_event.color`
     * - `liturgical_event.common`
     *
     * For a mobile date liturgical event:
     * - `liturgical_event.name`
     * - `liturgical_event.grade`
     * - `liturgical_event.color`
     * - `liturgical_event.common`
     * - `liturgical_event.strtotime`
     */
    case CreateNew = 'createNew';

    /**
     * This `metadata.action` is only used for Decrees of the Dicastery for Divine Worship and the Discipline of the Sacraments.
     */
    case MakeDoctor = 'makeDoctor';
}
