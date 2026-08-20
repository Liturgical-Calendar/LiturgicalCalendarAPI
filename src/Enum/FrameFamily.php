<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Enum;

/**
 * The kind of check a result frame reports, for the sake of the legacy CSS classes and nothing else.
 *
 * `step` and `status` are one vocabulary for every frame. The classes the current clients match on are
 * not: a source-data check and a calendar validation address a `schema-valid` box, while a unit test
 * addresses a `test-valid` one — the same {@see Step::VALIDATES}, projected onto two different legacy
 * names — and the test address carries its year segment *before* the step where a calendar's carries it
 * after (`.MyTest.year-2024.test-valid` against `.calendar-IT.schema-valid.year-2024`).
 *
 * Those two irregularities are facts about the family, not about any call site, which is why the family
 * is a value passed to {@see \LiturgicalCalendar\Api\Health::sendStepResult()} rather than a class name
 * or a segment order handed back to the emitters. One place still composes every selector; this only
 * tells it which of the two grammars it is composing.
 *
 * Nothing here reaches the wire, and nothing should. It exists because `classes` exists, and it goes
 * when `classes` goes — together with {@see \LiturgicalCalendar\Api\Health::FRAME_CLASS_FOR_STEP} and
 * the `$classFragment` / `$classQualifier` parameters.
 */
enum FrameFamily: string
{
    /** A source-data check or a calendar validation: `exists`, `parses`, `validates`. */
    case CHECK = 'check';

    /** A unit test run against a computed calendar: one named outcome, addressed `test-valid`. */
    case TEST_RUN = 'test-run';
}
