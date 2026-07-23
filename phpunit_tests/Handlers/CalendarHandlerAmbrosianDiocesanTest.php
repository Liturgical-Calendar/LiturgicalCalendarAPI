<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Handlers\CalendarHandler;
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
}
