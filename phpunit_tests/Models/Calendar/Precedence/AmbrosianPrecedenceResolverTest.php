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

    /**
     * Baseline/regression check (no new logic exercised): a Lenten ferie
     * (rank 7) contested by an ordinary comune memorial (rank 10) already
     * wins the rank comparison in `resolve()`'s sort, so the ferie is the
     * WINNER here, not the loser -- the memorial is suppressed via the plain
     * fallback. Included because the task brief's Step 1 explicitly calls
     * for this assertion alongside the two new-logic scenarios below.
     */
    public function testLentenFerieContestedByOrdinaryMemorialIsNotSuppressed(): void
    {
        $messages = [];
        $ctx      = $this->buildContext(2025, $messages);

        $ferie = $this->makeEvent([
            'key'    => 'LentFerieDay',
            'date'   => '2025-03-11',
            'grade'  => LitGrade::WEEKDAY,
            'season' => LitSeason::LENT,
        ]);

        $memorial = $this->makeEvent([
            'key'    => 'SomeSaintMemorial',
            'date'   => '2025-03-11',
            'grade'  => LitGrade::MEMORIAL,
            'proper' => false,
        ]);

        $ctx->cal->addLiturgicalEvent('LentFerieDay', $ferie);
        $ctx->cal->addLiturgicalEvent('SomeSaintMemorial', $memorial);

        ( new AmbrosianPrecedenceResolver() )->resolve($ctx);

        self::assertFalse($ctx->cal->isSuppressed('LentFerieDay'));
        self::assertNotNull($ctx->cal->getLiturgicalEvent('LentFerieDay'));

        self::assertTrue($ctx->cal->isSuppressed('SomeSaintMemorial'));
        self::assertNull($ctx->cal->getLiturgicalEvent('SomeSaintMemorial'));
    }

    /**
     * The actual new behaviour (norm 4): a Lenten ferie contested by a
     * comune saint Solemnity (rank 5, NOT the Annunciation or St Joseph)
     * naturally loses the rank comparison in `resolve()`'s sort (5 < 7), so
     * the ferie is handed to `resolveLoser()` as the LOSER (and the
     * solemnity is `resolveLoser()`'s `$winner` argument). Per norm 4, a
     * Lenten ferie yields only to the Annunciation/St Joseph -- Task 8
     * (issue #727 item 1) closes what used to be a no-op here: the ferie
     * itself is NOT suppressed and NOT moved, but the impeding solemnity
     * (the `$winner`) is itself impeded by the protected ferie and is
     * transferred away via the generic n.56 "first free day" walk, landing
     * on the very next day since nothing else occupies this minimal
     * fixture.
     */
    public function testLentenFerieIsProtectedByTransferringImpedingSolemnityAway(): void
    {
        $messages = [];
        $ctx      = $this->buildContext(2025, $messages);

        $ferie = $this->makeEvent([
            'key'    => 'LentFerieDay',
            'date'   => '2025-03-11',
            'grade'  => LitGrade::WEEKDAY,
            'season' => LitSeason::LENT,
        ]);

        $solemnity = $this->makeEvent([
            'key'       => 'SomeSaintSolemnity',
            'date'      => '2025-03-11',
            'grade'     => LitGrade::SOLEMNITY,
            'dominical' => false,
            'proper'    => false,
        ]);

        $ctx->cal->addLiturgicalEvent('LentFerieDay', $ferie);
        $ctx->cal->addLiturgicalEvent('SomeSaintSolemnity', $solemnity);

        ( new AmbrosianPrecedenceResolver() )->resolve($ctx);

        // The ferie is NOT suppressed and NOT moved: it retains its precedence
        // and its date (norm 4 protection).
        self::assertFalse($ctx->cal->isSuppressed('LentFerieDay'));
        $ferieAfter = $ctx->cal->getLiturgicalEvent('LentFerieDay');
        self::assertNotNull($ferieAfter);
        self::assertSame('2025-03-11', $ferieAfter->date->format('Y-m-d'));

        // The solemnity is NOT suppressed either: it survives, transferred off
        // the protected Lenten day to the first subsequent free day.
        self::assertFalse($ctx->cal->isSuppressed('SomeSaintSolemnity'));
        $solemnityAfter = $ctx->cal->getLiturgicalEvent('SomeSaintSolemnity');
        self::assertNotNull($solemnityAfter);
        self::assertSame('2025-03-12', $solemnityAfter->date->format('Y-m-d'));

        self::assertCount(1, $messages);
        self::assertStringContainsString('LentFerieDay', $messages[0]);
        self::assertStringContainsString('SomeSaintSolemnity', $messages[0]);
        self::assertStringContainsString('2025-03-12', $messages[0]);
    }

    /**
     * The guard on the Lenten-ferie branch: `protectLentenFerie()` (which
     * TRANSFERS the impeding winner off the protected day) must fire ONLY
     * when the impeding winner is a solemnity. A NON-solemnity winner that
     * outranks a Lenten ferie -- the shape a privileged Sunday of Lent or a
     * fixed rank-2 day like SabatoTradSymb would take -- must NEVER be
     * transferred (those days do not move); the ferie falls through to plain
     * suppression instead, exactly as it did before Task 8.
     *
     * Constructed with a dominical Feast of the Lord (grade `FEAST_LORD`,
     * rank 3) as the winner: it outranks the rank-7 Lenten ferie, but
     * `isSolemnity()` is false for a `FEAST_LORD` grade, so the guard
     * correctly excludes it from `protectLentenFerie()` and the ferie is
     * suppressed rather than the winner transferred.
     */
    public function testLentenFerieAgainstNonSolemnityWinnerDoesNotTransferTheWinner(): void
    {
        $messages = [];
        $ctx      = $this->buildContext(2025, $messages);

        $ferie = $this->makeEvent([
            'key'    => 'LentFerieDay',
            'date'   => '2025-03-11',
            'grade'  => LitGrade::WEEKDAY,
            'season' => LitSeason::LENT,
        ]);

        // A dominical Feast of the Lord (rank 3): outranks the rank-7 ferie,
        // but is NOT a solemnity -- so the `isSolemnity($winner)` guard keeps
        // it out of protectLentenFerie(). It must not move.
        $lordFeast = $this->makeEvent([
            'key'       => 'SomeLordFeast',
            'date'      => '2025-03-11',
            'grade'     => LitGrade::FEAST_LORD,
            'season'    => LitSeason::ORDINARY_TIME,
            'dominical' => true,
        ]);

        $ctx->cal->addLiturgicalEvent('LentFerieDay', $ferie);
        $ctx->cal->addLiturgicalEvent('SomeLordFeast', $lordFeast);

        ( new AmbrosianPrecedenceResolver() )->resolve($ctx);

        // The winner is a non-solemnity: it is NOT transferred, it stays put.
        self::assertFalse($ctx->cal->isSuppressed('SomeLordFeast'));
        $winnerAfter = $ctx->cal->getLiturgicalEvent('SomeLordFeast');
        self::assertNotNull($winnerAfter);
        self::assertSame('2025-03-11', $winnerAfter->date->format('Y-m-d'));

        // The ferie falls through to plain suppression (pre-Task-8 behaviour
        // for this sub-case) -- it is NOT protected here, because protection
        // only applies against an impeding solemnity.
        self::assertTrue($ctx->cal->isSuppressed('LentFerieDay'));
        self::assertNull($ctx->cal->getLiturgicalEvent('LentFerieDay'));

        self::assertCount(1, $messages);
        self::assertStringContainsString('LentFerieDay', $messages[0]);
        self::assertStringContainsString('suppressed', $messages[0]);
    }

    /**
     * The other half of norm 4: a Lenten ferie DOES yield when the winner is
     * specifically the Annunciation (or St Joseph) solemnity.
     */
    public function testLentenFerieYieldsToAnnunciationSolemnity(): void
    {
        $messages = [];
        $ctx      = $this->buildContext(2025, $messages);

        $ferie = $this->makeEvent([
            'key'    => 'LentFerieDay',
            'date'   => '2025-03-11',
            'grade'  => LitGrade::WEEKDAY,
            'season' => LitSeason::LENT,
        ]);

        $annunciation = $this->makeEvent([
            'key'       => 'Annunciation',
            'date'      => '2025-03-11',
            'grade'     => LitGrade::SOLEMNITY,
            'dominical' => false,
            'proper'    => false,
        ]);

        $ctx->cal->addLiturgicalEvent('LentFerieDay', $ferie);
        $ctx->cal->addLiturgicalEvent('Annunciation', $annunciation);

        ( new AmbrosianPrecedenceResolver() )->resolve($ctx);

        // This date (2025-03-11) is outside the settimana autentica/Sabato in
        // traditione symboli window, so the Annunciation itself is untouched...
        $winner = $ctx->cal->getLiturgicalEvent('Annunciation');
        self::assertNotNull($winner);
        self::assertSame('2025-03-11', $winner->date->format('Y-m-d'));

        // ...but the ferie yields (per norm 4) and is suppressed.
        self::assertTrue($ctx->cal->isSuppressed('LentFerieDay'));
        self::assertNull($ctx->cal->getLiturgicalEvent('LentFerieDay'));
    }

    /**
     * Verified anchor dates (see task report for the PHP snippet that
     * confirmed these): Easter 2025 = 2025-04-20; settimana autentica
     * (Holy Week Mon-Thu) = 2025-04-14 .. 2025-04-17; SatOctaveEaster =
     * 2025-04-26; Monday after the octave (Easter+8) = 2025-04-28.
     *
     * The Annunciation, placed on 2025-04-15 (Tuesday of Holy Week,
     * contested by the 'TueHolyWeek' settimana-autentica ferie, rank 2),
     * must transfer to the Monday after the Easter octave, 2025-04-28.
     */
    public function testAnnunciationInSettimanaAutenticaTransfersToMondayAfterOctave(): void
    {
        $messages = [];
        $ctx      = $this->buildContext(2025, $messages);

        $holyWeekTuesday = $this->makeEvent([
            'key'       => 'TueHolyWeek',
            'date'      => '2025-04-15',
            'grade'     => LitGrade::WEEKDAY,
            'season'    => LitSeason::LENT,
            'dominical' => false,
        ]);

        $annunciation = $this->makeEvent([
            'key'       => 'Annunciation',
            'date'      => '2025-04-15',
            'grade'     => LitGrade::SOLEMNITY,
            'dominical' => false,
            'proper'    => false,
        ]);

        $ctx->cal->addLiturgicalEvent('TueHolyWeek', $holyWeekTuesday);
        $ctx->cal->addLiturgicalEvent('Annunciation', $annunciation);

        ( new AmbrosianPrecedenceResolver() )->resolve($ctx);

        // The Holy Week ferie wins and stays put.
        $winner = $ctx->cal->getLiturgicalEvent('TueHolyWeek');
        self::assertNotNull($winner);
        self::assertSame('2025-04-15', $winner->date->format('Y-m-d'));

        // The Annunciation is NOT suppressed: it survives, transferred to the
        // Monday after the Easter octave.
        self::assertFalse($ctx->cal->isSuppressed('Annunciation'));
        $moved = $ctx->cal->getLiturgicalEvent('Annunciation');
        self::assertNotNull($moved);
        self::assertSame('2025-04-28', $moved->date->format('Y-m-d'));

        self::assertCount(1, $messages);
        self::assertStringContainsString('Annunciation', $messages[0]);
        self::assertStringContainsString('2025-04-28', $messages[0]);
    }

    /**
     * Same window, but St Joseph transfers to the TUESDAY after the octave
     * (Easter+9 = 2025-04-29), not the Monday.
     */
    public function testStJosephInSettimanaAutenticaTransfersToTuesdayAfterOctave(): void
    {
        $messages = [];
        $ctx      = $this->buildContext(2025, $messages);

        $holyWeekWednesday = $this->makeEvent([
            'key'       => 'WedHolyWeek',
            'date'      => '2025-04-16',
            'grade'     => LitGrade::WEEKDAY,
            'season'    => LitSeason::LENT,
            'dominical' => false,
        ]);

        $stJoseph = $this->makeEvent([
            'key'       => 'StJoseph',
            'date'      => '2025-04-16',
            'grade'     => LitGrade::SOLEMNITY,
            'dominical' => false,
            'proper'    => false,
        ]);

        $ctx->cal->addLiturgicalEvent('WedHolyWeek', $holyWeekWednesday);
        $ctx->cal->addLiturgicalEvent('StJoseph', $stJoseph);

        ( new AmbrosianPrecedenceResolver() )->resolve($ctx);

        self::assertFalse($ctx->cal->isSuppressed('StJoseph'));
        $moved = $ctx->cal->getLiturgicalEvent('StJoseph');
        self::assertNotNull($moved);
        self::assertSame('2025-04-29', $moved->date->format('Y-m-d'));

        self::assertCount(1, $messages);
        self::assertStringContainsString('StJoseph', $messages[0]);
        self::assertStringContainsString('2025-04-29', $messages[0]);
    }

    /**
     * Generic n.56: a solemnity impeded by a higher-ranked day, with no
     * specific rule above (not a privileged-Sunday case, not the
     * Annunciation/St Joseph, not a Lenten ferie), transfers to the first
     * subsequent day free of ranks 1-10. This constructs a short occupied
     * run (day+1 and day+2 both occupied by rank <= 10 events) so the
     * landing day is day+3.
     */
    public function testGenericSolemnityImpededTransfersToFirstFreeDay(): void
    {
        $messages = [];
        $ctx      = $this->buildContext(2026, $messages);

        // Rank 3: comune dominical solemnity/feast-of-the-Lord, outranks the rank-5 solemnity below.
        $higherRankingDay = $this->makeEvent([
            'key'       => 'SomeLordFeast',
            'date'      => '2026-07-20',
            'grade'     => LitGrade::HIGHER_SOLEMNITY,
            'season'    => LitSeason::ORDINARY_TIME,
            'dominical' => true,
        ]);

        // Rank 5: comune, non-dominical saint solemnity -- the impeded event.
        $impededSolemnity = $this->makeEvent([
            'key'       => 'SomeOtherSaintSolemnity',
            'date'      => '2026-07-20',
            'grade'     => LitGrade::SOLEMNITY,
            'dominical' => false,
            'proper'    => false,
        ]);

        // day+1: occupied by a rank <= 10 event (comune memorial, rank 10).
        $occupant1 = $this->makeEvent([
            'key'    => 'Occupant1',
            'date'   => '2026-07-21',
            'grade'  => LitGrade::MEMORIAL,
            'proper' => false,
        ]);

        // day+2: occupied by a rank <= 10 event (comune feast, rank 8).
        $occupant2 = $this->makeEvent([
            'key'    => 'Occupant2',
            'date'   => '2026-07-22',
            'grade'  => LitGrade::FEAST,
            'proper' => false,
        ]);

        // day+3 (2026-07-23) is left free -- no event added.

        $ctx->cal->addLiturgicalEvent('SomeLordFeast', $higherRankingDay);
        $ctx->cal->addLiturgicalEvent('SomeOtherSaintSolemnity', $impededSolemnity);
        $ctx->cal->addLiturgicalEvent('Occupant1', $occupant1);
        $ctx->cal->addLiturgicalEvent('Occupant2', $occupant2);

        ( new AmbrosianPrecedenceResolver() )->resolve($ctx);

        // The higher-ranking day wins and stays put.
        $winner = $ctx->cal->getLiturgicalEvent('SomeLordFeast');
        self::assertNotNull($winner);
        self::assertSame('2026-07-20', $winner->date->format('Y-m-d'));

        // The impeded solemnity is NOT suppressed: it lands on the first free day.
        self::assertFalse($ctx->cal->isSuppressed('SomeOtherSaintSolemnity'));
        $moved = $ctx->cal->getLiturgicalEvent('SomeOtherSaintSolemnity');
        self::assertNotNull($moved);
        self::assertSame('2026-07-23', $moved->date->format('Y-m-d'));

        // The two occupying events are untouched.
        self::assertNotNull($ctx->cal->getLiturgicalEvent('Occupant1'));
        self::assertNotNull($ctx->cal->getLiturgicalEvent('Occupant2'));

        self::assertCount(1, $messages);
        self::assertStringContainsString('SomeOtherSaintSolemnity', $messages[0]);
        self::assertStringContainsString('2026-07-23', $messages[0]);
    }

    /**
     * The iterative re-resolution pass (Task 8, issue #727 item 3): a
     * transfer can land an event on a date that ALREADY holds another
     * event that was uncontested (a single-event "group") when
     * `resolveOnePass()` took its snapshot for this pass, so it was never
     * revisited within that same pass. Only a second pass -- which
     * rebuilds the date-group snapshot from scratch, reflecting the first
     * pass's moves -- catches the fresh coincidence.
     *
     * Deliberately NOT built with a resident *solemnity* on the transfer's
     * destination day: both existing single-pass checks already avoid
     * landing on a solemnity in real time --
     * `transferSaintSolemnity()`'s own `inSolemnities()` check, and this
     * generic n.56 walk's `isFreeOfOccupiedRanks()` check (a
     * solemnity is rank 5/6, which is NOT free of the occupied ranks, so
     * the walk would already skip past it within a single pass). The genuine
     * gap only appears when the destination's resident is BELOW the
     * comune-memorial threshold (proper memorial-tier or weekday) -- such a
     * resident does not block the walk (rank > `OCCUPIED_RANK_CEILING` is
     * "free"), so the impeded solemnity lands right on top of it, and nobody
     * revisits that now-contested date until the next pass. This is
     * exactly the shape of the real cascade found in the 2025 assembled
     * Ambrosian year (see `AmbrosianRealYearPrecedenceTest`): St Ambrose
     * (Dec 7) impeded by Advent IV walks through the occupied Dec 8
     * (Immaculate Conception, a solemnity -- skipped) onto Dec 9, which
     * already held St Juan Diego (an optional memorial) -- unresolved
     * after a single pass, resolved after the second.
     */
    public function testGenericTransferOntoOccupiedDayIsReResolvedByIterativePass(): void
    {
        $messages = [];
        $ctx      = $this->buildContext(2026, $messages);

        // Rank 3: comune dominical solemnity/feast-of-the-Lord, outranks the rank-5 solemnity below.
        $higherRankingDay = $this->makeEvent([
            'key'       => 'SomeLordFeast',
            'date'      => '2026-07-20',
            'grade'     => LitGrade::HIGHER_SOLEMNITY,
            'season'    => LitSeason::ORDINARY_TIME,
            'dominical' => true,
        ]);

        // Rank 5: comune, non-dominical saint solemnity -- the impeded event.
        $impededSolemnity = $this->makeEvent([
            'key'       => 'ImpededSolemnity',
            'date'      => '2026-07-20',
            'grade'     => LitGrade::SOLEMNITY,
            'dominical' => false,
            'proper'    => false,
        ]);

        // day+1 (2026-07-21): pre-existing, UNCONTESTED (single-event group)
        // comune optional memorial -- rank 13, "free of ranks 1-10", so the
        // n.56 walk does not skip past it and the impeded solemnity lands
        // directly on top of it.
        $mondayResident = $this->makeEvent([
            'key'    => 'MondayResident',
            'date'   => '2026-07-21',
            'grade'  => LitGrade::MEMORIAL_OPT,
            'proper' => false,
        ]);

        $ctx->cal->addLiturgicalEvent('SomeLordFeast', $higherRankingDay);
        $ctx->cal->addLiturgicalEvent('ImpededSolemnity', $impededSolemnity);
        $ctx->cal->addLiturgicalEvent('MondayResident', $mondayResident);

        ( new AmbrosianPrecedenceResolver() )->resolve($ctx);

        // The higher-ranking day wins and stays put.
        $winner = $ctx->cal->getLiturgicalEvent('SomeLordFeast');
        self::assertNotNull($winner);
        self::assertSame('2026-07-20', $winner->date->format('Y-m-d'));

        // The impeded solemnity is NOT suppressed: it lands on 2026-07-21 (the
        // first day free of ranks 1-10 -- the resident memorial there does not
        // block the walk).
        self::assertFalse($ctx->cal->isSuppressed('ImpededSolemnity'));
        $moved = $ctx->cal->getLiturgicalEvent('ImpededSolemnity');
        self::assertNotNull($moved);
        self::assertSame('2026-07-21', $moved->date->format('Y-m-d'));

        // The second pass re-resolves the fresh coincidence this transfer
        // created: the lower-ranking resident memorial is suppressed by the
        // now-co-located, higher-ranking solemnity.
        self::assertTrue($ctx->cal->isSuppressed('MondayResident'));
        self::assertNull($ctx->cal->getLiturgicalEvent('MondayResident'));

        // Two distinct outcomes -> two messages: the n.56 transfer (pass 1)
        // and the pass-2 suppression of the displaced resident.
        self::assertCount(2, $messages);
        self::assertStringContainsString('ImpededSolemnity', $messages[0]);
        self::assertStringContainsString('2026-07-21', $messages[0]);
        self::assertStringContainsString('MondayResident', $messages[1]);
        self::assertStringContainsString('ImpededSolemnity', $messages[1]);
    }
}
