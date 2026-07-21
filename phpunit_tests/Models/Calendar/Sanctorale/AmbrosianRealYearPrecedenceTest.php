<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Models\Calendar\Sanctorale;

use LiturgicalCalendar\Api\Enum\LitLocale;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\LocaleDateFormatter;
use LiturgicalCalendar\Api\Models\Calendar\Precedence\AmbrosianPrecedenceResolver;
use LiturgicalCalendar\Api\Models\Calendar\Precedence\PrecedenceContext;
use LiturgicalCalendar\Api\Params\CalendarParams;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Plan 5 / Task 8 real-data acceptance gate for `AmbrosianPrecedenceResolver`
 * (issue #727): runs the resolver against a full civil year assembled by the
 * Task 7 harness (`AmbrosianRealYearHarnessTrait::assembleAmbrosianYear()`) --
 * the first time the resolver has ever seen real, non-fixture Ambrosian data.
 *
 * This is deliberately test-only glue, same as the Task 7 assembly test: the
 * `/calendar` Ambrosian route stays a 501 (`CalendarHandler` never calls
 * `AmbrosianPrecedenceResolver::resolve()`), so nothing here affects live
 * output. See the Task 8 report for the full cascade trace.
 *
 * Marked @group slow per project convention for full-engine acceptance runs
 * (temporale + sanctorale assembly + precedence resolution for two civil
 * years); excluded from `composer test:quick`.
 */
#[CoversClass(AmbrosianPrecedenceResolver::class)]
#[Group('slow')]
final class AmbrosianRealYearPrecedenceTest extends TestCase
{
    use AmbrosianRealYearHarnessTrait;

    /**
     * Builds a PrecedenceContext wrapping the given real, already-assembled
     * `LiturgicalEventCollection`, mirroring
     * `AmbrosianPrecedenceResolverTest::buildContext()` but reusing an
     * existing collection instead of constructing an empty one.
     *
     * @param array<string> $messages
     */
    private function buildContextFor(\LiturgicalCalendar\Api\Models\Calendar\LiturgicalEventCollection $cal, int $year, array &$messages): PrecedenceContext
    {
        $params = new CalendarParams();
        $params->setParams(['year' => $year]);
        $params->setRite(Rite::AMBROSIAN);

        return new PrecedenceContext(
            $cal,
            $params,
            new LocaleDateFormatter(LitLocale::$RUNTIME_LOCALE),
            $messages
        );
    }

    /**
     * Builds a comparable signature of every event currently in the
     * collection (active AND suppressed), keyed by event_key, valued by its
     * current date -- suppressed events are included (with a `SUPPRESSED:`
     * marker) so that a spurious re-suppression or re-activation between two
     * `resolve()` calls would also break the fixpoint invariant, not just a
     * spurious re-date.
     *
     * @return array<string,string>
     */
    private function signatureOf(\LiturgicalCalendar\Api\Models\Calendar\LiturgicalEventCollection $cal): array
    {
        $signature = [];

        foreach ($cal->getLiturgicalEvents()->getEvents() as $key => $event) {
            $signature[$key] = $event->date->format('Y-m-d');
        }

        foreach ($cal->getSuppressedEvents()->getEvents() as $key => $event) {
            $signature[$key] = 'SUPPRESSED:' . $event->date->format('Y-m-d');
        }

        return $signature;
    }

    /**
     * The acceptance gate (task brief Step 1(i)): running `resolve()` a
     * SECOND time on the same, already-resolved collection performs no
     * further changes. This is the direct proof that the iterative
     * re-resolution pass added in this task actually reaches a fixpoint on
     * real data, rather than merely "running until the pass cap" or leaving
     * residual unresolved coincidences that a hypothetical third pass would
     * still need to clean up.
     *
     * @return void
     */
    public function testResolveReachesAFixpointOn2025(): void
    {
        $cal = $this->assembleAmbrosianYear(2025);

        $messages1 = [];
        $ctx1      = $this->buildContextFor($cal, 2025, $messages1);
        ( new AmbrosianPrecedenceResolver() )->resolve($ctx1);

        // Sanity: the first resolve() actually did something (otherwise a
        // "second call changes nothing" assertion would be vacuous).
        self::assertNotEmpty($messages1, 'Expected the first resolve() pass to produce at least one message');

        // No pass-cap warning: the resolver reached a fixpoint within the cap.
        foreach ($messages1 as $message) {
            self::assertStringNotContainsString(
                're-resolution cap',
                $message,
                'The iterative pass hit its safety cap on real 2025 data -- this signals an anomaly, not a normal outcome'
            );
        }

        $signatureAfterFirstResolve = $this->signatureOf($cal);

        $messages2 = [];
        $ctx2      = $this->buildContextFor($cal, 2025, $messages2);
        ( new AmbrosianPrecedenceResolver() )->resolve($ctx2);

        self::assertSame(
            [],
            $messages2,
            'A second resolve() call on an already-resolved collection must produce zero messages (fixpoint)'
        );
        self::assertSame(
            $signatureAfterFirstResolve,
            $this->signatureOf($cal),
            'A second resolve() call must not change any event date or suppression state (fixpoint)'
        );
    }

    /**
     * Same fixpoint acceptance gate for 2026 (the second civil year the
     * Task 7 harness/temporale ordo-validation both cover), guarding against
     * the invariant only happening to hold for one specific year's date
     * arithmetic.
     */
    public function testResolveReachesAFixpointOn2026(): void
    {
        $cal = $this->assembleAmbrosianYear(2026);

        $messages1 = [];
        $ctx1      = $this->buildContextFor($cal, 2026, $messages1);
        ( new AmbrosianPrecedenceResolver() )->resolve($ctx1);

        self::assertNotEmpty($messages1, 'Expected the first resolve() pass to produce at least one message');

        foreach ($messages1 as $message) {
            self::assertStringNotContainsString('re-resolution cap', $message);
        }

        $signatureAfterFirstResolve = $this->signatureOf($cal);

        $messages2 = [];
        $ctx2      = $this->buildContextFor($cal, 2026, $messages2);
        ( new AmbrosianPrecedenceResolver() )->resolve($ctx2);

        self::assertSame([], $messages2);
        self::assertSame($signatureAfterFirstResolve, $this->signatureOf($cal));
    }

    /**
     * A concrete real coincidence (task brief Step 1(ii)), documented in the
     * Task 8 report: in the 2025 assembled year, `Advent4` (the temporale
     * anchor for Advent IV, a dominical Higher Solemnity) and `StAmbrose`
     * (the comune sanctorale Solemnity for the patron of Milan) both fall on
     * 2025-12-07. `Advent4` outranks `StAmbrose` (rank 3 vs rank 5), so
     * `StAmbrose` is impeded and transferred via the generic n.56 walk.
     *
     * The walk cannot land on 2025-12-08: the comune sanctorale also places
     * `ImmaculateConception` (a Solemnity, rank 5 -- NOT free of ranks 1-10)
     * there, so the walk skips it. It lands on 2025-12-09 instead, where the
     * comune sanctorale independently places `StJuanDiego` (an optional
     * memorial, rank 12 -- free of ranks 1-10, so the walk does not skip
     * it). This creates a FRESH coincidence at the destination that only the
     * Task 8 iterative pass resolves: `StJuanDiego` is suppressed by the
     * now-co-located, higher-ranking `StAmbrose` in the second pass.
     *
     * This single real-year coincidence exercises: the generic n.56 walk
     * skipping an occupied-by-a-solemnity day, landing on an
     * occupied-by-a-memorial day, and the iterative pass cleaning up the
     * resulting collision -- i.e. it is the real-data analogue of the
     * constructed cascade in `AmbrosianPrecedenceResolverTest::testGenericTransferOntoOccupiedDayIsReResolvedByIterativePass()`.
     */
    public function testStAmbroseCascadeThroughOccupiedImmaculateConceptionOntoStJuanDiego(): void
    {
        $cal = $this->assembleAmbrosianYear(2025);

        // Sanity-check the pre-resolution fixture shape this test relies on,
        // so a future change to the comune sanctorale source data that
        // invalidates this scenario fails loudly here rather than the
        // assertions below silently passing vacuously.
        $advent4              = $cal->getLiturgicalEvent('Advent4');
        $stAmbrose            = $cal->getLiturgicalEvent('StAmbrose');
        $immaculateConception = $cal->getLiturgicalEvent('ImmaculateConception');
        $stJuanDiego          = $cal->getLiturgicalEvent('StJuanDiego');

        self::assertNotNull($advent4);
        self::assertNotNull($stAmbrose);
        self::assertNotNull($immaculateConception);
        self::assertNotNull($stJuanDiego);
        self::assertSame('2025-12-07', $advent4->date->format('Y-m-d'));
        self::assertSame('2025-12-07', $stAmbrose->date->format('Y-m-d'), 'Expected StAmbrose to originally coincide with Advent4');
        self::assertSame('2025-12-08', $immaculateConception->date->format('Y-m-d'));
        self::assertSame('2025-12-09', $stJuanDiego->date->format('Y-m-d'));

        $messages = [];
        $ctx      = $this->buildContextFor($cal, 2025, $messages);
        ( new AmbrosianPrecedenceResolver() )->resolve($ctx);

        // Advent4 wins outright and never moves.
        $advent4After = $cal->getLiturgicalEvent('Advent4');
        self::assertNotNull($advent4After);
        self::assertSame('2025-12-07', $advent4After->date->format('Y-m-d'));
        self::assertFalse($cal->isSuppressed('Advent4'));

        // ImmaculateConception is untouched: it was never contested (it wins
        // its own uncontested date and blocks -- but is not itself impeded by
        // -- the n.56 walk).
        $immaculateAfter = $cal->getLiturgicalEvent('ImmaculateConception');
        self::assertNotNull($immaculateAfter);
        self::assertSame('2025-12-08', $immaculateAfter->date->format('Y-m-d'));
        self::assertFalse($cal->isSuppressed('ImmaculateConception'));

        // StAmbrose survives, transferred past the occupied Dec 8 onto Dec 9.
        self::assertFalse($cal->isSuppressed('StAmbrose'));
        $stAmbroseAfter = $cal->getLiturgicalEvent('StAmbrose');
        self::assertNotNull($stAmbroseAfter);
        self::assertSame('2025-12-09', $stAmbroseAfter->date->format('Y-m-d'));

        // StJuanDiego -- the fresh coincidence StAmbrose's transfer created at
        // its destination -- is suppressed, but only by the SECOND pass.
        self::assertTrue($cal->isSuppressed('StJuanDiego'));
        self::assertNull($cal->getLiturgicalEvent('StJuanDiego'));

        // Dec 9 ends up holding exactly one active event: StAmbrose.
        $dec9Occupants = $cal->getCalEventsFromDate(\LiturgicalCalendar\Api\DateTime::fromFormat('9-12-2025'));
        self::assertCount(1, $dec9Occupants);
        self::assertArrayHasKey('StAmbrose', $dec9Occupants);
    }
}
