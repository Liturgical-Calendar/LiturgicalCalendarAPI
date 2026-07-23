<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

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

/**
 * Task 4 of Plan 7 (Ambrosian un-501 wiring): `CalendarHandler::addAmbrosianSanctoraleToCalendar()`
 * loads the 254-row comune Ambrosian sanctorale (`AmbrosianSanctoraleLoader`) and adds every event
 * to `$this->Cal`, unconditionally (the Ambrosian assembly model is "add everything, then resolve
 * coincidences later" — unlike the Roman check-before-add convention). The only guard is an
 * `event_key` collision against an event already present in `$this->Cal` (added earlier by the
 * Proprium de Tempore load + temporale engine): `addLiturgicalEvent()` is keyed by `event_key` and
 * would otherwise silently overwrite the temporale definition.
 *
 * These tests drive `addAmbrosianSanctoraleToCalendar()` directly via reflection (rather than
 * through `handle()`) because at the time this task was written the Ambrosian rite still 501-ed
 * at the top of `handle()` — this task only built a piece of the future Ambrosian generation
 * path; the orchestrator task (Task 9) wires it into the real request flow and Task 10 removed
 * the 501 gate.
 *
 * The engine-wiring here (building a `TemporaleContext` and running
 * `AmbrosianTemporale::buildTemporale()`) is deliberately inlined rather than reusing
 * `AmbrosianTemporaleHarnessTrait` (used by the `Models/Calendar/Temporale` suite): that trait
 * declares its own `setUpBeforeClass()`/`tearDownAfterClass()`, which would silently shadow
 * `AbstractHandlerTestCase`'s (Router path pinning) instead of composing with it. Keeping the
 * small amount of wiring local avoids that trap.
 */
final class CalendarHandlerAmbrosianSanctoraleLoadTest extends AbstractHandlerTestCase
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
     * Builds a `TemporaleContext` wired to the Ambrosian Proprium de Tempore for a given civil
     * year, mirroring how `CalendarHandler` wires the temporale engine (and how
     * `AmbrosianTemporaleHarnessTrait::buildContext()` does it for the `Models/Calendar/Temporale`
     * suite).
     *
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
     * Step 1 audit (Plan 7 Task 4): determine the ACTUAL overlap between the 254
     * `propriumdesanctis_2024` sanctorale event_keys and the Ambrosian temporale keys — both the
     * raw `propriumdetempore.json` keys and the full set of keys the temporale engine
     * (`AmbrosianTemporale::buildTemporale()`) leaves in the calendar after running (i.e.
     * including any keys the engine synthesizes/derives, and confirming none of the three
     * raw-overlap keys are removed or renamed along the way).
     *
     * Finding: contrary to the Roman curation convention (where the sanctorale does not
     * re-declare temporale-owned solemnities), the Ambrosian source data DOES declare
     * `Christmas`, `Circoncisione`, and `Epiphany` in both
     * `propriumdetempore/propriumdetempore.json` and
     * `propriumdesanctis_2024/propriumdesanctis.json`. All three keys remain present in the
     * temporale engine's output. This is exactly the overlap the task brief warned about, and it
     * is real (not merely hypothetical) — `addAmbrosianSanctoraleToCalendar()`'s collision guard
     * is exercised on every Ambrosian request, not just defensively.
     */
    public function testActualOverlapBetweenTemporaleAndSanctoraleKeysIsExactlyThreeKnownKeys(): void
    {
        $sanctoraleFile = JsonData::AMBROSIAN_SANCTORALE_FILE->path();
        $sanctoraleKeys = array_column(
            json_decode((string) file_get_contents($sanctoraleFile), true, flags: JSON_THROW_ON_ERROR),
            'event_key'
        );
        self::assertCount(254, $sanctoraleKeys, 'Expected 254 comune sanctorale event_keys in the source file.');

        $rawTemporaleFile = JsonData::AMBROSIAN_TEMPORALE_FILE->path();
        $rawTemporaleKeys = array_column(
            json_decode((string) file_get_contents($rawTemporaleFile), true, flags: JSON_THROW_ON_ERROR),
            'event_key'
        );

        $rawOverlap = array_values(array_intersect($rawTemporaleKeys, $sanctoraleKeys));
        sort($rawOverlap);
        self::assertSame(
            ['Christmas', 'Circoncisione', 'Epiphany'],
            $rawOverlap,
            'Expected exactly these three keys to be declared in BOTH raw source files.'
        );

        // Confirm the engine doesn't remove/rename any of the three overlapping keys, and doesn't
        // introduce any *other* synthesized key that also collides with the sanctorale.
        $messages = [];
        $ctx      = $this->buildTemporaleContext(2025, $messages);
        ( new AmbrosianTemporale() )->buildTemporale($ctx);
        $engineKeys = $ctx->cal->getLiturgicalEvents()->getKeys();

        $engineOverlap = array_values(array_intersect($engineKeys, $sanctoraleKeys));
        sort($engineOverlap);
        self::assertSame(
            ['Christmas', 'Circoncisione', 'Epiphany'],
            $engineOverlap,
            'Expected the temporale engine output to still collide with the sanctorale on exactly these three keys.'
        );
    }

    /**
     * Builds a `CalendarHandler` whose `$Cal` is pre-populated by the Ambrosian temporale engine
     * (mirroring what will have already run, earlier in the real pipeline, by the time
     * `addAmbrosianSanctoraleToCalendar()` executes), then invokes the new (private) method via
     * reflection.
     *
     * @return array{0:LiturgicalEventCollection,1:array<int,string>}
     */
    private function runSanctoraleStep(int $year = 2025): array
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

        $method = $handlerRef->getMethod('addAmbrosianSanctoraleToCalendar');
        $method->setAccessible(true);
        $method->invoke($handler);

        /** @var LiturgicalEventCollection $cal */
        $cal = $calProp->getValue($handler);

        $messagesProp = $handlerRef->getProperty('Messages');
        $messagesProp->setAccessible(true);
        /** @var array<int,string> $resultMessages */
        $resultMessages = $messagesProp->getValue($handler);

        return [$cal, $resultMessages];
    }

    public function testKnownComuneSaintIsAddedWithPlausibleDateAndGrade(): void
    {
        [$cal] = $this->runSanctoraleStep(2025);

        $stAmbrose = $cal->getLiturgicalEvent('StAmbrose');
        self::assertNotNull($stAmbrose, 'Expected `StAmbrose` to be present in the calendar after the sanctorale step.');
        self::assertSame('2025-12-07', $stAmbrose->date->format('Y-m-d'));
        self::assertSame(LitGrade::SOLEMNITY, $stAmbrose->grade);
    }

    public function testSanctoraleEventsCarryTheEmptyReadingsPlaceholder(): void
    {
        [$cal] = $this->runSanctoraleStep(2025);

        $stAmbrose = $cal->getLiturgicalEvent('StAmbrose');
        self::assertNotNull($stAmbrose);
        self::assertTrue(isset($stAmbrose->readings), 'Expected `StAmbrose` to carry a readings object.');
        self::assertSame(
            AmbrosianReadings::empty()->jsonSerialize(),
            $stAmbrose->readings->jsonSerialize(),
            'Expected the readings to be the Task 2 empty-readings placeholder.'
        );
    }

    public function testCollidingKeysAreSkippedAndTemporaleDefinitionWins(): void
    {
        $messages = [];
        $ctx      = $this->buildTemporaleContext(2025, $messages);
        ( new AmbrosianTemporale() )->buildTemporale($ctx);

        // Capture the temporale-produced objects for the three colliding keys BEFORE running the
        // sanctorale step, so we can assert identity (not merely equality) afterwards.
        $temporaleChristmas     = $ctx->cal->getLiturgicalEvent('Christmas');
        $temporaleCirconcisione = $ctx->cal->getLiturgicalEvent('Circoncisione');
        $temporaleEpiphany      = $ctx->cal->getLiturgicalEvent('Epiphany');
        self::assertNotNull($temporaleChristmas);
        self::assertNotNull($temporaleCirconcisione);
        self::assertNotNull($temporaleEpiphany);

        $keysBefore = $ctx->cal->getLiturgicalEvents()->getKeys();

        $handler = new CalendarHandler([], Rite::AMBROSIAN);

        $params = new CalendarParams();
        $params->setRite(Rite::AMBROSIAN);
        $params->setParams(['year' => 2025, 'locale' => 'it']);

        $handlerRef = new \ReflectionClass($handler);

        $paramsProp = $handlerRef->getProperty('CalendarParams');
        $paramsProp->setAccessible(true);
        $paramsProp->setValue($handler, $params);

        $calProp = $handlerRef->getProperty('Cal');
        $calProp->setAccessible(true);
        $calProp->setValue($handler, $ctx->cal);

        $method = $handlerRef->getMethod('addAmbrosianSanctoraleToCalendar');
        $method->setAccessible(true);
        $method->invoke($handler);

        /** @var LiturgicalEventCollection $cal */
        $cal = $calProp->getValue($handler);

        // The temporale definitions must NOT have been overwritten (same object identity).
        self::assertSame($temporaleChristmas, $cal->getLiturgicalEvent('Christmas'));
        self::assertSame($temporaleCirconcisione, $cal->getLiturgicalEvent('Circoncisione'));
        self::assertSame($temporaleEpiphany, $cal->getLiturgicalEvent('Epiphany'));

        // No duplicate/overwritten keys: exactly (254 - 3) new keys were added on top of the
        // pre-existing temporale keys (3 sanctorale rows were skipped due to collision).
        $keysAfter = $cal->getLiturgicalEvents()->getKeys();
        self::assertCount(count($keysBefore) + ( 254 - 3 ), $keysAfter);
        self::assertSame(count($keysAfter), count(array_unique($keysAfter)), 'Expected no duplicate event_keys in the collection.');

        $messagesProp = $handlerRef->getProperty('Messages');
        $messagesProp->setAccessible(true);
        /** @var array<int,string> $resultMessages */
        $resultMessages = $messagesProp->getValue($handler);

        $joined = implode("\n", $resultMessages);
        self::assertStringContainsString('Christmas', $joined);
        self::assertStringContainsString('Circoncisione', $joined);
        self::assertStringContainsString('Epiphany', $joined);
    }
}
