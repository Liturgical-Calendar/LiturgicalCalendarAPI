<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Enum\LitLocale;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Handlers\CalendarHandler;
use LiturgicalCalendar\Api\LocaleDateFormatter;
use LiturgicalCalendar\Api\Models\Calendar\LiturgicalEventCollection;
use LiturgicalCalendar\Api\Params\CalendarParams;

/**
 * Plan 7 Task 9: `CalendarHandler::calculateAmbrosianCalendar()`, the single orchestrator that
 * assembles Tasks 3-8 (rite-aware Proprium de Tempore load, temporale engine, comune sanctorale,
 * season-stamping, precedence resolution, Holy Days of Obligation, year-cycles/vigils, psalter
 * week) into the exact call sequence the `/calendar/ambrosian` route now uses since Plan 7
 * Task 10 lifted the 501 gate and wired this method into `handle()`.
 *
 * Unlike the per-task tests (`CalendarHandlerAmbrosianPrecedenceResolverTest`,
 * `LiturgicalEventCollectionAmbrosianPsalterWeekTest`, etc.), which manually chain the individual
 * passes together (each hand-building a `TemporaleContext` and invoking `AmbrosianTemporale`
 * directly), this test invokes `calculateAmbrosianCalendar()` itself via reflection — it is the
 * one seam that proves the orchestrator's own internal wiring (including its own
 * `loadPropriumDeTemporeData()` + `TemporaleContext` + `RiteProfileFactory` construction) is
 * correct, not just that the individual passes work when chained by test code.
 *
 * This test still reaches the orchestrator directly via reflection (rather than through
 * `handle()`), exactly as the Task 5/6/7/8 tests reach their respective methods; the full
 * `handle()` path (now live) is covered separately by `CalendarRiteRoutingTest`,
 * `AmbrosianLitCalSchemaTest`, and `phpunit_tests/Routes/Readonly/AmbrosianCalendarTest`.
 */
final class CalendarHandlerAmbrosianOrchestratorTest extends AbstractHandlerTestCase
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
     * Builds a bare `CalendarHandler` with just enough state (`CalendarParams`, an empty `Cal`,
     * and a `localeDateFormatter`) for `calculateAmbrosianCalendar()` to run — mirroring how
     * `handle()` itself only ever calls `calculateUniversalCalendar()` after assigning
     * `$this->Cal = new LiturgicalEventCollection($this->CalendarParams)`, and bypassing
     * `prepareL10N()` (which needs a full request-locale round trip) in favour of setting
     * `LitLocale::$PRIMARY_LANGUAGE`/`$RUNTIME_LOCALE` directly, exactly as the Task 5/6/7/8 tests
     * do.
     */
    private function assembleHandler(int $year = 2025): CalendarHandler
    {
        LitLocale::$PRIMARY_LANGUAGE = 'it';
        LitLocale::$RUNTIME_LOCALE   = 'it_IT';

        $params = new CalendarParams();
        $params->setRite(Rite::AMBROSIAN);
        $params->setParams(['year' => $year, 'locale' => 'it']);

        $handler = new CalendarHandler([], Rite::AMBROSIAN);

        $handlerRef = new \ReflectionClass($handler);

        $paramsProp = $handlerRef->getProperty('CalendarParams');
        $paramsProp->setAccessible(true);
        $paramsProp->setValue($handler, $params);

        $calProp = $handlerRef->getProperty('Cal');
        $calProp->setAccessible(true);
        $calProp->setValue($handler, new LiturgicalEventCollection($params));

        $localeDateFormatterProp = $handlerRef->getProperty('localeDateFormatter');
        $localeDateFormatterProp->setAccessible(true);
        $localeDateFormatterProp->setValue($handler, new LocaleDateFormatter(LitLocale::$RUNTIME_LOCALE));

        return $handler;
    }

    private function runOrchestrator(CalendarHandler $handler): LiturgicalEventCollection
    {
        $handlerRef = new \ReflectionClass($handler);

        $method = $handlerRef->getMethod('calculateAmbrosianCalendar');
        $method->setAccessible(true);
        $method->invoke($handler);

        $calProp = $handlerRef->getProperty('Cal');
        $calProp->setAccessible(true);
        /** @var LiturgicalEventCollection $cal */
        $cal = $calProp->getValue($handler);

        return $cal;
    }

    /**
     * The end-to-end proof: `StAmbrose` (7 December, a saint Solemnity in the Ambrosian comune
     * sanctorale) coincides with `Advent4` (the fourth Ambrosian Advent Sunday) on 2025-12-07.
     * Per the Tabella dei giorni liturgici, the privileged Sunday outranks the saint Solemnity, so
     * `StAmbrose` transfers: 2025-12-08 is occupied by the Immaculate Conception (also a
     * Solemnity), so `StAmbrose` anticipates instead to the preceding Saturday, 2025-12-06.
     *
     * This is the same result validated per-pass in
     * `CalendarHandlerAmbrosianPrecedenceResolverTest`, now reached via the single orchestrator
     * call.
     */
    public function testStAmbroseAnticipatesToDec6ViaSingleOrchestratorCall(): void
    {
        $handler = $this->assembleHandler(2025);
        $cal     = $this->runOrchestrator($handler);

        $stAmbrose = $cal->getLiturgicalEvent('StAmbrose');
        self::assertNotNull($stAmbrose, 'Expected `StAmbrose` to survive the full orchestrator pipeline (transferred, not suppressed).');
        self::assertFalse($cal->isSuppressed('StAmbrose'), 'Expected `StAmbrose` NOT to be suppressed.');
        self::assertSame('2025-12-06', $stAmbrose->date->format('Y-m-d'));

        $advent4 = $cal->getLiturgicalEvent('Advent4');
        self::assertNotNull($advent4);
        self::assertSame('2025-12-07', $advent4->date->format('Y-m-d'));
    }

    /**
     * Completeness gate: every event surviving the full orchestrator pipeline has a non-null
     * `liturgical_season` AND a non-null `psalter_week`. This is the handler-level analogue of the
     * Plan 6 temporale-completeness gate and Task 8's psalter-week gap-free assertion, now checked
     * against the fully assembled (temporale + sanctorale + resolved + cycled) collection.
     */
    public function testEveryEventHasSeasonAndPsalterWeekAfterOrchestrator(): void
    {
        $handler = $this->assembleHandler(2025);
        $cal     = $this->runOrchestrator($handler);

        $checked = 0;
        foreach ($cal->getLiturgicalEvents() as $event) {
            $checked++;
            self::assertNotNull(
                $event->liturgical_season,
                sprintf(
                    'Expected event `%s` (%s) to have a non-null liturgical_season after the full orchestrator pipeline.',
                    $event->event_key ?? '(no event_key)',
                    $event->date->format('Y-m-d')
                )
            );
            self::assertNotNull(
                $event->psalter_week,
                sprintf(
                    'Expected event `%s` (%s) to have a non-null psalter_week after the full orchestrator pipeline.',
                    $event->event_key ?? '(no event_key)',
                    $event->date->format('Y-m-d')
                )
            );
        }

        self::assertGreaterThan(0, $checked, 'Expected at least one liturgical event in the assembled collection.');
    }

    /**
     * `Christmas` (25 December) must be marked as a Holy Day of Obligation once
     * `setAmbrosianHolyDaysOfObligation()` has run as part of the orchestrator.
     */
    public function testChristmasIsMarkedHolyDayOfObligation(): void
    {
        $handler = $this->assembleHandler(2025);
        $cal     = $this->runOrchestrator($handler);

        $christmas = $cal->getLiturgicalEvent('Christmas');
        self::assertNotNull($christmas, 'Expected a LiturgicalEvent for Christmas.');
        self::assertTrue($christmas->holy_day_of_obligation, 'Expected Christmas to be marked as a Holy Day of Obligation.');
    }

    /**
     * At least one Solemnity in the assembled collection has a synthesized `{key}_vigil` event,
     * proving `setAmbrosianYearCyclesAndVigils()` ran as part of the orchestrator.
     */
    public function testAtLeastOneSolemnityHasAVigilEvent(): void
    {
        $handler = $this->assembleHandler(2025);
        $cal     = $this->runOrchestrator($handler);

        $vigilKeys = [];
        foreach ($cal->getLiturgicalEvents() as $event) {
            if (true === ( $event->is_vigil_mass ?? false )) {
                $vigilKeys[] = $event->event_key;
            }
        }

        self::assertNotEmpty($vigilKeys, 'Expected at least one synthesized `{key}_vigil` event after the orchestrator ran.');
    }
}
