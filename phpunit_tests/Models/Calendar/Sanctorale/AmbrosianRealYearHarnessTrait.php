<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Models\Calendar\Sanctorale;

use LiturgicalCalendar\Api\DateTime;
use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Enum\LitLocale;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\LocaleDateFormatter;
use LiturgicalCalendar\Api\Models\Calendar\LiturgicalEvent;
use LiturgicalCalendar\Api\Models\Calendar\LiturgicalEventCollection;
use LiturgicalCalendar\Api\Models\Calendar\Missal\AmbrosianMissalResolver;
use LiturgicalCalendar\Api\Models\Calendar\Sanctorale\AmbrosianSanctoraleLoader;
use LiturgicalCalendar\Api\Models\Calendar\Temporale\AmbrosianTemporale;
use LiturgicalCalendar\Api\Models\Calendar\Temporale\TemporaleContext;
use LiturgicalCalendar\Api\Models\PropriumDeSanctisEvent;
use LiturgicalCalendar\Api\Models\PropriumDeTemporeMap;
use LiturgicalCalendar\Api\Params\CalendarParams;
use LiturgicalCalendar\Api\Router;
use LiturgicalCalendar\Api\Utilities;

/**
 * Integration harness that assembles a full civil-year Ambrosian
 * `LiturgicalEventCollection` out of two engines that, until now, have only
 * ever been exercised in isolation:
 *
 * - Plan 3's `AmbrosianTemporale::buildTemporale()` (temporal anchor block),
 *   wired the same way `AmbrosianTemporaleHarnessTrait::buildContext()` does.
 * - Task 6's `AmbrosianSanctoraleLoader::load()` (comune ambrosiano), whose
 *   `PropriumDeSanctisEvent`s carry only a `month`/`day` (no year) until this
 *   harness dates them for a specific civil year and adds them to the
 *   collection.
 *
 * This is deliberately test-only glue for Plan 5 / Task 8: `CalendarHandler`
 * does not call this code, and the Ambrosian `/calendar` route stays a 501
 * until a later plan wires the real engine together.
 */
trait AmbrosianRealYearHarnessTrait
{
    private static string $originalPrimaryLanguage = '';
    private static string $originalRuntimeLocale   = '';

    public static function setUpBeforeClass(): void
    {
        // JsonData::path() concatenates Router::$apiFilePath; populate it.
        Router::getApiPaths();

        // Capture the global locale statics before assembleAmbrosianYear() forces
        // Italian, so tearDownAfterClass() can restore them and the mutation does
        // not leak into other suites sharing the same process.
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
     * Assembles a full civil-year Ambrosian `LiturgicalEventCollection`: the
     * temporale anchor block (Plan 3) followed by the comune sanctorale
     * (Task 6), with each fixed-date sanctorale event dated for `$year`
     * before being added.
     *
     * Note on the resulting key count: the Ambrosian comune source lists a
     * handful of dominical solemnities (`Christmas`, `Circoncisione`,
     * `Epiphany`) under the same event_key as their temporale counterparts.
     * Adding the sanctorale version of one of these keys *overwrites* the
     * map entry rather than creating a second one (`LiturgicalEventCollection`
     * is keyed by event_key), so the total distinct key count after assembly
     * is `temporale anchors + sanctorale rows - overlapping keys`, not a
     * plain sum of the two source counts.
     *
     * @param int $year civil year to assemble (e.g. 2025)
     * @return LiturgicalEventCollection populated with temporale anchors and comune sanctorale
     */
    private function assembleAmbrosianYear(int $year): LiturgicalEventCollection
    {
        // Force the runtime primary language so LocaleDateFormatter + i18n load deterministically,
        // mirroring AmbrosianTemporaleHarnessTrait::buildContext().
        LitLocale::$PRIMARY_LANGUAGE = 'it';
        LitLocale::$RUNTIME_LOCALE   = 'it_IT';

        $params = new CalendarParams();
        $params->setParams(['year' => $year]);
        $params->setRite(Rite::AMBROSIAN);

        $cal = new LiturgicalEventCollection($params);

        // 1. Temporale anchors (Plan 3 engine).
        $dataFile = JsonData::AMBROSIAN_TEMPORALE_FILE->path();
        $i18nFile = strtr(
            JsonData::AMBROSIAN_TEMPORALE_I18N_FILE->path(),
            ['{locale}' => 'it']
        );

        $rawEvents = Utilities::jsonFileToObjectArray($dataFile);
        /** @var array<string,string> $names */
        $names = Utilities::jsonFileToArray($i18nFile);

        $temporaleMap = PropriumDeTemporeMap::fromObject($rawEvents);
        $temporaleMap->setNames($names);

        $messages = [];
        $ctx      = new TemporaleContext(
            $cal,
            $params,
            $temporaleMap,
            new LocaleDateFormatter(LitLocale::$RUNTIME_LOCALE),
            $messages
        );
        ( new AmbrosianTemporale() )->buildTemporale($ctx);

        // 2. Comune sanctorale (Task 6 loader), dated for this civil year.
        $editions = ( new AmbrosianMissalResolver() )->resolve($year);
        $missal   = $editions[0];

        $sanctoraleMap = ( new AmbrosianSanctoraleLoader() )->load($missal, 'it');

        foreach ($sanctoraleMap as $key => $sanctisEvent) {
            /** @var PropriumDeSanctisEvent $sanctisEvent */
            if ($sanctisEvent->month === 2 && $sanctisEvent->day === 29 && false === checkdate(2, 29, $year)) {
                // A fixed Feb-29 comune row has no corresponding date in a civil year
                // that is not a leap year. DateTime::createFromFormat() would not
                // reject this (it silently rolls the date over to March 1st), so we
                // guard explicitly rather than let a misdated event slip in. No such
                // row exists in the 2024 edition today; this is defensive for future
                // editions/decrees that might add one.
                continue;
            }

            $sanctisEvent->setDate(DateTime::fromFormat($sanctisEvent->day . '-' . $sanctisEvent->month . '-' . $year));
            $cal->addLiturgicalEvent($key, LiturgicalEvent::fromObject($sanctisEvent));
        }

        return $cal;
    }
}
