<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Models\Calendar\Precedence;

use LiturgicalCalendar\Api\Enum\LitGrade;
use LiturgicalCalendar\Api\Enum\LitSeason;
use LiturgicalCalendar\Api\Enum\LitLocale;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\LocaleDateFormatter;
use LiturgicalCalendar\Api\Models\Calendar\LiturgicalEventCollection;
use LiturgicalCalendar\Api\Models\Calendar\Precedence\AmbrosianPrecedenceResolver;
use LiturgicalCalendar\Api\Models\Calendar\Precedence\PrecedenceContext;
use LiturgicalCalendar\Api\Params\CalendarParams;
use LiturgicalCalendar\Api\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Coincidence + suppression core of `AmbrosianPrecedenceResolver`: on a
 * contested date (more than one event), the event with the lowest
 * `AmbrosianLiturgicalDayRank::rankOf()` value wins and every other event on
 * that date is suppressed (removed from the active collection, recorded on
 * the suppressed-events ledger, and explained via a message). Task 5 only
 * implements the suppression fallback -- the transfer rules that the real
 * Ambrosian Tabella prescribes ahead of suppression are Tasks 6-7.
 */
#[CoversClass(AmbrosianPrecedenceResolver::class)]
final class AmbrosianPrecedenceResolverTest extends TestCase
{
    use AmbrosianEventFactoryTrait;

    private static string $originalPrimaryLanguage = '';
    private static string $originalRuntimeLocale   = '';

    public static function setUpBeforeClass(): void
    {
        // JsonData::path() (used transitively by LiturgicalEventCollection's
        // lectionary bootstrap) concatenates Router::$apiFilePath; populate it.
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
     * Builds a PrecedenceContext wrapping a real LiturgicalEventCollection,
     * mirroring how CalendarHandler wires a PrecedenceContext and how
     * AmbrosianTemporaleHarnessTrait wires a TemporaleContext.
     *
     * @param array<string> $messages
     */
    private function buildContext(int $year, array &$messages): PrecedenceContext
    {
        // Force the runtime primary language so LocaleDateFormatter + i18n load deterministically.
        LitLocale::$PRIMARY_LANGUAGE = 'it';
        LitLocale::$RUNTIME_LOCALE   = 'it_IT';

        $params = new CalendarParams();
        $params->setParams(['year' => $year]);
        $params->setRite(Rite::AMBROSIAN);

        $cal = new LiturgicalEventCollection($params);

        return new PrecedenceContext(
            $cal,
            $params,
            new LocaleDateFormatter(LitLocale::$RUNTIME_LOCALE),
            $messages
        );
    }

    public function testHigherRankSolemnitySuppressesLowerRankMemorialOnSameDate(): void
    {
        $messages = [];
        $ctx      = $this->buildContext(2026, $messages);

        // Rank 5: comune, non-dominical saint solemnity.
        $solemnity = $this->makeEvent([
            'key'       => 'SomeSaintSolemnity',
            'date'      => '2026-07-20',
            'grade'     => LitGrade::HIGHER_SOLEMNITY,
            'season'    => LitSeason::ORDINARY_TIME,
            'dominical' => false,
            'proper'    => false,
        ]);

        // Rank 10: comune obligatory memorial, same date.
        $memorial = $this->makeEvent([
            'key'    => 'SomeSaintMemorial',
            'date'   => '2026-07-20',
            'grade'  => LitGrade::MEMORIAL,
            'proper' => false,
        ]);

        $ctx->cal->addLiturgicalEvent('SomeSaintSolemnity', $solemnity);
        $ctx->cal->addLiturgicalEvent('SomeSaintMemorial', $memorial);

        // Sanity check before resolving: both events are present and uncontested.
        self::assertNotNull($ctx->cal->getLiturgicalEvent('SomeSaintSolemnity'));
        self::assertNotNull($ctx->cal->getLiturgicalEvent('SomeSaintMemorial'));
        self::assertFalse($ctx->cal->isSuppressed('SomeSaintMemorial'));

        ( new AmbrosianPrecedenceResolver() )->resolve($ctx);

        // The higher-ranking solemnity remains, on the same date.
        $winner = $ctx->cal->getLiturgicalEvent('SomeSaintSolemnity');
        self::assertNotNull($winner);
        self::assertSame('2026-07-20', $winner->date->format('Y-m-d'));

        // The lower-ranking memorial is no longer retrievable from the active collection...
        self::assertNull($ctx->cal->getLiturgicalEvent('SomeSaintMemorial'));

        // ...but is recorded on the suppressed-events ledger...
        self::assertTrue($ctx->cal->isSuppressed('SomeSaintMemorial'));
        $suppressed = $ctx->cal->getSuppressedEventByKey('SomeSaintMemorial');
        self::assertNotNull($suppressed);
        self::assertSame('SomeSaintMemorial', $suppressed->event_key);

        // ...and a message explains the suppression.
        self::assertCount(1, $messages);
        self::assertStringContainsString('SomeSaintMemorial', $messages[0]);
        self::assertStringContainsString('SomeSaintSolemnity', $messages[0]);
    }

    public function testUncontestedDateIsLeftUntouched(): void
    {
        $messages = [];
        $ctx      = $this->buildContext(2026, $messages);

        $memorial = $this->makeEvent([
            'key'    => 'LoneMemorial',
            'date'   => '2026-07-21',
            'grade'  => LitGrade::MEMORIAL,
            'proper' => false,
        ]);

        $ctx->cal->addLiturgicalEvent('LoneMemorial', $memorial);

        ( new AmbrosianPrecedenceResolver() )->resolve($ctx);

        self::assertNotNull($ctx->cal->getLiturgicalEvent('LoneMemorial'));
        self::assertFalse($ctx->cal->isSuppressed('LoneMemorial'));
        self::assertCount(0, $messages);
    }

    /**
     * Advent I 2025 falls on 2025-11-16 (verified: a Sunday, ISO-8601 weekday
     * 7). A saint's Solemnity impeded by that privileged Sunday must be
     * transferred to the following Monday, 2025-11-17.
     */
    public function testSaintSolemnityImpededByAdventSundayTransfersToMonday(): void
    {
        $messages = [];
        $ctx      = $this->buildContext(2025, $messages);

        $adventSunday = $this->makeEvent([
            'key'       => 'Advent1',
            'date'      => '2025-11-16',
            'grade'     => LitGrade::HIGHER_SOLEMNITY,
            'season'    => LitSeason::ADVENT,
            'dominical' => true,
        ]);

        $saintSolemnity = $this->makeEvent([
            'key'       => 'SomeSaintSolemnity',
            'date'      => '2025-11-16',
            'grade'     => LitGrade::HIGHER_SOLEMNITY,
            'dominical' => false,
            'proper'    => false,
        ]);

        $ctx->cal->addLiturgicalEvent('Advent1', $adventSunday);
        $ctx->cal->addLiturgicalEvent('SomeSaintSolemnity', $saintSolemnity);

        ( new AmbrosianPrecedenceResolver() )->resolve($ctx);

        // The Advent Sunday wins and stays put.
        $winner = $ctx->cal->getLiturgicalEvent('Advent1');
        self::assertNotNull($winner);
        self::assertSame('2025-11-16', $winner->date->format('Y-m-d'));

        // The saint's Solemnity is NOT suppressed: it survives, moved to the following Monday.
        self::assertFalse($ctx->cal->isSuppressed('SomeSaintSolemnity'));
        $moved = $ctx->cal->getLiturgicalEvent('SomeSaintSolemnity');
        self::assertNotNull($moved);
        self::assertSame('2025-11-17', $moved->date->format('Y-m-d'));

        self::assertCount(1, $messages);
        self::assertStringContainsString('SomeSaintSolemnity', $messages[0]);
        self::assertStringContainsString('2025-11-17', $messages[0]);
    }

    /**
     * Same coincidence as above, but the following Monday (2025-11-17) is
     * already occupied by another solemnity. The Tabella then anticipates the
     * superseded saint's Solemnity to the Saturday immediately preceding the
     * Sunday, 2025-11-15, instead of transferring it to the (occupied) Monday.
     */
    public function testSaintSolemnityAnticipatedToSaturdayWhenFollowingMondayIsOccupied(): void
    {
        $messages = [];
        $ctx      = $this->buildContext(2025, $messages);

        $adventSunday = $this->makeEvent([
            'key'       => 'Advent1',
            'date'      => '2025-11-16',
            'grade'     => LitGrade::HIGHER_SOLEMNITY,
            'season'    => LitSeason::ADVENT,
            'dominical' => true,
        ]);

        $saintSolemnity = $this->makeEvent([
            'key'       => 'SomeSaintSolemnity',
            'date'      => '2025-11-16',
            'grade'     => LitGrade::HIGHER_SOLEMNITY,
            'dominical' => false,
            'proper'    => false,
        ]);

        // Pre-occupies the following Monday with another (uncontested) solemnity.
        $mondaySolemnity = $this->makeEvent([
            'key'       => 'MondaySolemnity',
            'date'      => '2025-11-17',
            'grade'     => LitGrade::SOLEMNITY,
            'dominical' => false,
            'proper'    => false,
        ]);

        $ctx->cal->addLiturgicalEvent('Advent1', $adventSunday);
        $ctx->cal->addLiturgicalEvent('SomeSaintSolemnity', $saintSolemnity);
        $ctx->cal->addLiturgicalEvent('MondaySolemnity', $mondaySolemnity);

        ( new AmbrosianPrecedenceResolver() )->resolve($ctx);

        // The saint's Solemnity is NOT suppressed: it survives, anticipated to the preceding Saturday.
        self::assertFalse($ctx->cal->isSuppressed('SomeSaintSolemnity'));
        $moved = $ctx->cal->getLiturgicalEvent('SomeSaintSolemnity');
        self::assertNotNull($moved);
        self::assertSame('2025-11-15', $moved->date->format('Y-m-d'));

        // The pre-existing Monday solemnity is untouched.
        $mondayStillThere = $ctx->cal->getLiturgicalEvent('MondaySolemnity');
        self::assertNotNull($mondayStillThere);
        self::assertSame('2025-11-17', $mondayStillThere->date->format('Y-m-d'));

        self::assertCount(1, $messages);
        self::assertStringContainsString('SomeSaintSolemnity', $messages[0]);
        self::assertStringContainsString('2025-11-15', $messages[0]);
    }

    /**
     * Lent I 2025 falls on 2025-03-09 (verified: a Sunday). A Solemnity of the
     * Lord impeded by that privileged Sunday must be transferred to the
     * following Monday, 2025-03-10 (never anticipated to a Saturday -- that
     * fallback only applies to Solemnities of a saint).
     */
    public function testLordSolemnityImpededByLentSundayTransfersToMonday(): void
    {
        $messages = [];
        $ctx      = $this->buildContext(2025, $messages);

        $lentSunday = $this->makeEvent([
            'key'       => 'Lent1',
            'date'      => '2025-03-09',
            'grade'     => LitGrade::HIGHER_SOLEMNITY,
            'season'    => LitSeason::LENT,
            'dominical' => true,
        ]);

        $lordSolemnity = $this->makeEvent([
            'key'       => 'SomeLordSolemnity',
            'date'      => '2025-03-09',
            'grade'     => LitGrade::SOLEMNITY,
            'dominical' => true,
        ]);

        $ctx->cal->addLiturgicalEvent('Lent1', $lentSunday);
        $ctx->cal->addLiturgicalEvent('SomeLordSolemnity', $lordSolemnity);

        ( new AmbrosianPrecedenceResolver() )->resolve($ctx);

        self::assertFalse($ctx->cal->isSuppressed('SomeLordSolemnity'));
        $moved = $ctx->cal->getLiturgicalEvent('SomeLordSolemnity');
        self::assertNotNull($moved);
        self::assertSame('2025-03-10', $moved->date->format('Y-m-d'));

        self::assertCount(1, $messages);
        self::assertStringContainsString('SomeLordSolemnity', $messages[0]);
        self::assertStringContainsString('2025-03-10', $messages[0]);
    }

    /**
     * Same Lent Sunday, but the impeded event is a Feast (not Solemnity) of
     * the Lord: per the Tabella it is omitted outright for the year, never
     * transferred.
     */
    public function testLordFeastImpededByLentSundayIsOmittedNotMoved(): void
    {
        $messages = [];
        $ctx      = $this->buildContext(2025, $messages);

        $lentSunday = $this->makeEvent([
            'key'       => 'Lent1',
            'date'      => '2025-03-09',
            'grade'     => LitGrade::HIGHER_SOLEMNITY,
            'season'    => LitSeason::LENT,
            'dominical' => true,
        ]);

        $lordFeast = $this->makeEvent([
            'key'       => 'SomeLordFeast',
            'date'      => '2025-03-09',
            'grade'     => LitGrade::FEAST_LORD,
            'dominical' => true,
        ]);

        $ctx->cal->addLiturgicalEvent('Lent1', $lentSunday);
        $ctx->cal->addLiturgicalEvent('SomeLordFeast', $lordFeast);

        ( new AmbrosianPrecedenceResolver() )->resolve($ctx);

        // Omitted: no longer retrievable from the active collection...
        self::assertNull($ctx->cal->getLiturgicalEvent('SomeLordFeast'));

        // ...but recorded on the suppressed-events ledger (it did not silently vanish)...
        self::assertTrue($ctx->cal->isSuppressed('SomeLordFeast'));
        $suppressed = $ctx->cal->getSuppressedEventByKey('SomeLordFeast');
        self::assertNotNull($suppressed);

        // ...and the date on the suppressed record is unchanged: it was never moved.
        self::assertSame('2025-03-09', $suppressed->date->format('Y-m-d'));

        self::assertCount(1, $messages);
        self::assertStringContainsString('SomeLordFeast', $messages[0]);
        self::assertStringContainsString('omitted', $messages[0]);
    }
}
