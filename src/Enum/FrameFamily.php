<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Enum;

/**
 * The kind of check a result frame reports, and the legacy CSS class grammar that goes with it.
 *
 * `step` and `status` are one vocabulary for every frame. The classes the current clients match on are
 * not: a source-data check and a calendar validation address a `schema-valid` box, while a unit test
 * addresses a `test-valid` one — the same {@see Step::VALIDATES}, projected onto two different legacy
 * names — and the test address carries its year segment *before* the step where a calendar's carries it
 * after (`.MyTest.year-2024.test-valid` against `.calendar-IT.schema-valid.year-2024`).
 *
 * Those two irregularities are facts about the family, not about any call site, so they are declared
 * here, in {@see FrameFamily::CLASS_FOR_STEP} and {@see FrameFamily::frameClasses()}, and nowhere else.
 * **`frameClasses()` is the only thing in the repository that composes one of these selectors.** It
 * lives on the enum rather than inside `Health` because it has two callers in two namespaces:
 * `Health::sendStepResult()`, for every check and calendar frame, and
 * `LitTestRunner::setMessage()`, for the frame a test that actually ran produces. While the projection
 * sat in `Health` the second of those had its own copy of `".$test.year-$year.test-valid"`, so the
 * label-as-selector defect #820 fixed inside `Health` was still half-true across the repository.
 *
 * Callers pass segments — never dots, never positions, never class names. A step a family does not have
 * is refused rather than given an invented class, which covers `complete` on a check (the terminal frame
 * is not a check and has no card to address, #821) and every step but `validates` on a test run (one
 * named outcome, not a three-step pipeline).
 *
 * Nothing here reaches the wire, and nothing should. This whole enum exists because `classes` exists,
 * and it goes when `classes` goes, together with the `$classFragment` / `$classQualifier` parameters of
 * `Health::sendStepResult()` and the `classes` assignment in `LitTestRunner::setMessage()`. There is no
 * other half left: those two call sites and this file are the entire legacy address surface.
 */
enum FrameFamily: string
{
    /** A source-data check or a calendar validation: `exists`, `parses`, `validates`. */
    case CHECK = 'check';

    /** A unit test run against a computed calendar: one named outcome, addressed `test-valid`. */
    case TEST_RUN = 'test-run';

    /**
     * The legacy CSS class fragment for each published step, per family.
     *
     * The wire carries two vocabularies during migration: `step` is what `GET /validations` publishes,
     * and `classes` is what the current clients match on. This is the projection between them, and it
     * exists in exactly one place so they cannot drift.
     *
     * It is keyed by family because the projection is **not** one-to-one: a unit test's one outcome is
     * `Step::VALIDATES` on the wire, exactly as a schema check is, but it addresses a `test-valid` box
     * rather than a `schema-valid` one. Keying by family is what lets both be true without a caller ever
     * naming a class — and it keeps the divergence *declared data* instead of an override argument that
     * any future caller could reach for.
     *
     * @var array<string, array<string, string>>
     */
    private const CLASS_FOR_STEP = [
        self::CHECK->value    => [
            'exists'    => 'file-exists',
            'parses'    => 'json-valid',
            'validates' => 'schema-valid'
        ],
        self::TEST_RUN->value => [
            'validates' => 'test-valid'
        ]
    ];

    /**
     * Compose the `classes` selector a result frame of this family is addressed by.
     *
     * The one place in the repository that turns segments into a selector. The dots, the ordering and
     * the step's class are its business; a caller supplies values.
     *
     * @param string $fragment The identity segment, without the leading dot: an inventory id's class
     *        fragment, `calendar-{id}`, or a test name.
     * @param Step $step The step being reported. A step this family does not have cannot be addressed and
     *        is refused rather than emitted, since a frame classed `.<fragment>.` matches zero cards —
     *        the silent mismatch this whole projection exists to end. PHPStan cannot catch it for us: the
     *        table is typed `array<string, array<string, string>>` rather than as a shape.
     * @param ?string $qualifier One further segment, or null when the address is the fragment and the step
     *        alone. Where it sits is the family's business, not the caller's: `CHECK` puts it after the
     *        step and `TEST_RUN` before it, which is how the same `year-{year}` lands on either side.
     *        An empty string is treated as absent — `.frag..test-valid` would match nothing, and neither
     *        caller can produce one today, so this closes the hazard rather than fixing a regression.
     * @throws \LogicException When this family has no class for `$step`.
     */
    public function frameClasses(string $fragment, Step $step, ?string $qualifier = null): string
    {
        $stepClass = self::CLASS_FOR_STEP[$this->value][$step->value]
            ?? throw new \LogicException("Step::{$step->name} has no legacy frame class in the {$this->value} family and cannot be sent as a step result.");

        $qualifierSegments = null === $qualifier || '' === $qualifier ? [] : [$qualifier];

        // The two grammars differ in exactly one thing — which side of the step class the qualifier falls
        // on — and both orders live here. Expressing it as a family rather than as two parameters makes
        // "before *and* after" unrepresentable; leaving the match exhaustive, with no default arm, means a
        // third family fails PHPStan here instead of silently borrowing one of these two grammars.
        $segments = match ($this) {
            self::CHECK    => [$fragment, $stepClass, ...$qualifierSegments],
            self::TEST_RUN => [$fragment, ...$qualifierSegments, $stepClass]
        };

        return '.' . implode('.', $segments);
    }
}
