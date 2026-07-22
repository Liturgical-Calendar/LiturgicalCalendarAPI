<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Enum\LitLocale;
use LiturgicalCalendar\Api\Enum\LitSeason;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Handlers\CalendarHandler;
use LiturgicalCalendar\Api\LocaleDateFormatter;
use LiturgicalCalendar\Api\Models\Calendar\LiturgicalEventCollection;
use LiturgicalCalendar\Api\Models\Calendar\Temporale\AmbrosianTemporale;
use LiturgicalCalendar\Api\Models\Calendar\Temporale\TemporaleContext;
use LiturgicalCalendar\Api\Models\PropriumDeTemporeMap;
use LiturgicalCalendar\Api\Params\CalendarParams;
use LiturgicalCalendar\Api\Utilities;

/**
 * Plan 7 Task 6, Part A: `LiturgicalEventCollection::stampAmbrosianSeasonOnSanctorale()`.
 *
 * The Ambrosian temporale engine (`AmbrosianTemporale::buildTemporale()`, Plan 7 Tasks 3/5)
 * self-stamps `liturgical_season` on every temporale event it produces (via
 * `LitSeason::forEventKey()`). The Ambrosian comune sanctorale
 * (`CalendarHandler::addAmbrosianSanctoraleToCalendar()`, Plan 7 Task 4) carries no season
 * information at all: sanctorale source rows have no `liturgical_season` field, so every
 * sanctorale event lands in the collection with `liturgical_season === null`.
 *
 * `stampAmbrosianSeasonOnSanctorale()` closes that gap by copying the season from a co-located
 * temporale event on the same date (`getCalEventsFromDate()`), falling back to
 * `LitSeason::CHRISTMAS` for the one known gap date (Dec 26-31, where the deferred Ambrosian
 * n.32 Christmas-octave-Sunday rule isn't implemented yet and so no seasoned temporale event
 * exists on that date to copy from).
 *
 * This test builds the full 2025 Ambrosian temporale + comune sanctorale assembly exactly as
 * `CalendarHandlerAmbrosianSanctoraleLoadTest` does (temporale engine, then
 * `addAmbrosianSanctoraleToCalendar()` via reflection) rather than through `handle()`, at the
 * granularity of this single pass — the full pipeline is assembled by `calculateAmbrosianCalendar()`
 * (Task 9) and wired into `handle()` by Task 10.
 */
final class CalendarHandlerAmbrosianSeasonOnSanctoraleTest extends AbstractHandlerTestCase
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
     * Assembles the full 2025 Ambrosian temporale + comune sanctorale, mirroring
     * `CalendarHandlerAmbrosianSanctoraleLoadTest::runSanctoraleStep()`.
     */
    private function assembleAmbrosianYear(int $year = 2025): LiturgicalEventCollection
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

        return $cal;
    }

    /**
     * `StJoseph` (19 March in the Ambrosian comune sanctorale) has no `liturgical_season` when
     * first assembled (sanctorale rows carry none), and falls within Ambrosian Lent for 2025
     * (Ambrosian `Lent1` 2025-03-09 .. `Easter` 2025-04-20): the temporale engine already produces
     * a `LentWeekday*` ferial event on that same date (2025-03-19), which is where the season
     * should be copied from.
     */
    public function testComuneSaintInLentHasNullSeasonBeforeStampingAndLentAfter(): void
    {
        $cal = $this->assembleAmbrosianYear(2025);

        $stJoseph = $cal->getLiturgicalEvent('StJoseph');
        self::assertNotNull($stJoseph, 'Expected `StJoseph` to be present after the sanctorale step.');
        self::assertSame('2025-03-19', $stJoseph->date->format('Y-m-d'));
        self::assertNull($stJoseph->liturgical_season, 'Expected the comune sanctorale event to carry no season before stamping.');

        $cal->stampAmbrosianSeasonOnSanctorale();

        self::assertSame(LitSeason::LENT, $stJoseph->liturgical_season);
    }

    /**
     * Every event that already carries a `liturgical_season` (i.e. every temporale event) must be
     * left untouched by the stamping pass.
     */
    public function testTemporaleEventsWithExistingSeasonAreNotOverwritten(): void
    {
        $cal = $this->assembleAmbrosianYear(2025);

        $easter = $cal->getLiturgicalEvent('Easter');
        self::assertNotNull($easter);
        self::assertSame(LitSeason::EASTER, $easter->liturgical_season);

        $cal->stampAmbrosianSeasonOnSanctorale();

        self::assertSame(LitSeason::EASTER, $easter->liturgical_season);
    }

    /**
     * `HolyInnocents` (28 December) is the one known date, for civil year 2025, with NO seasoned
     * temporale event at all: 2025-12-28 is a Sunday, and the Ambrosian n.32
     * Christmas-octave-Sunday rule (which would produce a proper Sunday-within-the-octave
     * temporale event on that date) is deferred/not yet implemented, so the temporale engine
     * leaves that date without a seasoned sibling. This exercises the `LitSeason::CHRISTMAS`
     * fallback branch, which is correct regardless: Dec 26-31 is always within the Christmas
     * octave.
     */
    public function testChristmasOctaveSundayGapFallsBackToChristmasSeason(): void
    {
        $cal = $this->assembleAmbrosianYear(2025);

        // Sanity: confirm the known gap date has no seasoned sibling before asserting the fallback.
        $holyInnocentsEvent = $cal->getLiturgicalEvent('HolyInnocents');
        self::assertNotNull($holyInnocentsEvent);
        self::assertSame('2025-12-28', $holyInnocentsEvent->date->format('Y-m-d'));
        self::assertSame(7, (int) $holyInnocentsEvent->date->format('N'), 'Expected 2025-12-28 to be a Sunday for this fixture year.');

        foreach ($cal->getCalEventsFromDate($holyInnocentsEvent->date) as $coincidingEvent) {
            self::assertNull($coincidingEvent->liturgical_season, 'Expected no seasoned sibling event to already exist on the gap date.');
        }

        $cal->stampAmbrosianSeasonOnSanctorale();

        self::assertSame(LitSeason::CHRISTMAS, $holyInnocentsEvent->liturgical_season);
    }
}
