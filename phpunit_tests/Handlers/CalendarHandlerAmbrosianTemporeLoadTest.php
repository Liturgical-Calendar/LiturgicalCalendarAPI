<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Enum\LitLocale;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Handlers\CalendarHandler;
use LiturgicalCalendar\Api\Models\PropriumDeTemporeMap;
use LiturgicalCalendar\Api\Params\CalendarParams;

/**
 * Task 3 of Plan 7 (Ambrosian un-501 wiring): `CalendarHandler::loadPropriumDeTemporeData()`
 * is called at the start of `calculateUniversalCalendar()`, BEFORE the rite dispatch that
 * hands off to the rite's temporale engine. Prior to this task it was Roman-hardcoded, so an
 * Ambrosian request would have calculated against the Roman Proprium de Tempore.
 *
 * These tests drive `loadPropriumDeTemporeData()` directly via reflection (rather than through
 * `handle()`) because at the time this task was written the Ambrosian rite still 501-ed at the
 * top of `handle()` — this task only made the tempore-load step rite-aware in preparation for
 * the later orchestrator task (Plan 7 Task 10) that removed the 501 gate.
 */
final class CalendarHandlerAmbrosianTemporeLoadTest extends AbstractHandlerTestCase
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
     * Builds a CalendarHandler with CalendarParams wired for the given rite/locale/year via
     * reflection (both `CalendarParams` and `loadPropriumDeTemporeData()` are private), then
     * invokes the tempore-load step and returns the resulting `PropriumDeTempore` map.
     */
    private function loadTempore(Rite $rite, string $locale, int $year = 2025): PropriumDeTemporeMap
    {
        LitLocale::$PRIMARY_LANGUAGE = \Locale::getPrimaryLanguage($locale) ?? $locale;
        LitLocale::$RUNTIME_LOCALE   = $locale;

        $handler = new CalendarHandler([], $rite);

        $params = new CalendarParams();
        $params->setRite($rite);
        $params->setParams(['year' => $year, 'locale' => $locale]);

        $handlerRef = new \ReflectionClass($handler);
        $paramsProp = $handlerRef->getProperty('CalendarParams');
        $paramsProp->setAccessible(true);
        $paramsProp->setValue($handler, $params);

        $loadMethod = $handlerRef->getMethod('loadPropriumDeTemporeData');
        $loadMethod->setAccessible(true);
        $loadMethod->invoke($handler);

        $temporeProp = $handlerRef->getProperty('PropriumDeTempore');
        $temporeProp->setAccessible(true);
        /** @var PropriumDeTemporeMap $tempore */
        $tempore = $temporeProp->getValue($handler);
        return $tempore;
    }

    public function testAmbrosianRiteLoadsAmbrosianTempore(): void
    {
        $tempore = $this->loadTempore(Rite::AMBROSIAN, 'it');

        self::assertTrue(isset($tempore['Circoncisione']), 'Expected the Ambrosian-only key `Circoncisione` to be present.');
        self::assertTrue(isset($tempore['DedicationDuomo']), 'Expected the Ambrosian-only key `DedicationDuomo` to be present.');
        self::assertFalse(isset($tempore['AshWednesday']), 'Did not expect the Roman-only key `AshWednesday` to be present in the Ambrosian tempore.');
    }

    public function testAmbrosianRiteFallsBackToItalianForUnsupportedLocale(): void
    {
        // Ambrosian i18n only ships `it` and `la`; a requested locale outside that set
        // (e.g. `en`) must fall back to Italian rather than erroring out.
        $tempore = $this->loadTempore(Rite::AMBROSIAN, 'en');

        self::assertTrue(isset($tempore['Circoncisione']));
        self::assertSame('Circoncisione del Signore', $tempore['Circoncisione']->name);
    }

    public function testRomanRiteStillLoadsRomanTempore(): void
    {
        $tempore = $this->loadTempore(Rite::ROMAN, 'en');

        self::assertTrue(isset($tempore['AshWednesday']), 'Expected the Roman-only key `AshWednesday` to be present.');
        self::assertFalse(isset($tempore['Circoncisione']), 'Did not expect the Ambrosian-only key `Circoncisione` to be present in the Roman tempore.');
    }
}
