<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Models\Calendar;

use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Enum\LitGrade;
use LiturgicalCalendar\Api\Enum\LitLocale;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Handlers\CalendarHandler;
use LiturgicalCalendar\Api\LocaleDateFormatter;
use LiturgicalCalendar\Api\Models\Calendar\LiturgicalEventCollection;
use LiturgicalCalendar\Api\Models\Calendar\Temporale\AmbrosianTemporale;
use LiturgicalCalendar\Api\Models\Calendar\Temporale\TemporaleContext;
use LiturgicalCalendar\Api\Models\Lectionary\AmbrosianReadings;
use LiturgicalCalendar\Api\Models\PropriumDeTemporeMap;
use LiturgicalCalendar\Api\Params\CalendarParams;
use LiturgicalCalendar\Api\Utilities;
use LiturgicalCalendar\Tests\Handlers\AbstractHandlerTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Plan 7 Task 8: `LiturgicalEventCollection::calculatePsalterWeek()` run against the fully
 * assembled Ambrosian collection (temporale -> comune sanctorale -> season-stamp -> precedence
 * resolution -> year-cycles/vigils), the last of the per-event passes to exercise before Task 9's
 * orchestrator wires them all into `CalendarHandler`'s request path (the Ambrosian `/calendar`
 * route stays a 501 until then).
 *
 * `calculatePsalterWeek()` itself is a rite-agnostic gap-filler (it only fills `psalter_week`
 * where it is still `null`: vigils inherit from the event they are a vigil for, Commemorations/
 * Optional Memorials inherit from a same-day ferial event, everything else defaults to `0`) and
 * needs no Ambrosian-specific change to work correctly on this collection. No change was made to
 * `calculatePsalterWeek()` or to `addLiturgicalEvent()`'s psalter pre-stamp regex
 * (`/(Advent|Lent|Easter)([1-7])/`, which also fires -- unguarded -- on Ambrosian
 * `Advent1..6`/`Lent1..5`/`Easter2..7` keys); see the Task 8 report for the full rationale.
 *
 * **PROVISIONAL -- flagged for Plan 9 ordo validation.** The values the shared pre-stamp regex
 * assigns to Ambrosian Advent/Lent/Easter Sunday keys come from the *Roman* `psalterWeek()`
 * formula (`week % 4`, floored at 4), which is not necessarily how the Ambrosian psalter's own
 * weekly cycle is numbered (Ambrosian Advent alone has 6 weeks, not 4, unlike Roman Advent's 4).
 * Nothing downstream depends on this value being liturgically correct yet.
 */
#[CoversClass(LiturgicalEventCollection::class)]
final class LiturgicalEventCollectionAmbrosianPsalterWeekTest extends AbstractHandlerTestCase
{
    private static string $originalPrimaryLanguage = '';
    private static string $originalRuntimeLocale   = '';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$originalPrimaryLanguage = LitLocale::$PRIMARY_LANGUAGE;
        self::$originalRuntimeLocale   = LitLocale::$RUNTIME_LOCALE;
    }

    public static function tearDownAfterClass(): void
    {
        LitLocale::$PRIMARY_LANGUAGE = self::$originalPrimaryLanguage;
        LitLocale::$RUNTIME_LOCALE   = self::$originalRuntimeLocale;
        parent::tearDownAfterClass();
    }

    /**
     * @param array<string> $messages
     */
    private function buildTemporaleContext(int $year, array &$messages): TemporaleContext
    {
        LitLocale::$PRIMARY_LANGUAGE = 'it';
        LitLocale::$RUNTIME_LOCALE   = 'it_IT';

        $dataFile = JsonData::AMBROSIAN_TEMPORALE_FILE->path();
        $i18nFile = strtr(JsonData::AMBROSIAN_TEMPORALE_I18N_FILE->path(), ['{locale}' => 'it']);

        $rawEvents = Utilities::jsonFileToObjectArray($dataFile);
        /** @var array<string,string> $names */
        $names = Utilities::jsonFileToArray($i18nFile);

        $map = PropriumDeTemporeMap::fromObject($rawEvents);
        $map->setNames($names);

        $params = new CalendarParams();
        $params->setParams(['year' => $year]);
        $params->setRite(Rite::AMBROSIAN);

        $cal = new LiturgicalEventCollection($params);

        return new TemporaleContext(
            $cal,
            $params,
            $map,
            new LocaleDateFormatter(LitLocale::$RUNTIME_LOCALE),
            $messages
        );
    }

    /**
     * Assembles the full pipeline through `resolve()` for the given civil year: temporale (Plan
     * 3) -> comune sanctorale (`CalendarHandler::addAmbrosianSanctoraleToCalendar()`, Plan 7 Task
     * 4) -> season-stamping (`stampAmbrosianSeasonOnSanctorale()`, Task 6) -> precedence
     * resolution (`resolveAmbrosianPrecedence()`, Task 5). Mirrors
     * `CalendarHandlerAmbrosianPrecedenceResolverTest::assembleAmbrosianYear()` exactly, returning
     * the bare `LiturgicalEventCollection` since this test only needs collection-level methods
     * (`setAmbrosianYearCyclesAndVigils()`, `calculatePsalterWeek()`) afterwards.
     */
    private function assembleResolvedAmbrosianYear(int $year = 2025): LiturgicalEventCollection
    {
        $messages = [];
        $ctx      = $this->buildTemporaleContext($year, $messages);
        ( new AmbrosianTemporale() )->buildTemporale($ctx);

        $handler = new CalendarHandler([], Rite::AMBROSIAN);

        $params = new CalendarParams();
        $params->setRite(Rite::AMBROSIAN);
        $params->setParams(['year' => $year, 'locale' => 'it']);

        $handlerRef = new \ReflectionClass($handler);

        $paramsProp = $handlerRef->getProperty('CalendarParams');
        $paramsProp->setAccessible(true);
        $paramsProp->setValue($handler, $params);

        $calProp = $handlerRef->getProperty('Cal');
        $calProp->setAccessible(true);
        $calProp->setValue($handler, $ctx->cal);

        $localeDateFormatterProp = $handlerRef->getProperty('localeDateFormatter');
        $localeDateFormatterProp->setAccessible(true);
        $localeDateFormatterProp->setValue($handler, new LocaleDateFormatter(LitLocale::$RUNTIME_LOCALE));

        $sanctoraleMethod = $handlerRef->getMethod('addAmbrosianSanctoraleToCalendar');
        $sanctoraleMethod->setAccessible(true);
        $sanctoraleMethod->invoke($handler);

        $ctx->cal->stampAmbrosianSeasonOnSanctorale();

        $resolveMethod = $handlerRef->getMethod('resolveAmbrosianPrecedence');
        $resolveMethod->setAccessible(true);
        $resolveMethod->invoke($handler);

        return $ctx->cal;
    }

    /**
     * Mirrors `LiturgicalEventCollectionAmbrosianYearCyclesAndVigilsTest::
     * assembleAmbrosianYearWithReadingsPlaceholder()`: in the real (future Task 9) pipeline every
     * Ambrosian event carries a `readings` property (comune sanctorale rows get it from
     * `addAmbrosianSanctoraleToCalendar()`; temporale-origin events don't yet, since nothing in
     * the current, still-unwired pipeline stamps one onto them). `createVigilMassFor()` (reused
     * as-is by `calculateAmbrosianVigilMass()`) reads `->readings` unconditionally, so this
     * test-only helper closes that gap by stamping the same empty-readings placeholder onto any
     * event that doesn't already have one.
     */
    private function withReadingsPlaceholder(LiturgicalEventCollection $cal): LiturgicalEventCollection
    {
        foreach ($cal->getLiturgicalEvents() as $event) {
            if (false === isset($event->readings)) {
                $event->setReadings(AmbrosianReadings::empty());
            }
        }

        return $cal;
    }

    /**
     * The acceptance gate: after the full pipeline (temporale -> sanctorale -> season-stamp ->
     * resolve -> cycles/vigils) plus `calculatePsalterWeek()`, EVERY event in the collection has
     * a non-null `psalter_week` (`0` is a valid, deliberate value from the gap-filler's default
     * branch -- this asserts absence of `null`, not absence of `0`).
     */
    public function testEveryEventHasNonNullPsalterWeekAfterFullPipeline(): void
    {
        $cal = $this->assembleResolvedAmbrosianYear(2025);
        $cal = $this->withReadingsPlaceholder($cal);

        $cal->setAmbrosianYearCyclesAndVigils();
        $cal->calculatePsalterWeek();

        foreach ($cal->getLiturgicalEvents() as $event) {
            self::assertNotNull(
                $event->psalter_week,
                sprintf(
                    'Expected event `%s` (%s) to have a non-null psalter_week after calculatePsalterWeek().',
                    $event->event_key ?? '(no event_key)',
                    $event->date->format('Y-m-d')
                )
            );
        }
    }

    /**
     * Spot-check: `Advent4` (2025-12-07, the fourth Ambrosian Advent Sunday -- see
     * `AmbrosianRealYearPrecedenceTest`) already received a psalter_week at `addLiturgicalEvent()`
     * time, via the shared (Roman-regex) pre-stamp `psalterWeek(4) === 4`. `calculatePsalterWeek()`
     * is a gap-filler and must NOT overwrite an already-set value, so this value survives the full
     * pipeline untouched. This documents the PROVISIONAL Roman-numbering value described in the
     * class docblock, not a claim that `4` is the liturgically correct Ambrosian psalter week for
     * Advent IV.
     */
    public function testAdventSundayKeepsProvisionalPreStampedPsalterWeek(): void
    {
        $cal = $this->assembleResolvedAmbrosianYear(2025);
        $cal = $this->withReadingsPlaceholder($cal);

        $advent4 = $cal->getLiturgicalEvent('Advent4');
        self::assertNotNull($advent4, 'Expected a LiturgicalEvent for temporale key Advent4');
        self::assertSame('2025-12-07', $advent4->date->format('Y-m-d'));
        self::assertSame(
            LiturgicalEventCollection::psalterWeek(4),
            $advent4->psalter_week,
            'Expected Advent4 to already carry the Roman-regex pre-stamped psalter_week before calculatePsalterWeek() runs.'
        );

        $cal->setAmbrosianYearCyclesAndVigils();
        $cal->calculatePsalterWeek();

        self::assertSame(
            LiturgicalEventCollection::psalterWeek(4),
            $advent4->psalter_week,
            'Expected calculatePsalterWeek() (a gap-filler) not to overwrite an already-set psalter_week.'
        );
    }

    /**
     * Spot-check: an after-Pentecost ferial weekday (`AfterPentecostWeekday1Tuesday`, 2025-06-10,
     * grade WEEKDAY) does not match any of `addLiturgicalEvent()`'s psalter pre-stamp branches
     * (its key contains neither `Advent`/`Lent`/`Easter` immediately followed by a digit 1-7, nor
     * `OrdSunday`), so `psalter_week` is still `null` going into `calculatePsalterWeek()`. As a
     * plain weekday (not a vigil, not a Commemoration/Optional Memorial), it falls through to the
     * gap-filler's default branch and is assigned `0`.
     *
     * (The sibling key `AfterPentecostWeekday1Monday`, 2025-06-09, is NOT used here: once
     * `resolveAmbrosianPrecedence()` runs, that particular Monday's ferial loses to a
     * higher-precedence comune sanctorale event occupying the same date and ends up suppressed --
     * a real precedence outcome, not a bug, but it means that key is no longer present in
     * `getLiturgicalEvents()` by the time this test's assertions run, so it would be a poor choice
     * of fixture for a psalter_week spot-check. `AfterPentecostWeekday1Tuesday` remains active and
     * exercises the same gap-filler branch just as well.)
     */
    public function testAfterPentecostFerialWeekdayGetsZeroFromGapFiller(): void
    {
        $cal = $this->assembleResolvedAmbrosianYear(2025);
        $cal = $this->withReadingsPlaceholder($cal);

        $weekday = $cal->getLiturgicalEvent('AfterPentecostWeekday1Tuesday');
        self::assertNotNull($weekday, 'Expected a LiturgicalEvent for AfterPentecostWeekday1Tuesday');
        self::assertSame('2025-06-10', $weekday->date->format('Y-m-d'));
        self::assertSame(LitGrade::WEEKDAY, $weekday->grade);
        self::assertNull($weekday->psalter_week, 'Expected psalter_week to still be null before calculatePsalterWeek() runs.');

        $cal->setAmbrosianYearCyclesAndVigils();
        $cal->calculatePsalterWeek();

        self::assertSame(0, $weekday->psalter_week);
    }

    /**
     * Spot-check: `Assumption` (2025-08-15, grade SOLEMNITY, not a Sunday, not week-numbered) has
     * no pre-stamp and so is `null` going into the gap-filler, which sets it to `0`. Its
     * synthesized `Assumption_vigil` (created by `setAmbrosianYearCyclesAndVigils()` via
     * `calculateAmbrosianVigilMass()`, which adds directly to the events map and bypasses
     * `addLiturgicalEvent()` entirely) is also `null` beforehand, exercising the
     * vigil-inherits-from-parent branch of `calculatePsalterWeek()`.
     */
    public function testAssumptionVigilInheritsPsalterWeekFromParentEvent(): void
    {
        $cal = $this->assembleResolvedAmbrosianYear(2025);
        $cal = $this->withReadingsPlaceholder($cal);

        $assumption = $cal->getLiturgicalEvent('Assumption');
        self::assertNotNull($assumption, 'Expected a LiturgicalEvent for comune sanctorale key Assumption');
        self::assertNull($assumption->psalter_week);

        $cal->setAmbrosianYearCyclesAndVigils();

        $vigil = $cal->getLiturgicalEvent('Assumption_vigil');
        self::assertNotNull($vigil, 'Expected a LiturgicalEvent for the synthesized Assumption_vigil key');
        self::assertNull($vigil->psalter_week, 'Expected the vigil to have no psalter_week yet (created outside addLiturgicalEvent()).');

        $cal->calculatePsalterWeek();

        self::assertNotNull($assumption->psalter_week);
        self::assertSame(0, $assumption->psalter_week);
        self::assertSame($assumption->psalter_week, $vigil->psalter_week);
    }
}
