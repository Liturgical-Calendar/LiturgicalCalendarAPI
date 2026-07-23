<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Enum\LitGrade;
use LiturgicalCalendar\Api\Enum\LitLocale;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Handlers\CalendarHandler;
use LiturgicalCalendar\Api\Http\Enum\ReturnTypeParam;
use LiturgicalCalendar\Api\Models\Calendar\LiturgicalEvent;
use LiturgicalCalendar\Api\Models\Calendar\LiturgicalEventCollection;
use LiturgicalCalendar\Api\Models\Lectionary\ReadingsFestive;
use LiturgicalCalendar\Api\Params\CalendarParams;

/**
 * Task 8 of Plan 8b (Ambrosian diocesan overlays): `CalendarHandler::loadDiocesanCalendarData()`
 * previously always resolved the diocese's nation via `world_dioceses.json` and forced
 * `CalendarParams->NationalCalendar = strtoupper($nation)`, then loaded the diocese file from the
 * Roman tree (`JsonData::DIOCESAN_CALENDAR_FILE`). That coupling is wrong for Ambrosian dioceses:
 * `CalendarParams::validateRiteCompatibility()` throws if `NationalCalendar !== null` for the
 * Ambrosian rite, and the Ambrosian diocesan source tree lives under a separate path
 * (`JsonData::AMBROSIAN_DIOCESAN_CALENDAR_FILE`).
 *
 * These tests drive the private `loadDiocesanCalendarData()` directly via reflection (mirroring
 * `CalendarHandlerAmbrosianSanctoraleLoadTest`), since the method is invoked partway through
 * `handle()` long before a full calendar is assembled.
 */
final class CalendarHandlerAmbrosianDiocesanTest extends AbstractHandlerTestCase
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
     * Builds a `CalendarHandler` with `CalendarParams` set to request the given diocese under the
     * given rite, then invokes the private `loadDiocesanCalendarData()` via reflection.
     */
    private function invokeLoadDiocesanCalendarData(Rite $rite, string $dioceseId): CalendarHandler
    {
        $handler = new CalendarHandler([], $rite);

        $params                   = ( new \ReflectionClass(CalendarParams::class) )->newInstanceWithoutConstructor();
        $params->Rite             = $rite;
        $params->DiocesanCalendar = $dioceseId;

        $handlerRef = new \ReflectionClass($handler);

        $paramsProp = $handlerRef->getProperty('CalendarParams');
        $paramsProp->setAccessible(true);
        $paramsProp->setValue($handler, $params);

        $method = $handlerRef->getMethod('loadDiocesanCalendarData');
        $method->setAccessible(true);
        $method->invoke($handler);

        return $handler;
    }

    public function testAmbrosianDioceseLoadsWithoutSettingNationalCalendar(): void
    {
        $handler = $this->invokeLoadDiocesanCalendarData(Rite::AMBROSIAN, 'lugano_ch');

        $handlerRef = new \ReflectionClass($handler);

        $paramsProp = $handlerRef->getProperty('CalendarParams');
        $paramsProp->setAccessible(true);
        /** @var CalendarParams $params */
        $params = $paramsProp->getValue($handler);

        self::assertNull(
            $params->NationalCalendar,
            'Expected NationalCalendar to remain null for an Ambrosian diocesan request (no nations/CH lookup).'
        );

        $dataProp = $handlerRef->getProperty('DiocesanData');
        $dataProp->setAccessible(true);
        $diocesanData = $dataProp->getValue($handler);

        self::assertNotNull($diocesanData, 'Expected DiocesanData to be populated for lugano_ch.');
        self::assertSame('lugano_ch', $diocesanData->metadata->diocese_id);
    }

    public function testRomanDioceseStillSetsNationalCalendar(): void
    {
        $handler = $this->invokeLoadDiocesanCalendarData(Rite::ROMAN, 'agrige_it');

        $handlerRef = new \ReflectionClass($handler);

        $paramsProp = $handlerRef->getProperty('CalendarParams');
        $paramsProp->setAccessible(true);
        /** @var CalendarParams $params */
        $params = $paramsProp->getValue($handler);

        self::assertSame(
            'IT',
            $params->NationalCalendar,
            'Expected the Roman diocesan load to keep setting NationalCalendar (unchanged behavior).'
        );

        $dataProp = $handlerRef->getProperty('DiocesanData');
        $dataProp->setAccessible(true);
        $diocesanData = $dataProp->getValue($handler);

        self::assertNotNull($diocesanData);
        self::assertSame('agrige_it', $diocesanData->metadata->diocese_id);
    }

    /**
     * Task 9 of Plan 8b: `CalendarHandler::applyAmbrosianDiocesanCalendar()` — the add-all-then-
     * resolve, diocesan-wins overlay wired into `calculateAmbrosianCalendar()` between
     * `addAmbrosianSanctoraleToCalendar()` and `backfillAmbrosianReadingsPlaceholder()`.
     *
     * Builds a fully-assembled `CalendarHandler` (real `CalendarParams`, an empty `Cal`, and a
     * `localeDateFormatter`, exactly like `CalendarHandlerAmbrosianOrchestratorTest::assembleHandler()`),
     * then also reflection-invokes `loadDiocesanCalendarData()` so `$this->DiocesanData` is
     * populated from the real on-disk diocesan JSON before `calculateAmbrosianCalendar()` runs.
     */
    private function assembleHandlerForDiocese(string $dioceseId, int $year = 2025): CalendarHandler
    {
        LitLocale::$PRIMARY_LANGUAGE = 'it';
        LitLocale::$RUNTIME_LOCALE   = 'it_IT';

        $params = new CalendarParams();
        $params->setRite(Rite::AMBROSIAN);
        $params->setParams(['year' => $year, 'locale' => 'it']);
        $params->DiocesanCalendar = $dioceseId;

        $handler = new CalendarHandler([], Rite::AMBROSIAN);

        $handlerRef = new \ReflectionClass($handler);

        $paramsProp = $handlerRef->getProperty('CalendarParams');
        $paramsProp->setAccessible(true);
        $paramsProp->setValue($handler, $params);

        $loadMethod = $handlerRef->getMethod('loadDiocesanCalendarData');
        $loadMethod->setAccessible(true);
        $loadMethod->invoke($handler);

        $calProp = $handlerRef->getProperty('Cal');
        $calProp->setAccessible(true);
        $calProp->setValue($handler, new LiturgicalEventCollection($params));

        $localeDateFormatterProp = $handlerRef->getProperty('localeDateFormatter');
        $localeDateFormatterProp->setAccessible(true);
        $localeDateFormatterProp->setValue($handler, new \LiturgicalCalendar\Api\LocaleDateFormatter(LitLocale::$RUNTIME_LOCALE));

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
     * Net-new diocesan key: `SanLuigiGuanella` (24 October, grade SOLEMNITY) is proper to the
     * Archdiocese of Milan and does not exist in the comune Ambrosian sanctorale at all. After the
     * full orchestrator runs for `milano_it` 2025, it must be present in `$this->Cal`, carry its
     * diocesan i18n name (non-empty), and carry festive (5-field) readings.
     */
    public function testMilanProperEventIsAddedWithGradeNameAndFestiveReadings(): void
    {
        $handler = $this->assembleHandlerForDiocese('milano_it', 2025);
        $cal     = $this->runOrchestrator($handler);

        $event = $cal->getLiturgicalEvent('SanLuigiGuanella');
        self::assertNotNull($event, 'Expected `SanLuigiGuanella` (Milan-proper) to be present after the diocesan overlay ran.');
        self::assertSame(LitGrade::SOLEMNITY, $event->grade);
        self::assertNotSame('', $event->name, 'Expected the diocesan i18n name to be applied (non-empty).');
        self::assertInstanceOf(ReadingsFestive::class, $event->readings);
    }

    /**
     * Diocesan-wins override: `StFrancisOfAssisi` (4 October) is FEAST (grade 4) in the comune
     * Ambrosian sanctorale but MEMORIAL (grade 3) in the `lugano_ch` diocesan calendar. After the
     * overlay runs, the diocesan grade must win (the comune definition is removed and replaced,
     * not merely shadowed).
     */
    public function testLuganoOverrideDowngradesStFrancisGradeToMemorial(): void
    {
        $handler = $this->assembleHandlerForDiocese('lugano_ch', 2025);
        $cal     = $this->runOrchestrator($handler);

        $event = $cal->getLiturgicalEvent('StFrancisOfAssisi');
        self::assertNotNull($event, 'Expected `StFrancisOfAssisi` to be present after the diocesan overlay ran.');
        self::assertSame(
            LitGrade::MEMORIAL,
            $event->grade,
            'Expected the diocesan override (MEMORIAL) to win over the comune definition (FEAST).'
        );
        self::assertInstanceOf(ReadingsFestive::class, $event->readings);
    }

    /**
     * Comune-unchanged guarantee: when no diocese is requested (`$this->DiocesanData` stays null),
     * `applyAmbrosianDiocesanCalendar()` must be a strict no-op — it must not add, remove, or
     * otherwise touch any event already in `$this->Cal`.
     */
    public function testNoOpWhenDiocesanDataIsNull(): void
    {
        LitLocale::$PRIMARY_LANGUAGE = 'it';
        LitLocale::$RUNTIME_LOCALE   = 'it_IT';

        $params = new CalendarParams();
        $params->setRite(Rite::AMBROSIAN);
        $params->setParams(['year' => 2025, 'locale' => 'it']);

        $handler = new CalendarHandler([], Rite::AMBROSIAN);

        $handlerRef = new \ReflectionClass($handler);

        $paramsProp = $handlerRef->getProperty('CalendarParams');
        $paramsProp->setAccessible(true);
        $paramsProp->setValue($handler, $params);

        $calBefore = new LiturgicalEventCollection($params);
        $calProp   = $handlerRef->getProperty('Cal');
        $calProp->setAccessible(true);
        $calProp->setValue($handler, $calBefore);

        $dataProp = $handlerRef->getProperty('DiocesanData');
        $dataProp->setAccessible(true);
        self::assertNull($dataProp->getValue($handler), 'Precondition: DiocesanData must be null for a comune-only request.');

        $method = $handlerRef->getMethod('applyAmbrosianDiocesanCalendar');
        $method->setAccessible(true);
        $method->invoke($handler);

        /** @var LiturgicalEventCollection $calAfter */
        $calAfter = $calProp->getValue($handler);
        self::assertSame($calBefore, $calAfter, 'Expected the same LiturgicalEventCollection instance, untouched.');
        self::assertCount(0, $calAfter->getLiturgicalEvents()->getKeys(), 'Expected no events to have been added by a no-op overlay.');
    }

    /**
     * Task 10 of Plan 8b: unlike the tests above (which reflection-invoke
     * `loadDiocesanCalendarData()` / `applyAmbrosianDiocesanCalendar()` / `calculateAmbrosianCalendar()`
     * directly), these drive the real, live `/calendar/ambrosian/diocese/{id}/{year}` route through the
     * full public `CalendarHandler::handle()` entry point — the same one the router dispatches to —
     * confirming `loadDiocesanCalendarData()` (called unconditionally in `handle()` before the
     * Ambrosian/Roman branch) populates `$this->DiocesanData` in time for the Ambrosian branch's
     * `calculateAmbrosianCalendar()` call to overlay it, exactly mirroring
     * `CalendarHandlerAmbrosianResponseSchemaTest::handle()`'s pattern for the comune-only route.
     */
    private function handleFullRequest(array $pathParts, string $uri): \Psr\Http\Message\ResponseInterface
    {
        // See CalendarHandlerAmbrosianResponseSchemaTest::handle() docblock: LiturgicalEvent's
        // process-lifetime internal_index counter must be reset so event_idx assertions don't
        // depend on how many other tests ran earlier in the same PHPUnit process.
        $prop = new \ReflectionProperty(LiturgicalEvent::class, 'internal_index');
        $prop->setValue(null, 0);

        $handler = new CalendarHandler($pathParts, Rite::AMBROSIAN);
        $handler->setAllowedReturnTypes([ReturnTypeParam::JSON]);
        return $handler->handle($this->requestFor('GET', $uri, ['Accept' => 'application/json']));
    }

    /**
     * @return array<string, mixed>
     */
    private function eventsByKey(array $body): array
    {
        $byKey = [];
        foreach ($body['litcal'] as $event) {
            $byKey[$event['event_key']] = $event;
        }
        return $byKey;
    }

    public function testMilanDioceseEndpointReturnsMilanProperAndComuneEvents(): void
    {
        $response = $this->handleFullRequest(
            ['diocese', 'milano_it', '2025'],
            '/calendar/ambrosian/diocese/milano_it/2025?year_type=CIVIL'
        );

        self::assertSame(200, $response->getStatusCode());

        $body  = $this->decodeJsonBody($response);
        $byKey = $this->eventsByKey($body);

        self::assertArrayHasKey(
            'SanLuigiGuanella',
            $byKey,
            'Expected the Milan-proper diocesan event `SanLuigiGuanella` to be present in the live response.'
        );
        self::assertSame(6, $byKey['SanLuigiGuanella']['grade']);

        self::assertArrayHasKey(
            'AllSaints',
            $byKey,
            'Expected the comune Ambrosian sanctorale event `AllSaints` to still be present alongside the diocesan overlay.'
        );
    }

    public function testLuganoDioceseEndpointReturns200WithNoCHNationError(): void
    {
        $response = $this->handleFullRequest(
            ['diocese', 'lugano_ch', '2025'],
            '/calendar/ambrosian/diocese/lugano_ch/2025?year_type=CIVIL'
        );

        self::assertSame(200, $response->getStatusCode());

        $body  = $this->decodeJsonBody($response);
        $byKey = $this->eventsByKey($body);

        self::assertArrayHasKey('StFrancisOfAssisi', $byKey, 'Expected `StFrancisOfAssisi` to be present in the live response.');
        self::assertSame(
            3,
            $byKey['StFrancisOfAssisi']['grade'],
            'Expected the lugano_ch diocesan override (MEMORIAL, grade 3) to win over the comune FEAST definition.'
        );

        self::assertArrayHasKey(
            'AllSaints',
            $byKey,
            'Expected the comune Ambrosian sanctorale event `AllSaints` to still be present alongside the diocesan overlay.'
        );
    }
}
