<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests;

use LiturgicalCalendar\Api\Enum\FrameFamily;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Enum\Status;
use LiturgicalCalendar\Api\Enum\Step;
use LiturgicalCalendar\Api\Health;
use LiturgicalCalendar\Api\Router;
use LiturgicalCalendar\Api\Test\LitTestRunner;
use LiturgicalCalendar\Tests\Support\HealthQueueIsolationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ratchet\ConnectionInterface;

/**
 * `Health::sendStepResult()` — the legacy frame fields as a projection of the structured ones.
 *
 * A frame used to say what it was about only through `classes`, a CSS selector the server built and
 * the browser matched with `querySelectorAll()`: attribution by string matching, with the server
 * knowing Bootstrap existed. Every frame now carries `target`, `step` and `status`, and `type` and
 * `classes` are *derived* from them rather than written out at each call site.
 *
 * **Every expectation here is a literal.** Deriving them from `FrameFamily::CLASS_FOR_STEP` — the
 * very table under test — is how #806 section B produced four tests that could not fail: both sides
 * of the assertion read one production value, so any change to it changed the expectation with it.
 * The literals below are the only thing pinning the projection, which is why the other suites are
 * free to *use* the table to derive their expectations.
 */
#[CoversClass(Health::class)]
final class HealthFrameProjectionTest extends TestCase
{
    use HealthQueueIsolationTrait;

    /**
     * A minimal Ratchet connection that records every outbound frame. `resourceId` is a dynamic
     * public property Ratchet assigns and is not part of `ConnectionInterface`, so this mirrors the
     * stub convention already used by HealthValidateSourceTest and HealthFolderStepResultTest
     * rather than a PHPUnit mock, which would trigger a dynamic-property deprecation.
     */
    private static function stubConnection(int $resourceId = 1)
    {
        return new class ($resourceId) implements ConnectionInterface {
            /** @var list<string> */
            public array $sent = [];

            public function __construct(public int $resourceId)
            {
            }

            public function send($data)
            {
                $this->sent[] = (string) $data;

                return $this;
            }

            public function close()
            {
            }
        };
    }

    /**
     * @param list<mixed> $args
     */
    private function invoke(Health $health, string $method, array $args): void
    {
        ( new \ReflectionMethod(Health::class, $method) )->invokeArgs($health, $args);
    }

    /**
     * @return array<string, array{string, Step, Status, string, string}>
     */
    public static function projectionProvider(): array
    {
        return [
            'exists passes'    => ['temporale-roman', Step::EXISTS,    Status::PASS, 'success', '.temporale-roman.file-exists'],
            'parses fails'     => ['temporale-roman', Step::PARSES,    Status::FAIL, 'error',   '.temporale-roman.json-valid'],
            'validates passes' => ['nation-roman-IT', Step::VALIDATES, Status::PASS, 'success', '.nation-roman-IT.schema-valid'],
        ];
    }

    #[DataProvider('projectionProvider')]
    public function testTheLegacyFieldsAreProjectedFromTheStructuredOnes(
        string $fragment,
        Step $step,
        Status $status,
        string $expectedType,
        string $expectedClasses
    ): void {
        $conn   = self::stubConnection();
        $health = $this->newHealth();
        $this->invoke($health, 'sendStepResult', [$conn, $fragment, (object) ['id' => 'temporale:roman'], $step, $status, 'text', null, null]);

        self::assertCount(1, $conn->sent);
        $frame = json_decode($conn->sent[0]);
        self::assertSame($expectedType, $frame->type, 'type is projected from status');
        self::assertSame($expectedClasses, $frame->classes, 'classes is projected from fragment and step');
        self::assertSame($step->value, $frame->step, 'the published step name reaches the wire');
        self::assertSame($status->value, $frame->status);
    }

    /**
     * The legacy fields keep their positions. `classes` is what today's clients match on and `type`
     * is what they paint with, so the structured fields are added *after* them and nothing is
     * reordered: a frame's first three properties are exactly the three it always had.
     */
    public function testTheLegacyFieldsComeFirstAndInTheirOriginalOrder(): void
    {
        $conn = self::stubConnection();
        $this->invoke(
            $this->newHealth(),
            'sendStepResult',
            [$conn, 'temporale-roman', (object) ['id' => 'temporale:roman'], Step::EXISTS, Status::PASS, 'The Data file exists', null, 'run-a']
        );

        $frame = json_decode($conn->sent[0], true);
        self::assertIsArray($frame);
        self::assertSame(
            ['type', 'text', 'classes'],
            array_slice(array_keys($frame), 0, 3),
            'the legacy trio must stay first, and in the order every shipped frame has used'
        );
        self::assertSame('The Data file exists', $frame['text'], 'the text is passed through untouched');
        self::assertSame('run-a', $frame['runToken'], 'the run token a client correlates by still reaches the frame');
    }

    /**
     * The target names what was checked, as an **object** carrying the id `GET /validations`
     * published — never a bare string, and never something derived from the selector. It is an object
     * so that clusters whose subject needs more than an id can say so without a breaking change:
     * a calendar validation adds `year`, a test run adds `calendar` and `year`.
     *
     * A v1 `executeValidation` message names no id, so its frames carry a null target rather than a
     * fabricated one.
     */
    public function testTheTargetIdReachesTheWireAndIsNullWhenThereIsNone(): void
    {
        $conn = self::stubConnection();
        $this->invoke(
            $this->newHealth(),
            'sendStepResult',
            [$conn, 'nation-roman-IT', (object) ['id' => 'nation:roman:IT'], Step::VALIDATES, Status::PASS, 'text', null, null]
        );
        $this->invoke(
            $this->newHealth(),
            'sendStepResult',
            [$conn, 'proprium-de-tempore', null, Step::VALIDATES, Status::PASS, 'text', null, null]
        );

        self::assertCount(2, $conn->sent);
        self::assertEquals((object) ['id' => 'nation:roman:IT'], json_decode($conn->sent[0])->target);
        self::assertNull(json_decode($conn->sent[1])->target);
    }

    /**
     * The calendar-validation address is the one in the protocol with a segment **after** the step:
     * `.calendar-{id}.{step}.year-{year}`. It is composed in exactly one place — the emitter's
     * `$classSuffix` — and nothing in the behavioural suites would notice if that concatenation were
     * dropped or reordered by a later edit: the frames would keep passing every other assertion while
     * `.calendar-IT.file-exists.year-2024` silently became `.calendar-IT.file-exists` and matched
     * zero cards, which is the exact failure mode #806 exists to end. Hence a literal.
     *
     * The target is asserted alongside it because the two say the same thing in the two vocabularies:
     * the year is in the selector for a v1 client and in `target.year` for a v2 one.
     */
    public function testACalendarFrameCarriesTheYearAfterTheStepAndInItsTarget(): void
    {
        $conn = self::stubConnection();
        $this->invoke(
            $this->newHealth(),
            'sendCalendarStepResult',
            [$conn, 'IT', 2024, Step::EXISTS, Status::PASS, 'The nationalcalendar of IT for the year 2024 exists']
        );

        $frame = json_decode($conn->sent[0]);
        self::assertSame(
            '.calendar-IT.file-exists.year-2024',
            $frame->classes,
            'the year segment rides after the step, and only the emitter puts it there'
        );
        self::assertEquals(
            (object) ['id' => 'IT', 'year' => 2024],
            $frame->target,
            'a calendar check is a calendar and a year; neither identifies it alone'
        );
    }

    /**
     * The test-run address is the other irregular one, and it is irregular in the opposite direction:
     * `.{test}.year-{year}.test-valid` puts its year segment **before** the step where a calendar
     * validation puts it after, and its step segment is `test-valid` where the shared table would say
     * `schema-valid`. Both facts are declared on {@see FrameFamily} rather than composed at the two
     * emission sites, and nothing else in the gate would notice if either were dropped: the sites sit
     * behind a resolved `cachedGet()` promise that no test drives.
     *
     * A literal, for the same reason the calendar one is: the failure mode is a class that still looks
     * plausible — `.AllSaintsUSA.test-valid.year-2024`, or `.AllSaintsUSA.year-2024.schema-valid` —
     * and matches zero cards.
     */
    public function testATestRunFrameCarriesTheYearBeforeTheStepAndNamesWhatItRan(): void
    {
        $conn = self::stubConnection();
        $this->invoke(
            $this->newHealth(),
            'sendTestResult',
            [$conn, 'AllSaintsUSA', 'US', 2024, Status::FAIL, 'There was an error decoding JSON data for the test AllSaintsUSA: Syntax error', null, 'run-a']
        );

        $frame = json_decode($conn->sent[0]);
        self::assertSame(
            '.AllSaintsUSA.year-2024.test-valid',
            $frame->classes,
            'the year segment rides before the step, and the step segment is test-valid, not schema-valid'
        );
        self::assertEquals(
            (object) ['id' => 'AllSaintsUSA', 'calendar' => 'US', 'year' => 2024],
            $frame->target,
            'a test run is a test, a calendar and a year; none of the three identifies it alone'
        );
        self::assertSame('validates', $frame->step, 'the wire step is the published word, whatever the legacy class says');
        self::assertSame('fail', $frame->status);
        self::assertSame('error', $frame->type);
        self::assertSame('run-a', $frame->runToken);
    }

    /**
     * `.test-valid` addresses the validity box; it does not claim an outcome. So a pass and a fail are
     * addressed identically and differ in `status` — which is the correct design and not the oddity
     * #806 reads it as: a class that encoded the outcome would force a client to know the result before
     * it could build the selector that finds the card to put the result in.
     */
    public function testTheValidityBoxIsAddressedTheSameWayForAPassAndAFail(): void
    {
        $conn   = self::stubConnection();
        $health = $this->newHealth();
        $this->invoke($health, 'sendTestResult', [$conn, 'AllSaintsUSA', 'US', 2024, Status::PASS, 'passed', null, null]);
        $this->invoke($health, 'sendTestResult', [$conn, 'AllSaintsUSA', 'US', 2024, Status::FAIL, 'failed', null, null]);

        $passed = json_decode($conn->sent[0]);
        $failed = json_decode($conn->sent[1]);

        self::assertSame('.AllSaintsUSA.year-2024.test-valid', $passed->classes);
        self::assertSame($passed->classes, $failed->classes, 'the address of the box is the same either way');
        self::assertSame(['success', 'pass'], [$passed->type, $passed->status]);
        self::assertSame(['error', 'fail'], [$failed->type, $failed->status]);
    }

    /**
     * A family lists only the steps it has. A test run is one named outcome, not a three-step pipeline,
     * so `exists` is refused for it exactly as `complete` is refused for a check — rather than quietly
     * borrowing the other family's `file-exists` and addressing a box that does not exist.
     */
    public function testAStepTheTestRunFamilyDoesNotHaveIsRefused(): void
    {
        $conn = self::stubConnection();

        $this->expectException(\LogicException::class);
        try {
            $this->invoke(
                $this->newHealth(),
                'sendStepResult',
                [$conn, 'AllSaintsUSA', null, Step::EXISTS, Status::PASS, 'text', null, null, 'year-2024', FrameFamily::TEST_RUN]
            );
        } finally {
            self::assertSame([], $conn->sent, 'nothing may be sent for a step the family cannot project');
        }
    }

    /**
     * The two emitters of a test-run frame agree, because there is only one grammar and one owner of
     * it. `Health::sendTestResult()` speaks for the two ways a run fails before the assertion is
     * reached; `LitTestRunner` speaks for the run that actually happened. A client that cannot tell
     * which produced a frame is exactly the point.
     *
     * This is the assertion that would have caught the duplication #806's review found: while
     * `LitTestRunner` held its own copy of `".$test.year-$year.test-valid"`, both sides passed every
     * test they had and nothing compared them.
     */
    public function testBothEmittersOfATestRunFrameAddressItIdentically(): void
    {
        Router::getApiPaths();

        $conn = self::stubConnection();
        $this->invoke(
            $this->newHealth(),
            'sendTestResult',
            [$conn, 'MaryMotherChurchTest', 'US', 2019, Status::FAIL, 'the calendar was not retrieved', null, null]
        );
        $fromHealth = json_decode($conn->sent[0]);

        $data                              = (object) [
            'settings' => (object) ['year' => 2019],
            'litcal'   => [(object) ['event_key' => 'MaryMotherChurch', 'date' => '2019-06-11T00:00:00+00:00']]
        ];
        $data->settings->national_calendar = 'US';
        $runner                            = new LitTestRunner('MaryMotherChurchTest', $data, Rite::ROMAN);
        $runner->runTest();
        $fromRunner = $runner->getMessage();

        self::assertSame('.MaryMotherChurchTest.year-2019.test-valid', $fromHealth->classes);
        self::assertSame($fromHealth->classes, $fromRunner->classes, 'one grammar, one owner, one address');
        self::assertEquals($fromHealth->target, $fromRunner->target, 'and one target shape for the same run');
        self::assertSame($fromHealth->step, $fromRunner->step);
        self::assertSame($fromHealth->status, $fromRunner->status);
    }

    /**
     * An empty qualifier is treated as absent rather than emitted as an empty segment: `.frag..step`
     * is a selector that matches nothing, which is the failure this projection exists to prevent. The
     * legacy code had the mirror of this hazard and no caller could produce it either, so this closes
     * a hole rather than fixing a regression — but the composer is now the only place that could ever
     * close it.
     */
    public function testAnEmptyQualifierIsTreatedAsAbsentRatherThanEmittedAsAnEmptySegment(): void
    {
        $conn = self::stubConnection();
        $this->invoke(
            $this->newHealth(),
            'sendStepResult',
            [$conn, 'temporale-roman', null, Step::EXISTS, Status::PASS, 'text', null, null, '']
        );
        $this->invoke(
            $this->newHealth(),
            'sendStepResult',
            [$conn, 'AllSaintsUSA', null, Step::VALIDATES, Status::PASS, 'text', null, null, '', FrameFamily::TEST_RUN]
        );

        self::assertSame('.temporale-roman.file-exists', json_decode($conn->sent[0])->classes);
        self::assertSame('.AllSaintsUSA.test-valid', json_decode($conn->sent[1])->classes);
    }

    /**
     * `responsetype` is legacy, so it keeps its legacy position: the four properties a decode-failure
     * frame has always led with, in the order it has always led with them, before any structured
     * field. Assigning it anywhere else would still decode to the same object, and would still be a
     * change to the wire format of a frame this migration promised not to touch.
     *
     * Absent — not null — when there is none, so a client cannot mistake "this frame is not about a
     * response format" for "the format was null".
     */
    public function testResponseTypeStaysInTheLegacyBlockAndIsAbsentWhenThereIsNone(): void
    {
        $conn = self::stubConnection();
        $this->invoke(
            $this->newHealth(),
            'sendCalendarStepResult',
            [$conn, 'IT', 2024, Step::PARSES, Status::FAIL, 'text', null, 'XML']
        );
        $this->invoke(
            $this->newHealth(),
            'sendCalendarStepResult',
            [$conn, 'IT', 2024, Step::PARSES, Status::PASS, 'text']
        );

        $withFormat = json_decode($conn->sent[0], true);
        self::assertIsArray($withFormat);
        self::assertSame(
            ['type', 'text', 'classes', 'responsetype'],
            array_slice(array_keys($withFormat), 0, 4),
            'the legacy block keeps its shape and its order; the structured fields follow it'
        );
        self::assertSame('XML', $withFormat['responsetype']);
        self::assertArrayNotHasKey('responsetype', json_decode($conn->sent[1], true));
    }

    /**
     * `retrieveXmlErrors()` returns the errors as a list, so the frame's `text` and its `details` are
     * built from the same values instead of one being recovered by re-splitting the other. The old
     * shape — join here, split there — looked safe because every message goes through
     * `htmlspecialchars()`, but the ` in file: …` suffix interpolates the URI raw, so a separator
     * arriving in a filename would have split one error into two.
     */
    public function testXmlErrorsComeBackAsAListSoTextAndDetailsCannotDrift(): void
    {
        $xml = '<a><b></a>';

        // Restored in a `finally`: libxml's error handling is process-global, so leaving it flipped
        // would silently change how every later test in the process sees malformed XML.
        $previousXmlErrorHandling = libxml_use_internal_errors(true);

        try {
            ( new \DOMDocument() )->loadXML($xml);
            $errors = libxml_get_errors();
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousXmlErrorHandling);
        }

        self::assertNotEmpty($errors, 'the fixture must actually produce libxml errors');

        $list = ( new \ReflectionMethod(Health::class, 'retrieveXmlErrors') )->invoke(null, $errors, explode("\n", $xml));

        self::assertIsArray($list);
        self::assertCount(count($errors), $list, 'one entry per error, not one joined string');
        self::assertContainsOnlyString($list);
        self::assertSame([], ( new \ReflectionMethod(Health::class, 'retrieveXmlErrors') )->invoke(null, [], []));
    }

    /**
     * Details are structured, and optional: a passing step has none and must not carry an empty
     * array that a client would have to distinguish from "no detail available".
     */
    public function testDetailsAreCarriedOnlyWhenThereAreAny(): void
    {
        $conn = self::stubConnection();
        $this->invoke(
            $this->newHealth(),
            'sendStepResult',
            [$conn, 'nation-roman-US-i18n', null, Step::PARSES, Status::FAIL, 'text', ['it.json: Syntax error'], null]
        );
        $this->invoke(
            $this->newHealth(),
            'sendStepResult',
            [$conn, 'nation-roman-US-i18n', null, Step::PARSES, Status::PASS, 'text', [], null]
        );

        self::assertSame(['it.json: Syntax error'], json_decode($conn->sent[0], true)['details']);
        self::assertArrayNotHasKey('details', json_decode($conn->sent[1], true));
    }

    /**
     * The `details` rule, at the one file-branch site that has something structured to say.
     *
     * A schema failure's text is the schema's own error line joined to the validator's message with
     * a newline — two things the emitter already holds, flattened into one string. `details` hands
     * the pieces back rather than making a client split prose it did not build. Sites with nothing
     * structured behind their text (an unreadable file, a missing schema) manufacture nothing, which
     * {@see HealthValidationDataErrorTest} pins from the other side.
     *
     * The validator's second line quotes an absolute path, so it is asserted structurally rather
     * than as a literal; the first line is `LitSchema`'s own and is pinned exactly.
     */
    public function testASchemaFailureCarriesThePartsItsTextFlattens(): void
    {
        Router::getApiPaths();

        $schema = ( new \ReflectionMethod(Health::class, 'retrieveSchemaForCategory') )->invoke(null, 'sourceDataCheck', 'proprium-de-tempore');
        self::assertIsString($schema);

        $conn       = self::stubConnection(4);
        $validation = (object) [
            'action'     => 'executeValidation',
            'category'   => 'sourceDataCheck',
            'validate'   => 'proprium-de-tempore',
            'sourceFile' => 'jsondata/x.json'
        ];

        // The buffer is closed in a `finally`: an `ob_start()` left open by a throw here does not fail
        // this test, it fails an unrelated later one in a way that looks nothing like its cause.
        ob_start();

        try {
            $this->invoke(
                $this->newHealth(),
                'processValidationData',
                ['{"litcal":"nope"}', $conn, $validation, 'jsondata/x.json', $schema, 'proprium-de-tempore', 'run-token-4', null]
            );
        } finally {
            ob_end_clean();
        }

        self::assertCount(3, $conn->sent);
        $passed = json_decode($conn->sent[0], true);
        $failed = json_decode($conn->sent[2], true);
        self::assertIsArray($passed);
        self::assertIsArray($failed);

        self::assertArrayNotHasKey('details', $passed, 'a step that passed has no failures to detail');
        self::assertSame('error', $failed['type']);
        self::assertSame('.proprium-de-tempore.schema-valid', $failed['classes']);
        self::assertCount(2, $failed['details'], 'the schema error line and the validator message');
        self::assertSame('Schema validation error: Proprium de Tempore data not created / updated', $failed['details'][0]);
        self::assertSame(
            $failed['text'],
            implode(PHP_EOL, $failed['details']),
            'details must be exactly the pieces the text flattens — no more, and nothing invented'
        );
    }

    /**
     * `complete` is not a check and has no legacy class: projecting it would have to invent one,
     * and a frame classed `.<fragment>.` matches nothing. The terminal frame is composed by
     * {@see Health::sendComplete()}, which routes around this method rather than relaxing the
     * refusal (#821), so reaching this one with it is a programming error and says so.
     *
     * The refusal is general, not a `COMPLETE` special case: any step missing from
     * `FrameFamily::CLASS_FOR_STEP` is refused the same way, so a case added later cannot quietly ship an
     * unmatchable `.<fragment>.` — which PHPStan would not catch, the const being typed
     * `array<string, array<string, string>>` rather than as a shape.
     */
    public function testTheTerminalStepIsRefusedBecauseItHasNoLegacyClass(): void
    {
        $conn = self::stubConnection();

        $this->expectException(\LogicException::class);
        try {
            $this->invoke(
                $this->newHealth(),
                'sendStepResult',
                [$conn, 'temporale-roman', (object) ['id' => 'temporale:roman'], Step::COMPLETE, Status::PASS, 'text', null, null]
            );
        } finally {
            self::assertSame([], $conn->sent, 'nothing may be sent for a step that cannot be projected');
        }
    }

    /**
     * So the terminal frame is built without the projection, and comes out with neither of the two
     * fields the projection would have supplied.
     *
     * `classes` would have to be invented — see the refusal above — and `status` would be a claim the
     * frame is not making: it reports that the work finished, not that it passed. Asserted as
     * *absent* keys rather than as null values, since a client tells "not applicable" from "null"
     * the same way it does for `responsetype`.
     *
     * The legacy `type` is still `success`, because a v2 client is reading `step` while any generic
     * frame handling is reading `type`, and there is no new response type in this migration — since
     * UnitTestInterface PR #46 an unrecognised one is painted as a visible failed check.
     */
    public function testTheTerminalFrameIsBuiltWithoutTheProjectionAndCarriesNeitherClassesNorStatus(): void
    {
        $conn = self::stubConnection();
        $this->invoke(
            $this->newHealth(),
            'sendComplete',
            [$conn, (object) ['id' => 'temporale:roman'], 'run-a', 'req-alpha']
        );

        self::assertCount(1, $conn->sent);
        $frame = json_decode($conn->sent[0], true);
        self::assertIsArray($frame);
        self::assertSame('complete', $frame['step'], 'the published terminal word reaches the wire');
        self::assertSame('success', $frame['type']);
        self::assertArrayNotHasKey('classes', $frame, 'no legacy class exists for a step the legacy protocol never had');
        self::assertArrayNotHasKey('status', $frame, 'finishing is not an outcome');
        self::assertSame(['id' => 'temporale:roman'], $frame['target']);
        self::assertSame(['run-a', 'run-a', 'req-alpha'], [$frame['runToken'], $frame['runId'], $frame['requestId']]);
    }

    /**
     * The gate, at the emitter rather than at the twelve call sites — which is where it has to be, so
     * that a thirteenth site added later cannot forget it.
     *
     * This is the one place in #806 where the additive envelope is not enough: a new frame changes the
     * *stream*. `resources.js` sizes a phase as `checks * 3` and advances on `>=`, so a v1 client
     * reaches its threshold on the three real frames and the terminal frame then increments whichever
     * counter has become active — finishing the following phase early too, with nothing visibly
     * failing. {@see HealthTerminalFrameTest} asserts the same absence from the other end, through
     * whole requests; this asserts it of the emitter itself, where no arm can hide it.
     */
    public function testTheTerminalFrameIsGatedOnCorrelationAndSendsNothingWithoutIt(): void
    {
        $conn = self::stubConnection();
        $this->invoke($this->newHealth(), 'sendComplete', [$conn, (object) ['id' => 'temporale:roman'], 'run-a', null]);

        self::assertSame([], $conn->sent, 'a client that did not opt into correlation must not be sent a frame it cannot have asked for');
    }
}
