<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Models\Calendar\Temporale;

use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Enum\LitLocale;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\LocaleDateFormatter;
use LiturgicalCalendar\Api\Models\Calendar\LiturgicalEventCollection;
use LiturgicalCalendar\Api\Models\Calendar\Temporale\AmbrosianTemporale;
use LiturgicalCalendar\Api\Models\Calendar\Temporale\TemporaleContext;
use LiturgicalCalendar\Api\Models\PropriumDeTemporeMap;
use LiturgicalCalendar\Api\Params\CalendarParams;
use LiturgicalCalendar\Api\Router;
use LiturgicalCalendar\Api\Utilities;

/**
 * Shared engine-wiring harness for the Ambrosian temporale test suite
 * (`AmbrosianTemporaleTest` and `AmbrosianTemporaleOrdoValidationTest`).
 *
 * Mirrors how `CalendarHandler` wires `RomanTemporale`, but scoped to the
 * Ambrosian Proprium de Tempore. Extracted from `AmbrosianTemporaleTest`
 * (Task 9) so the ordo-validation acceptance test (Task 10) does not
 * duplicate it.
 */
trait AmbrosianTemporaleHarnessTrait
{
    private static string $originalPrimaryLanguage = '';
    private static string $originalRuntimeLocale   = '';

    public static function setUpBeforeClass(): void
    {
        // JsonData::path() concatenates Router::$apiFilePath; populate it.
        Router::getApiPaths();

        // Capture the global locale statics before buildContext() forces Italian,
        // so tearDownAfterClass() can restore them and the mutation does not leak
        // into other suites sharing the same process.
        self::$originalPrimaryLanguage = LitLocale::$PRIMARY_LANGUAGE;
        self::$originalRuntimeLocale   = LitLocale::$RUNTIME_LOCALE;
    }

    public static function tearDownAfterClass(): void
    {
        // Restore the global locale statics (runs even when a test fails).
        LitLocale::$PRIMARY_LANGUAGE = self::$originalPrimaryLanguage;
        LitLocale::$RUNTIME_LOCALE   = self::$originalRuntimeLocale;
    }

    /**
     * Builds a TemporaleContext wired to the Ambrosian Proprium de Tempore for a
     * given civil year, mirroring how CalendarHandler wires RomanTemporale.
     *
     * @param array<string> $messages
     */
    private function buildContext(int $year, array &$messages): TemporaleContext
    {
        // Force the runtime primary language so LocaleDateFormatter + i18n load deterministically.
        LitLocale::$PRIMARY_LANGUAGE = 'it';
        LitLocale::$RUNTIME_LOCALE   = 'it_IT';

        $dataFile = strtr(
            JsonData::AMBROSIAN_TEMPORALE_FILE->path(),
            []
        );
        $i18nFile = strtr(
            JsonData::AMBROSIAN_TEMPORALE_I18N_FILE->path(),
            ['{locale}' => 'it']
        );

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

    /** @return array<string,string> map of event_key => 'Y-m-d' after buildTemporale */
    private function runEngine(int $year): array
    {
        $messages = [];
        $ctx      = $this->buildContext($year, $messages);
        ( new AmbrosianTemporale() )->buildTemporale($ctx);

        $dates = [];
        foreach ($ctx->cal->getLiturgicalEvents()->getKeys() as $key) {
            $event = $ctx->cal->getLiturgicalEvent($key);
            self::assertNotNull($event, "Expected a LiturgicalEvent for key $key");
            $dates[$key] = $event->date->format('Y-m-d');
        }
        return $dates;
    }
}
