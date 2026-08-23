<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Models\Calendar;

use LiturgicalCalendar\Api\DateTime;
use LiturgicalCalendar\Api\Enum\LitColor;
use LiturgicalCalendar\Api\Enum\LitCommon;
use LiturgicalCalendar\Api\Enum\LitEventType;
use LiturgicalCalendar\Api\Enum\LitGrade;
use LiturgicalCalendar\Api\Models\Calendar\LitCommons;
use LiturgicalCalendar\Api\Models\Calendar\LiturgicalEvent;
use LiturgicalCalendar\Api\Models\Calendar\LiturgicalEventCollection;
use LiturgicalCalendar\Api\Models\Lectionary\ReadingsCommons;
use LiturgicalCalendar\Api\Params\CalendarParams;
use LiturgicalCalendar\Api\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * `LiturgicalEvent` derives several serialized fields from other properties, and
 * `LiturgicalEventCollection::setProperty()` writes those source properties by reflection, behind
 * the constructor's back (#872). These tests pin the invariant that makes that safe: after a write,
 * every dependent field must read exactly as it would have if the event had been CONSTRUCTED with
 * the new value — which is why each assertion compares against a freshly constructed reference
 * event rather than against a hardcoded localized string (the model's locale is process-global
 * static state, so a literal expectation would be order-dependent).
 */
#[CoversClass(LiturgicalEventCollection::class)]
final class LiturgicalEventCollectionSetPropertyDerivationTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        Router::getApiPaths();
    }

    private function makeCollection(): LiturgicalEventCollection
    {
        $params = new CalendarParams();
        $params->setParams(['year' => 2025, 'locale' => 'en']);

        return new LiturgicalEventCollection($params);
    }

    /**
     * @param LitColor|LitColor[] $color
     */
    private function makeEvent(
        LitColor|array $color = LitColor::RED,
        LitGrade $grade = LitGrade::FEAST,
        ?string $displayGrade = null
    ): LiturgicalEvent {
        $common = LitCommons::create(['Martyrs:For Several Martyrs']);
        self::assertNotNull($common);

        $event            = new LiturgicalEvent('Test Event', new DateTime('2025-06-19'), $color, LitEventType::FIXED, $grade, $common, $displayGrade);
        $event->event_key = 'TestEvent';
        $event->setReadings(new ReadingsCommons(LitCommons::create([LitCommon::NONE])));

        return $event;
    }

    public function testSetPropertyColorAlsoRefreshesTheLocalizedColor(): void
    {
        $cal = $this->makeCollection();
        $cal->addLiturgicalEvent('TestEvent', $this->makeEvent(LitColor::RED));

        $before = $cal->getLiturgicalEvent('TestEvent')->jsonSerialize()['color_lcl'];

        self::assertTrue($cal->setProperty('TestEvent', 'color', [LitColor::WHITE]));

        $after = $cal->getLiturgicalEvent('TestEvent')->jsonSerialize()['color_lcl'];
        self::assertNotSame($before, $after, 'color_lcl must be re-derived after a `color` setProperty write, not left describing the previous colour.');
        self::assertSame(
            $this->makeEvent(LitColor::WHITE)->jsonSerialize()['color_lcl'],
            $after,
            'color_lcl after a write must equal what the constructor would have derived for the same colour.'
        );
    }

    public function testSetPropertyGradeAlsoRefreshesTheLocalizedGradeFields(): void
    {
        $cal = $this->makeCollection();
        $cal->addLiturgicalEvent('TestEvent', $this->makeEvent(LitColor::RED, LitGrade::FEAST));

        self::assertTrue($cal->setProperty('TestEvent', 'grade', LitGrade::MEMORIAL));

        $after     = $cal->getLiturgicalEvent('TestEvent')->jsonSerialize();
        $reference = $this->makeEvent(LitColor::RED, LitGrade::MEMORIAL)->jsonSerialize();
        self::assertSame($reference['grade_lcl'], $after['grade_lcl']);
        self::assertSame($reference['grade_abbr'], $after['grade_abbr']);
    }

    public function testSetPropertyCommonAlsoRefreshesTheLocalizedCommon(): void
    {
        $cal = $this->makeCollection();
        $cal->addLiturgicalEvent('TestEvent', $this->makeEvent());

        $newCommon = LitCommons::create(['Proper']);
        self::assertNotNull($newCommon);

        $reference            = new LiturgicalEvent('Test Event', new DateTime('2025-06-19'), LitColor::RED, LitEventType::FIXED, LitGrade::FEAST, $newCommon);
        $reference->event_key = 'TestEvent';
        $reference->setReadings(new ReadingsCommons(LitCommons::create([LitCommon::NONE])));

        self::assertTrue($cal->setProperty('TestEvent', 'common', $newCommon));

        self::assertSame(
            $reference->jsonSerialize()['common_lcl'],
            $cal->getLiturgicalEvent('TestEvent')->jsonSerialize()['common_lcl'],
            'common_lcl after a write must equal what the constructor would have derived for the same Common.'
        );
    }

    /**
     * An explicit `grade_display` override is authored, not derived: nothing can recompute it from
     * the grade. The constructor's one grade-coupled rule is that a HIGHER_SOLEMNITY clears it to
     * `''`; every other grade leaves the caller's value alone. A `grade` write must mirror exactly
     * that, so an override survives an ordinary grade change (as `AllSouls` and `DedicationLateran`
     * rely on) while a promotion to HIGHER_SOLEMNITY still clears it.
     */
    public function testGradeWriteLeavesAnExplicitGradeDisplayOverrideInPlace(): void
    {
        $cal = $this->makeCollection();
        $cal->addLiturgicalEvent('TestEvent', $this->makeEvent(LitColor::RED, LitGrade::FEAST, 'Some Explicit Override'));

        self::assertTrue($cal->setProperty('TestEvent', 'grade', LitGrade::MEMORIAL));

        self::assertSame(
            'Some Explicit Override',
            $cal->getLiturgicalEvent('TestEvent')->jsonSerialize()['grade_display'],
            'an explicit grade_display override must survive a non-HIGHER_SOLEMNITY grade change.'
        );
    }

    public function testGradeWriteToHigherSolemnityClearsGradeDisplay(): void
    {
        $cal = $this->makeCollection();
        $cal->addLiturgicalEvent('TestEvent', $this->makeEvent(LitColor::RED, LitGrade::FEAST, 'Some Explicit Override'));

        self::assertTrue($cal->setProperty('TestEvent', 'grade', LitGrade::HIGHER_SOLEMNITY));

        self::assertSame(
            '',
            $cal->getLiturgicalEvent('TestEvent')->jsonSerialize()['grade_display'],
            'grade_display must be cleared to \'\' for HIGHER_SOLEMNITY, mirroring the constructor.'
        );
    }

    /**
     * `setProperty()` answers "did anything change?" for the caller. For an object-valued property
     * that answer has to be about the VALUE: a freshly built `LitCommons` carrying exactly the same
     * Commons is never `===` the stored one, so an identity comparison reports a change that did
     * not happen, and callers are left to compare serialized values at each call site (#872).
     */
    public function testSetPropertyReportsNoChangeForAnEquivalentObjectValue(): void
    {
        $cal = $this->makeCollection();
        $cal->addLiturgicalEvent('TestEvent', $this->makeEvent());

        $equivalentCommon = LitCommons::create(['Martyrs:For Several Martyrs']);
        self::assertNotNull($equivalentCommon);

        self::assertFalse(
            $cal->setProperty('TestEvent', 'common', $equivalentCommon),
            'setProperty() must report false for a Common equivalent to the one already stored, even though it is a different object.'
        );
    }
}
