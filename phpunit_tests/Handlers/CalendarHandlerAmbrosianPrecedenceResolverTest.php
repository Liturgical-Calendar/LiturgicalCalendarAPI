<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Enum\LitLocale;
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
 * Plan 7 Task 5: `CalendarHandler::resolveAmbrosianPrecedence()`.
 *
 * This is the end-to-end proof that the Ambrosian generation path — temporale (Task 3),
 * comune sanctorale (Task 4), season-stamping (Task 6) — feeds real handler-assembled data
 * into `AmbrosianPrecedenceResolver` (Plan 4) via a `PrecedenceContext`, and that the resolver
 * fires correctly on it.
 *
 * For the 2025 Ambrosian year, `StAmbrose` (7 December, a saint Solemnity in the Ambrosian
 * comune sanctorale) and `Advent4` (the fourth Sunday of Ambrosian Advent) both land on
 * 2025-12-07 before resolution. Per the Tabella dei giorni liturgici, a privileged
 * (Advent/Lent/Easter) Sunday outranks a saint Solemnity, so `StAmbrose` must transfer: the
 * following Monday is 2025-12-08 (Immaculate Conception, itself a Solemnity, so occupied),
 * which per the saint-Solemnity transfer rule means `StAmbrose` is instead anticipated to the
 * preceding Saturday, 2025-12-06. This is the norm-correct result validated in Plan 6 and
 * confirmed against the chiesadimilano.it ordo (see [[ambrosian-ordo-validation-source]]).
 *
 * The season-stamping pass (Task 6) MUST run before `resolve()`: the resolver's season-gated
 * transfer rules read `liturgical_season`, which is `null` on every comune sanctorale event
 * (including `StAmbrose`) until stamped.
 */
final class CalendarHandlerAmbrosianPrecedenceResolverTest extends AbstractHandlerTestCase
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
     * Assembles the full 2025 Ambrosian temporale + comune sanctorale + season-stamping,
     * returning the `CalendarHandler` instance itself (rather than just the collection) so the
     * caller can also invoke `resolveAmbrosianPrecedence()` via reflection on the same instance
     * (it reads `$this->Cal`, `$this->CalendarParams`, `$this->localeDateFormatter` internally).
     *
     * Mirrors `CalendarHandlerAmbrosianSeasonOnSanctoraleTest::assembleAmbrosianYear()`, adding
     * the `stampAmbrosianSeasonOnSanctorale()` call that Task 6 introduced.
     */
    private function assembleAmbrosianYear(int $year = 2025): CalendarHandler
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

        return $handler;
    }

    public function testStAmbroseAnticipatesToDec6AfterResolvingAgainstAdvent4(): void
    {
        $handler    = $this->assembleAmbrosianYear(2025);
        $handlerRef = new \ReflectionClass($handler);

        $calProp = $handlerRef->getProperty('Cal');
        $calProp->setAccessible(true);
        /** @var LiturgicalEventCollection $cal */
        $cal = $calProp->getValue($handler);

        // BEFORE resolve(): StAmbrose and Advent4 both sit on 2025-12-07.
        $stAmbroseBefore = $cal->getLiturgicalEvent('StAmbrose');
        $advent4Before   = $cal->getLiturgicalEvent('Advent4');
        self::assertNotNull($stAmbroseBefore, 'Expected `StAmbrose` to be present after the sanctorale step.');
        self::assertNotNull($advent4Before, 'Expected `Advent4` to be present after the temporale step.');
        self::assertSame('2025-12-07', $stAmbroseBefore->date->format('Y-m-d'));
        self::assertSame('2025-12-07', $advent4Before->date->format('Y-m-d'));

        $method = $handlerRef->getMethod('resolveAmbrosianPrecedence');
        $method->setAccessible(true);
        $method->invoke($handler);

        // AFTER resolve(): StAmbrose has anticipated to 2025-12-06, and is not suppressed.
        $stAmbroseAfter = $cal->getLiturgicalEvent('StAmbrose');
        self::assertNotNull($stAmbroseAfter, 'Expected `StAmbrose` to still be present (transferred, not suppressed) after resolve().');
        self::assertFalse($cal->isSuppressed('StAmbrose'), 'Expected `StAmbrose` NOT to be suppressed.');
        self::assertSame('2025-12-06', $stAmbroseAfter->date->format('Y-m-d'));

        // Advent4 itself is untouched (it is the winner of the coincidence).
        $advent4After = $cal->getLiturgicalEvent('Advent4');
        self::assertNotNull($advent4After);
        self::assertSame('2025-12-07', $advent4After->date->format('Y-m-d'));

        // StJuanDiego stays active on 2025-12-09, unaffected by the StAmbrose/Advent4 coincidence.
        $stJuanDiego = $cal->getLiturgicalEvent('StJuanDiego');
        self::assertNotNull($stJuanDiego, 'Expected `StJuanDiego` to be present.');
        self::assertFalse($cal->isSuppressed('StJuanDiego'));
        self::assertSame('2025-12-09', $stJuanDiego->date->format('Y-m-d'));
    }
}
