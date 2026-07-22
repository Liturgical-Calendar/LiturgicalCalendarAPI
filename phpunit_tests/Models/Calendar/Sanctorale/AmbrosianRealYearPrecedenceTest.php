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
     * A concrete real coincidence (task brief Step 1(ii)): in the 2025
     * assembled year, `Advent4` (the temporale anchor for Advent IV, a
     * dominical Higher Solemnity and privileged Advent Sunday) and
     * `StAmbrose` (the comune sanctorale Solemnity for the patron of Milan)
     * both fall on 2025-12-07.
     *
     * Once Plan 6 Task 2 made `AmbrosianTemporale` populate
     * `liturgical_season` on every event, the resolver's season-gated
     * branches stopped being inert and now correctly recognize 2025-12-07 as
     * a privileged Advent Sunday. That triggers the saint-Solemnity-on-a-
     * privileged-Sunday rule (spec §5 / nn.4, 56) instead of the generic
     * n.56 forward walk: `StAmbrose` is displaced to the following Monday,
     * 2025-12-08 -- but that Monday is itself occupied by
     * `ImmaculateConception` (also a Solemnity), so `StAmbrose` is
     * ANTICIPATED to the preceding Saturday, 2025-12-06, instead.
     *
     * (Before `liturgical_season` was populated, the resolver could not see
     * that Dec 7 was a privileged Sunday and fell through to the generic
     * n.56 forward walk, which incorrectly landed `StAmbrose` on 2025-12-09
     * -- suppressing `StJuanDiego` there. That was a bug; this test now
     * documents and locks in the corrected anticipation-to-Saturday
     * behavior.)
     *
     * The Dec-6 landing is confirmed correct by the authoritative Milan
     * diocesan ordo:
     * https://www.chiesadimilano.it/almanacco/letture-rito-ambrosiano/anno-a-2025-2026-ra/ordinazione-di-santambrogio-8-2851534.html
     *
     * The generic n.56 iterative-pass mechanism (a transfer landing on an
     * already-occupied day, re-resolved by a second pass) is still covered
     * on constructed fixture data by
     * `AmbrosianPrecedenceResolverTest::testGenericTransferOntoOccupiedDayIsReResolvedByIterativePass()`;
     * this real-year scenario no longer exercises that generic path, since
     * the season-aware anticipation rule now applies instead.
     */
    public function testStAmbroseOnAdventSundayAnticipatedToSaturdayPastOccupiedImmaculateConception(): void
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
        // -- StAmbrose's displacement).
        $immaculateAfter = $cal->getLiturgicalEvent('ImmaculateConception');
        self::assertNotNull($immaculateAfter);
        self::assertSame('2025-12-08', $immaculateAfter->date->format('Y-m-d'));
        self::assertFalse($cal->isSuppressed('ImmaculateConception'));

        // StAmbrose survives: displaced from privileged Sunday Dec 7 to
        // Monday Dec 8, which is occupied by ImmaculateConception, so it is
        // anticipated to the preceding Saturday, Dec 6.
        self::assertFalse($cal->isSuppressed('StAmbrose'));
        $stAmbroseAfter = $cal->getLiturgicalEvent('StAmbrose');
        self::assertNotNull($stAmbroseAfter);
        self::assertSame('2025-12-06', $stAmbroseAfter->date->format('Y-m-d'));

        // StJuanDiego is untouched: with StAmbrose now anticipated to Dec 6
        // instead of transferred to Dec 9, there is no fresh coincidence at
        // StJuanDiego's date -- it remains active on its original 2025-12-09.
        self::assertFalse($cal->isSuppressed('StJuanDiego'));
        $stJuanDiegoAfter = $cal->getLiturgicalEvent('StJuanDiego');
        self::assertNotNull($stJuanDiegoAfter);
        self::assertSame('2025-12-09', $stJuanDiegoAfter->date->format('Y-m-d'));

        // Dec 6 ends up holding exactly one active event: StAmbrose.
        $dec6Occupants = $cal->getCalEventsFromDate(\LiturgicalCalendar\Api\DateTime::fromFormat('6-12-2025'));
        self::assertCount(1, $dec6Occupants);
        self::assertArrayHasKey('StAmbrose', $dec6Occupants);

        // Dec 9 ends up holding exactly one active event: StJuanDiego.
        $dec9Occupants = $cal->getCalEventsFromDate(\LiturgicalCalendar\Api\DateTime::fromFormat('9-12-2025'));
        self::assertCount(1, $dec9Occupants);
        self::assertArrayHasKey('StJuanDiego', $dec9Occupants);
    }
}
