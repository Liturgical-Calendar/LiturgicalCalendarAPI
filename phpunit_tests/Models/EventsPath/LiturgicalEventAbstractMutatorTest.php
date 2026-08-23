<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Models\EventsPath;

use LiturgicalCalendar\Api\Enum\LitGrade;
use LiturgicalCalendar\Api\Models\Calendar\LitCommons;
use LiturgicalCalendar\Api\Models\EventsPath\LiturgicalEventFixed;
use PHPUnit\Framework\TestCase;

/**
 * Task 6: `LiturgicalEventAbstract::applyGrade()`/`applyCommon()`/`applyName()` mutate a catalog
 * entry in place while re-deriving the localized fields (`grade_lcl`, `grade_abbr`, `common_lcl`)
 * that `LiturgicalEventAbstract`'s constructor computes and `jsonSerialize()` emits alongside the
 * raw values. A naive direct property assignment would leave those localized companions stale.
 */
final class LiturgicalEventAbstractMutatorTest extends TestCase
{
    private function makeEvent(): LiturgicalEventFixed
    {
        return LiturgicalEventFixed::fromArray([
            'event_key' => 'StsProtaseGervase',
            'name'      => 'Ss. Protaso e Gervaso, martiri',
            'month'     => 6,
            'day'       => 19,
            'grade'     => 4,
            'color'     => ['red'],
            'common'    => ['Martyrs:For Several Martyrs'],
        ]);
    }

    private function makeEventWithGradeDisplay(string $gradeDisplay): LiturgicalEventFixed
    {
        return LiturgicalEventFixed::fromArray([
            'event_key'     => 'StsProtaseGervase',
            'name'          => 'Ss. Protaso e Gervaso, martiri',
            'month'         => 6,
            'day'           => 19,
            'grade'         => 4,
            'color'         => ['red'],
            'common'        => ['Martyrs:For Several Martyrs'],
            'grade_display' => $gradeDisplay,
        ]);
    }

    public function testApplyGradeAlsoRefreshesTheLocalizedGrade(): void
    {
        $event  = $this->makeEvent();
        $before = $event->jsonSerialize()['grade_lcl'];

        $event->applyGrade(LitGrade::MEMORIAL);

        $after = $event->jsonSerialize();
        self::assertSame(LitGrade::MEMORIAL->value, $after['grade']);
        self::assertNotSame($before, $after['grade_lcl'], 'grade_lcl must be re-derived, not left stale.');
    }

    public function testApplyGradeToHigherSolemnityClearsGradeDisplay(): void
    {
        $event = $this->makeEventWithGradeDisplay('Some Explicit Override');

        $event->applyGrade(LitGrade::HIGHER_SOLEMNITY);

        self::assertSame('', $event->jsonSerialize()['grade_display'], 'grade_display must be cleared to \'\' for HIGHER_SOLEMNITY, mirroring the constructor.');
    }

    public function testApplyGradeToNonHigherSolemnityPreservesExistingGradeDisplay(): void
    {
        $event = $this->makeEventWithGradeDisplay('Some Explicit Override');

        $event->applyGrade(LitGrade::MEMORIAL);

        self::assertSame('Some Explicit Override', $event->jsonSerialize()['grade_display'], 'a legitimate explicit grade_display override must survive a non-HIGHER_SOLEMNITY grade change.');
    }

    public function testApplyCommonAlsoRefreshesTheLocalizedCommon(): void
    {
        $event  = $this->makeEvent();
        $before = $event->jsonSerialize()['common_lcl'];

        $newCommon = LitCommons::create(['Proper']);
        self::assertNotNull($newCommon);
        $event->applyCommon($newCommon);

        $after = $event->jsonSerialize();
        self::assertSame(['Proper'], $after['common']);
        self::assertNotSame($before, $after['common_lcl'], 'common_lcl must be re-derived, not left stale.');
    }

    public function testApplyNameSetsTheName(): void
    {
        $event = $this->makeEvent();
        $event->applyName('Ss. Protaso e Gervaso');

        self::assertSame('Ss. Protaso e Gervaso', $event->jsonSerialize()['name']);
    }
}
